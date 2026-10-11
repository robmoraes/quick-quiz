# Feature: Tagged Theme Selection

Status: Approved, implemented, verified, and deployed to production on 2026-10-10. See the [deployment record](../../runbooks/tagged-theme-selection-release.md).

## Intent

Editors need to add themes such as Kubernetes to the Dev quiz site without
changing its frontend for each new theme. Players should choose among themes
appropriate to that site while retaining its existing visual identity.

The site selects its audience through an environment-configured theme tag.
This prepares content discovery for other audiences without introducing a
generic SPA or exposing a URL control that changes the Dev site's audience.

## Scope

In scope:

- theme tags in PostgreSQL, Manager Web, and the protected administrative API;
- publishing theme tags in `themes.json` and reading them in the Quiz API;
- a public endpoint listing active themes, optionally filtered by one tag;
- a theme selector in SPA Dev, scoped by runtime environment configuration;
- compatible existing clients, safe session boundaries, and automated checks.

Out of scope:

- changes to SPA DSLab or Ads API contracts and campaign management;
- publishing topic tags, filtering topics by tags, or mixing themes in a run;
- a generic SPA, theme creation by players, tag hierarchies, or global tag CRUD;
- automatic API reload, database reads by the Quiz API, or new infrastructure;
- production edits, deployment, and release tags in this local increment.

Dependencies: Manager, PostgreSQL, existing local/S3 publication, Quiz API,
Redis, and SPA Dev. Existing Manager authentication and secrets are reused.

Assumptions: PostgreSQL remains the authoring source of truth. The Quiz API
serves its startup snapshot. The Dev site's advertising retains its existing
`dev` theme independently of the selected quiz theme.

## Behavior

### R1. Theme tags and Manager editing

1. A theme has zero to twenty tags. Reuse the existing tag identities, with
   separate theme associations. A topic does not inherit its theme's tags, and
   topic associations do not make a theme match a filter.
2. Accept at most twenty strings, each at most fifty ASCII bytes including
   surrounding whitespace. Trim ASCII space, tab, CR, LF, form feed, and vertical
   tab; lowercase ASCII letters; require `[a-z0-9]+(-[a-z0-9]+)*` as the whole
   value. Deduplicate and return tags sorted in ascending ASCII order.
3. Create a tag identity when first assigned. Removing an association does not
   remove other theme/topic associations. Existing themes start without tags;
   migration does not infer an audience from a theme ID.
4. Add a labeled, comma-separated Tags field to the theme create/edit form and
   show tags in the theme list. Blank input clears tags. Validation failures
   preserve submitted values. Existing authentication and CSRF rules apply.
5. Theme deletion removes its associations through the existing guarded delete
   operation. Unused tag identities can remain. PostgreSQL backups include the
   new associations.

### R2. Administrative API compatibility

Extend existing theme CRUD with optional `tags`; retain paths, required fields,
authentication, and response envelopes.

| Operation | Tag behavior |
| --- | --- |
| Create with tags | Normalize and save the supplied set |
| Create without tags | Save an empty set |
| Replace with tags | Replace the complete set |
| Replace without tags | Preserve existing tags |
| Replace with `tags: []` | Clear the set |
| Theme reads, catalog, and mutation responses | Include canonical `tags`, including `[]`, in PostgreSQL mode |

`PUT` still requires its existing fields; this does not introduce a partial
update operation. Localization and other edits that omit tags preserve them.
Null, non-array input, non-string members, empty slugs, invalid characters, and
exceeded limits return HTTP 422 using `validation_failed`. Failed validation
does not change metadata, associations, revisions, or published content.

Legacy authoring rejects explicit tag writes with HTTP 409 and
`theme_tags_unavailable`, following the existing topic-tag precedent. It must
preserve any serialized theme tags when editing other fields, and may expose
them on reads. Older clients that omit tags continue to work.

### R3. Publication and import

1. Theme tags affect discovery and are published metadata. Save them atomically
   with theme metadata and use the existing catalog revision/publication flow.
   A tags-only theme change requires publication and API reload, unlike a
   tags-only topic change.
2. Published theme entries include canonical `tags`, including `[]`. Existing
   theme fields and all topic, localization, and question JSON formats remain
   unchanged. Topic tags stay exclusively in PostgreSQL.
3. Readers accept old `themes.json` entries without tags as an empty set.
   Explicit invalid tag data is a content validation failure, not a reason to
   silently expose a theme under a different audience.
4. Theme discovery and question catalogs use the same loaded API snapshot.
   Published changes become visible after the existing API reload procedure.
   Discovery never reads S3 or PostgreSQL per request.
