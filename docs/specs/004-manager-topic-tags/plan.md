# Plan: Manager Topic Tags

## Architecture

Extend the current PostgreSQL authoring path inside `apps/manager`. Reuse
`ManagerDatabase`, `QuizCatalogLock`, existing authentication, and the versioned
migration runner. This refines the existing PostgreSQL/publication boundary in
[ADR 002](../../architecture/adr/002-postgresql-quiz-authoring-s3-publication.md);
no new service, datastore, dependency, or player API integration is introduced.

Keep tag normalization and set semantics in a small shared domain/service
component. Controllers translate HTTP/form input; repositories own SQL. The
legacy provider must reject supplied tags before calling content storage.

## PostgreSQL Model

Add the next migration, `0002_topic_tags.sql`, without editing migration 0001.

| Relation | Columns and constraints |
| --- | --- |
| `quiz_tags` | `slug TEXT PRIMARY KEY`; check canonical slug syntax and length 1–50 |
| `quiz_topic_tags` | `theme_id`, `topic_key`, `tag_slug`, all non-null; primary key over all three |

Add a composite foreign key from the association to
`quiz_topics(theme_id, topic_key) ON DELETE CASCADE`, and a foreign key from
`tag_slug` to `quiz_tags(slug)`. Index `tag_slug` for referential operations.
No global tag deletion or automatic pruning is part of this increment.

Use `INSERT ... ON CONFLICT DO NOTHING` for shared identities. Validate the
complete input before creating rows. Replace associations transactionally; the
primary key prevents duplicate assignment. Do not store an additional array,
JSON column, or localized tag copy on a topic.

## Read Paths

Extend PostgreSQL topic read models with canonical `tags`. Load associations in
one grouped query per theme/catalog batch or a grouped join that does not
multiply question counts. Sort tags explicitly in ASCII order.

Propagate the field through `topic()`, `topicViews()`, topic lists, catalog
responses, and topic mutation responses. Preserve locale-specific topic names
and descriptions. Avoid leaking tags from authoring read models into exports:
`QuizProjectionRenderer` must continue selecting the existing published columns
explicitly; audit `readCatalog()` and any other catalog serialization paths.

The legacy implementation keeps its current read payloads. UI capability checks
use the existing persistence-provider boundary, without introducing a new flag.

## Write and Publication Paths

The current `QuizContentWriter::saveTopicSet()` always advances a publication
revision, and `QuizPublicationService::mutate()` requires that advancement.
Tags-only writes therefore need an explicit database-only path; passing a dummy
revision or invoking the publisher with no changed objects is insufficient.

Within the existing shared catalog lock:

1. Validate topic fields, translations, and the optional tag set.
2. Read current metadata and normalize the intended existing fields exactly as
   the current writer does, including timestamp/default behavior.
3. When tags are supplied and published metadata is unchanged, replace tags in
   a database transaction with the normal topic existence checks. Retain all
   publication state and return an explicit tags-only mutation result.
4. For creation or a combined published-field/tag change, write metadata,
   translations, and tags in the same existing mutation transaction, advance
   the publication revision once, then run the existing publication flow.
5. When tags are omitted, preserve associations and existing save/publication
   behavior, including localization and AI-assisted save paths.

Apply the same lock to tags-only mutation, normal content mutation, import, and
guarded deletion so checking the current topic and saving its set cannot race a
concurrent deletion/replacement. Concurrent set replacements follow the existing
serialized last-writer behavior; they must never commit a partial set or affect
another theme's topic with the same key.

Return an explicit save outcome for the web/admin response layer. Do not infer
whether this request requires publication from global `publicationInfo()`: a
previous successful or failed publication does not describe a tags-only save.
Keep normal mutation responses intact; only the opt-in tags-only outcome uses
`apiReloadRequired=false` and `reason=topic_tags_only`. It leaves any existing
unpublished-content warning visible and does not invoke a retry.

## Import and Projection Safety

`QuizCatalogImporter` currently deletes themes before a replacement import,
which would cascade-delete tag associations. Under its existing transaction and
catalog lock, retain those associations before replacement and reattach only
identities whose theme/topic survives. Dictionary rows remain reusable. If
replacement fails, the transaction restores content and associations together.

Dry runs, identical re-imports, source checksums, and comparison reports remain
based on the current JSON projection. A tags-only difference must never trigger
replacement. Test the actual renderer and publisher with tagged topics; neither
central/localized indexes nor question files may gain a tag field.

## Web and Administrative Contract

Affected web files include `CatalogController` and the existing
`catalog/form.html.twig` and `catalog/index.html.twig` templates. Parse the
comma-separated web field into the same array validated for the API. Preserve
submitted tags when form validation fails or a description is suggested by AI;
localization-only saves must not send a stale set back to the writer.

Extend existing Manager OpenAPI topic input/read schemas using the proposed
[schemas](contracts/topic-tags.yaml). Keep required fields and verbs as they
are. Add a `oneOf` alternative for the `TopicMutation.publication` field only:
existing `Publication` or `TopicTagsOnlyPublication`. Other resource mutation
schemas and the publication-status endpoint remain unchanged.

Represent missing input distinctly from an explicit empty array. Map domain
validation to the current `validation_failed` 422 envelope. Map attempts to set
tags in legacy mode to `topic_tags_unavailable` 409. Use the existing protected
routes; add no global tag API in this slice.

## Automated Verification

Use PHPUnit and the existing local Compose PostgreSQL service. No separate
manual test-case artifacts are needed.

- Unit checks cover normalization, duplicate removal, ordering, 20-entry and
  50-character boundaries, invalid types, and missing versus empty input.
- PostgreSQL tests cover additive migration, identity sharing, transactional
  replacement, cascades, rollback, concurrency, and restart/readback persistence.
- Contract tests cover old clients, both locales, all topic read surfaces, mixed
  saves, tags-only responses, 401/409/422 behavior, and unchanged unrelated CRUD.
- Compiled-kernel tests exercise the web save and administrative paths with the
  actual container wiring, including validation redisplay and CSRF rejection.
- Storage spies and renderer fixtures prove zero storage calls and exact JSON,
  checksum, revision, and publication-record stability for tags-only writes,
  including when an unrelated publication has already failed.
- Import tests cover idempotency and preserving associations during replacement;
  bounded-query checks cover catalog growth without per-topic tag queries.

Run the complete Manager suite with PostgreSQL and parse the updated Manager
OpenAPI. No Go/SPA implementation or build changes belong to this feature.

## Delivery and Rollback

The present branch initially contains specification artifacts only. After the
spec is accepted, implement the ordered [tasks](tasks.md), update Manager
OpenAPI/documentation, and increment the Manager version when preparing release.

For local use, select the existing PostgreSQL quiz provider. For production,
back up the database, apply migration 0002 explicitly, then deploy the Manager
FPM/web release and verify tags through web and administrative routes. Do not
import the content catalog or restart player services for this migration.

Rollback restores the prior Manager images while retaining the additive schema.
Existing metadata edits using that version preserve topic rows and therefore
tag associations; a replacement import with a pre-tags version is not safe for
tags. Document that PostgreSQL backup/restore now includes metadata absent from
S3. Clarify in ADR 002 that publication revisions track the playable projection,
while Manager-only tags have no publication revision.
