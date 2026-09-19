# Feature: Manager Topic Tags

Status: Approved and implemented locally. Production rollout is pending.

## Intent

Editors and trusted administrative API clients need to attach reusable tags to
quiz topics. This first increment records and displays those classifications in
the Manager, providing a foundation for later discovery during study sessions.

Tags are Manager authoring metadata stored in PostgreSQL. They do not become
part of the published quiz content in this increment.

## Scope

In scope:

- PostgreSQL tag identities and their associations with topics;
- entering, viewing, replacing, and clearing tags in the existing Manager Web
  topic form and protected administrative topic CRUD;
- returning tags in Manager topic and catalog reads;
- compatible handling of existing clients and topic data;
- automated verification of persistence and publication isolation.

Out of scope:

- any change to published JSON paths, fields, or formats;
- changes to the player-facing Quiz API, Ads API, or either SPA;
- filtering, search, recommendations, automatic tagging, or quiz assembly by tags;
- a separate tag management screen or global tag CRUD/search endpoints;
- tag hierarchies, aliases, descriptions, colors, or localized tag names;
- new infrastructure, dependencies, environment variables, or secrets.

The affected API is the Manager-owned `/api/admin/quiz/*` API. PostgreSQL quiz
authoring is a prerequisite; the existing database and authentication are reused.

## Behavior

### Tag identity and lifecycle

1. A topic has zero to twenty tags. Tags form a set, not an ordered list.
2. Tags are shared across themes and locales. The same tag on two topics refers
   to the same classification; associations use the full theme/topic identity.
3. Input is an array of strings with at most twenty entries. Each supplied string
   is at most fifty characters, including surrounding whitespace. Trim ASCII
   whitespace, lowercase ASCII letters, then require a nonempty slug matching
   `[a-z0-9]+(-[a-z0-9]+)*`. Spaces inside a slug and accented characters are invalid.
4. Collapse duplicates after normalization. Return canonical tags sorted in
   ascending ASCII order. For example, `[" AWS ", "redes", "aws"]` becomes
   `["aws", "redes"]`.
5. New valid tag identities are created when assigned to a topic. Editors do not
   need a separate registration step.
6. Removing an association does not remove the tag from other topics. Deleting a
   topic or theme removes its associations through the existing guarded deletion
   flow. Unused tag identities may remain for reuse.
7. Existing topics start with an empty set. Tag changes persist across Manager
   container replacement and are included in PostgreSQL backups.

### Manager Web

1. Add a labeled Tags field to the existing topic create/edit form. A simple
   comma-separated input is sufficient, with a short example and the limits.
2. A blank field saves an empty set. Invalid entries produce validation feedback
   and preserve the submitted form values. Commas separate entries; they are not
   part of a tag.
3. Display saved tags on the topic form and in the existing topic catalog list.
4. Tags apply to the topic across locales. Localization forms and AI-assisted
   description/localization updates must preserve existing tags when they do
   not submit the field. Tag generation by AI is outside this feature.
5. Keep the current authenticated session and CSRF requirements.

### Administrative API

Extend the current topic contract with an optional `tags` array:

| Operation | Behavior |
| --- | --- |
| Create topic with `tags` | Normalize and persist the supplied set with the topic |
| Create topic without `tags` | Create the topic with no tags |
| Replace topic with `tags` | Replace its complete tag set |
| Replace topic without `tags` | Preserve its existing tags |
| Replace topic with `tags: []` | Remove all associations from that topic |
| Read topic, list topics, or read catalog | Include canonical `tags`, including `[]`, on PostgreSQL topic objects |

Keep existing paths, HTTP verbs, required topic fields, identifiers, and Bearer
security. This is not a new partial-update endpoint: a `PUT` still supplies its
existing required fields. Omitting tags is deliberately non-destructive for
older clients. Topic create/update responses also include the resulting tags.

Malformed tag values, null, non-array input, non-string entries, empty slugs,
and exceeded limits return HTTP 422 with the existing `validation_failed`
error envelope. No topic metadata, tag rows, or publication state changes on a
validation failure. Existing authentication and not-found precedence remains.

### Persistence and publication boundary

1. Save topic metadata, submitted localizations, and submitted tags in one
   PostgreSQL transaction. A database failure cannot commit only part of a save.