5. Import/export round trips retain theme tags. Import without tags represents
   an empty set. On explicit import `--replace`, the source theme tags are
   authoritative, including clearing them when absent; dry-run comparison must
   reveal that change. Existing preservation of PostgreSQL-only topic tags on
   surviving topic identities remains intact.
6. Publication failure uses the existing pending/failed revision and recovery
   flow. Do not report newly saved tags as already loaded by the Quiz API.

### R4. Public discovery

1. Add `GET /api/themes`. It requires no theme header, session ID, or
   administrative token. It lists active themes from the loaded snapshot.
2. With no `tag` parameter, return all active themes, including untagged themes.
   With one valid `tag`, return themes containing that normalized tag exactly.
   Normalize with R1's single-tag rules. Substring matching is not supported.
3. Empty, repeated, malformed, or oversized `tag` parameters return HTTP 400
   with `invalid_tag` in the existing public error envelope. An unknown valid
   tag returns HTTP 200 with `{"themes":[]}`.
4. Return theme ID, name, description, weight, active status, tags, and optional
   creation timestamp. Sort by ascending weight, then ID. Never return inactive
   themes. Active themes with no playable topics may still be listed.
5. This endpoint is locale-neutral: tags and IDs are machine identifiers, and
   theme names use the existing metadata. Do not add theme localization here.
6. Existing endpoints, payloads, headers, run behavior, and session persistence
   remain compatible. `X-QuickQuiz-Theme` continues to identify one theme on
   existing quiz requests. No tag-based run endpoint is introduced.

### R5. SPA Dev site scope and selection

1. Configure one audience tag at container startup through `SPA_THEME_TAG`.
   The repository's Compose setting is `SPA_DEV_THEME_TAG`; the browser receives
   `themeTag` in runtime configuration. The default when absent is `dev`.
   Explicitly empty or invalid values are configuration errors. Do not interpret
   them as an instruction to show all themes.
2. Local development uses the same default. Changing the deployed tag requires
   applying configuration and restarting/recreating the SPA container, without
   rebuilding its image.
3. Fetch `/api/themes?tag=<configured-tag>` before loading the selected theme's
   catalog. Only returned themes are selectable. URL parameters, local storage,
   and player controls do not override the configured audience tag.
4. Initially select `dev` if present; otherwise select the first returned theme.
   Keep the selected theme visible. A single available theme does not need an
   interactive selector. No saved cross-page theme preference is introduced.
5. Preserve the Dev visual identity and responsive layout. Place theme selection
   before topic selection and provide translated labels, loading/error/empty
   messages, and keyboard-accessible controls.
6. Empty discovery disables quiz start and explains that no themes are available.
   Request failures provide retry. Neither case falls back to the unfiltered
   list or to a hardcoded theme that was not returned.
7. An active theme without playable topics shows the existing no-content state
   and allows choosing another theme while idle.
8. Existing `?topic=...` links continue to apply within the initially selected
   theme if that topic exists. Switching themes clears the old topic selection
   and URL topic. Do not introduce a theme/tag querystring override.

### R6. Session and request isolation

1. Allow theme changes only before a session starts or after its existing end/
   recovery flow has returned to a new-session state. Disable the selector
   while requests are pending and throughout an open session, including between
   its runs. Do not silently abandon a run when choosing a theme.
2. Bind each catalog, run, answer, state poll, result, solution, and reset request
   to its intended theme. A session's results never aggregate different themes.
   Existing Redis identity, exhaustion, and TTL rules remain unchanged.
3. On a permitted theme change, clear old topic/difficulty/availability/error
   state and load the new catalog. Ignore late responses from prior theme or
   locale requests. A solution response for an old result must not populate a
   new session.
4. Keep advertising associated with the site's existing `dev` theme. Only pass
   a topic filter to advertising when the selected quiz theme is `dev`; clear
   that filter otherwise. Quiz selection must not inadvertently change the
   advertising header through a shared HTTP client.
5. After a run finishes, if no questions remain available in the selected
   theme's session, close that session automatically and display its consolidated
   result directly. Include every completed run; require neither a return to
   topic selection nor an explicit end-session action. Keep the existing new-session
   action. If questions remain elsewhere in the theme, retain the normal run
   result and continuation flow. A failure to check availability must not be
   interpreted as exhaustion.

## Acceptance Examples

These examples guide automated tests, not a separate manual QA artifact set.
Use synthetic fixtures; transaction rollback or disposable fixtures clean up
database/storage effects. Requirement IDs provide implementation traceability.