2. When tags are explicitly submitted and the normalized published topic fields
   and submitted translations are unchanged, treat the operation as tags-only.
   This includes clearing tags or submitting the same set again.
3. A tags-only save makes zero `ContentStorage` calls, creates no publication
   record, and changes neither catalog revision nor publication checksum/status.
   It succeeds even when S3 is unavailable.
4. A tags-only API response keeps the `topic`/`publication` envelope and returns
   `publication: {"apiReloadRequired": false, "reason": "topic_tags_only"}`.
   This describes this operation; it does not report other pending changes as
   published or clear the Manager's existing publication warning.
5. Creating a topic or changing published fields alongside tags retains the
   existing publication flow. JSON contains only its existing fields. If S3
   publication fails after the database commit, the normal `publication_failed`
   response applies and the committed tags remain readable in PostgreSQL.
6. Requests that omit tags retain their existing publication behavior. Other
   administrative mutations and publication endpoints retain their contracts.
7. Rendering, full publication, source comparison, and import idempotency exclude
   tags. A tags-only change leaves the JSON projection byte-for-byte identical.
8. Explicit replacement imports preserve tags for theme/topic identities present
   in both the old and replacement catalogs. New topics receive no tags; removed
   topics lose associations. An import failure restores the original data/tags.

### Legacy provider compatibility

Tags are available only with `MANAGER_QUIZ_PERSISTENCE_PROVIDER=postgres`.
Legacy reads retain their existing response shape, and the web form does not
offer tag editing in that mode. Supplying `tags` to a legacy topic mutation
returns HTTP 409 with code `topic_tags_unavailable`, without writing content.
Existing legacy requests that omit tags continue working. No tag is serialized
into local JSON as a fallback.

## Acceptance Examples

These scenarios guide automated tests, not separate manual test-case documents.

| Scenario | Expected result |
| --- | --- |
| Create with `AWS`, `redes`, and `aws` | One shared `aws` identity; topic reads return `["aws", "redes"]` |
| Read the topic in `en-US` and `pt-BR` | Same tags; localized topic text keeps its current behavior |
| An older client replaces the name and omits tags | Existing associations survive; normal name publication occurs |
| Submit only a changed tag set alongside unchanged required topic fields | Tags persist; zero storage calls; same projection, revisions, and checksum; no API reload required |
| Save tags while an earlier content revision has failed publication | Tag save succeeds; the previous failed status/warning remains unchanged |
| Submit an invalid tag together with a new description | HTTP 422; neither the description nor tags change |
| Clear tags on one of two topics sharing `aws` | Only that topic's associations are removed |
| Delete a tagged topic after the normal deletion guard succeeds | Its associations disappear; unrelated topics and tags remain |
| Replace-import a catalog containing an already tagged topic | Tags survive for that same theme/topic identity |
| Submit tags to the legacy provider | HTTP 409; no JSON write or silent data loss |

## Data and Contracts

PostgreSQL owns the shared tag identities and the many-to-many topic associations.
The [technical plan](plan.md) defines the schema and transaction boundaries.

The [OpenAPI schema excerpt](contracts/topic-tags.yaml) defines the additional
field and tags-only publication response, integrated into
[Manager OpenAPI](../../openapi-manager-admin.yaml) version 0.3.0 alongside the
new 409 response. `docs/openapi.yaml` remains unchanged.

## Quality Attributes

- Bound tag reads by catalog/theme queries, avoiding a query per topic and all
  S3 access. Preserve the current bounded question-count queries.
- Normalize and validate in shared business logic; use SQL parameters, database
  uniqueness constraints, and transactions for concurrent assignment.
- Escape tag text in templates, associate validation feedback with the input,
  and support keyboard-only editing. Never render submitted text as HTML.
- Reuse current auth, CSRF, database secrets, and sanitized error handling.
- Verify tag persistence independently from JSON publication and player state.

## Rollout and Operations

Apply an additive versioned migration, then release only the Manager images.
Existing topics require no content import or S3 rewrite. No Quiz API restart is
needed for the tag feature or tags-only edits; normal playable-content changes
retain their current publication/reload behavior.

Back up PostgreSQL before deployment. Application rollback keeps the new tables
and associations intact but removes tag editing from the older Manager. JSON
alone cannot recover tags. Avoid replacement imports with a pre-tags Manager,
whose deletion/reinsertion behavior does not preserve these associations.