| ID / requirements | Given / When | Expected outcome |
| --- | --- | --- |
| A1 / R1–R2 | Create Kubernetes with `[" Dev ", "cloud", "dev"]` | Stored and returned tags are `["cloud", "dev"]` |
| A2 / R2 | Replace its name with tags omitted; then replace with `[]` | First preserves tags; second clears them, retaining existing required fields |
| A3 / R1–R2 | Submit null, a number, an accented slug, a 51-byte string, or 21 entries | 422; no persistence or publication side effects; web form retains values |
| A4 / R2 | Unauthenticated admin request, invalid web CSRF, or legacy explicit tag write | Existing auth/CSRF rejection; legacy authenticated request returns 409 `theme_tags_unavailable` |
| A5 / R3 | Change only a theme tag; separately change only a topic tag | Theme change publishes and requires API reload; topic change performs no publication |
| A6 / R3 | Load an old themes file; then import a tagged file and export it | Old file yields empty tag sets; tagged round trip retains tags; replace preview reveals removals |
| A7 / R4 | Active `dev` and `kubernetes` tagged `dev`, active `literature` tagged `arts`, inactive `draft` tagged `dev`; request without tag or with `tag=DEV` | All-active request excludes only `draft`; filtered request returns only `dev` and `kubernetes` in weight/ID order |
| A8 / R4 | Query `tag=unknown`, `tag=`, `tag=dev&tag=arts`, or invalid syntax | Unknown is 200/empty; remaining cases are 400 `invalid_tag` |
| A9 / R4–R5 | Dev site configured as `dev`; visit with `?tag=arts&theme=literature` | Configured discovery remains `dev`; literature is not a selectable theme |
| A10 / R5 | Change startup tag on the same image, or supply an empty value | Valid new tag scopes discovery without a build; empty config fails visibly, never broadens scope |
| A11 / R5–R6 | Select Kubernetes while idle, then start a run | Only its topics appear; all quiz requests use Kubernetes; selector stays locked through the session; ads retain `dev` without a foreign topic filter |
| A12 / R6 | Old catalog/solution response arrives after theme/session context changes | Response is discarded; new content, counters, and results remain unchanged |
| A13 / R4–R5 | Filter matches nothing, discovery fails, or selected theme has no playable topics | Distinct empty/error/no-content states; no invalid quiz start or unfiltered fallback |
| A14 / R3–R4 | New tags are published while API is running, then API reloads | Old snapshot remains consistent until reload; new discovery and catalog agree afterward |
| A15 / R4 | Existing SPA DSLab requests catalog and runs with its current theme header | Existing payloads and behavior remain compatible without a DSLab release |
| A16 / R6 | The final run exhausts the selected theme's session | Session closes automatically and opens its consolidated result, preserving all runs and answers; the existing new-session action becomes available after reset |

## Data and Contracts

- [Public theme discovery](contracts/themes.openapi.yaml): implemented endpoint.
- [Manager tag additions](contracts/theme-tags.yaml): additive schema components.
- [Published theme index](contracts/themes.schema.json): backward-compatible
  optional field for readers; the new publisher always emits `tags`.
- Persistence adds theme/tag associations using existing tag identities. No
  quiz content or session data moves to a different datastore.
- Canonical contracts are updated in [Quiz API OpenAPI](../../openapi.yaml) and
  [Manager OpenAPI](../../openapi-manager-admin.yaml). The feature contracts
  describe implemented capabilities; deployment remains a separate step.

## Quality Attributes

- Security: Manager writes remain protected. Discovery exposes public metadata
  only. A frontend audience filter is navigation policy, not authorization;
  clients can still query other public themes directly.
- Performance: discovery uses only in-memory metadata, with zero per-request
  object-storage/database calls. Manager lists fetch tags without per-theme
  queries. No background content synchronization is added.
- Reliability: preserve snapshot consistency and discard stale async responses.
  Invalid configuration and unavailable content must not widen the site scope.
- Accessibility/localization: use labeled, keyboard-operable controls and the
  existing supported UI locales. Do not localize tag slugs or error codes.
- Privacy/observability: no new secrets or personal data; use existing request
  and publication diagnostics to distinguish invalid input, failed publication,
  an old loaded snapshot, and an empty filtered result.

## Rollout and Operations

Apply the additive migration and release Manager support. Deliberately assign
the `dev` tag to the current `dev` theme and any other intended themes; publish
and reload the Quiz API with discovery support before deploying SPA Dev.
Verify filtered discovery and existing clients before enabling the new UI.
Newly tagged themes then require publication and API reload, but no SPA build.

Rollback SPA Dev before rolling the API back to a version without discovery.
Keep the additive database tables. Older Manager publishers may omit tags, so
pause publication if Manager must be rolled back and restore a compatible
publisher before editing content. See [plan.md](plan.md) for sequencing.

## Review Decisions

The approved design uses one audience tag per site, default theme `dev` when
available, session-bound theme selection, and unchanged Dev advertising
ownership. These choices were approved before implementation. Deployment remains a
separate step. Generic SPA behavior, multi-tag
filters, and hot reload remain deferred.
