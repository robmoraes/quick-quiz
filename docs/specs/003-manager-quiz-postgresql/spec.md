# Feature: PostgreSQL-backed Quiz Authoring

## Intent

The Manager currently treats the published JSON objects in content storage as
its authoring database. A normal catalog request repeatedly lists S3 prefixes
and downloads question files to calculate counts and validate content. With the
current production catalog, read-only navigation can take 11 to 24 seconds and
the cost grows with the number of question files.

The Manager is an authoring application and needs queryable, transactional
persistence. Editors and trusted API clients must be able to browse and modify
themes, topics, localized questions, and answers without scanning the
publication objects.

The desired architecture is:

```text
Manager -> PostgreSQL -> JSON publication -> S3 -> Quiz API memory
```

PostgreSQL becomes the source of truth for authored quiz content. S3 remains
the publication boundary consumed by the Quiz API. The Quiz API does not gain
a PostgreSQL dependency.

## Scope

In scope:

- normalized PostgreSQL persistence for themes, topics, localized topic
  metadata, questions, localized prompts, and ordered answers;
- transactional Manager CRUD through both the web interface and the protected
  administration API;
- an idempotent importer for the current local or S3 JSON layout;
- generation and publication of the existing JSON layout from PostgreSQL;
- preservation of locale parity, question IDs, difficulty rules, active flags,
  weights, and answer ordering;
- staged cutover, verification, rollback, and publication observability;
- removal of S3 reads from normal Manager navigation after cutover.

Out of scope:

- making the Quiz API query PostgreSQL;
- moving player sessions, Manager sessions, users, AI prompts, or audit data;
- changing the player-facing Quiz API contract;
- changing the meaning of themes, topics, locales, difficulties, questions, or
  answers;
- adding a draft review workflow, approvals, scheduling, or multiple published
  release channels;
- replacing S3 as the published-content store;
- consolidating all published files into a new bundle format;
- semantic validation of question or translation quality.

Assumptions:

- PostgreSQL 17 remains available through `MANAGER_DATABASE_URL`, with the
  existing `MANAGER_DATABASE_URL__FILE` secret convention supported by the
  runtime;
- `FALLBACK_LOCALE` and `SUPPORTED_LOCALES` remain environment-owned
  configuration;
- difficulties remain the integers 1 through 4 with the current answer-count
  rules;
- the current S3 bucket, prefix, IAM permissions, and versioning remain
  available for publication and rollback;
- the existing JSON files are authoritative only until the production import
  and cutover are accepted.

## Behavior

### Source of truth and read paths

1. After cutover, PostgreSQL is the only authoring source of truth for themes,
   topics, questions, prompts, and answers.
2. Manager pages and `GET /api/admin/quiz/*` endpoints must query PostgreSQL and
   must not list or read S3 objects during ordinary navigation.
3. Counts by theme, topic, locale, and difficulty must be calculated by SQL,
   without opening published question JSON files.
4. S3 content is a derived publication projection. Changes made directly to S3
   after cutover must not silently alter the Manager database.
5. The current Manager UI and administrative API resource identifiers and CRUD
   semantics must remain compatible unless this specification explicitly
   changes them.

### Themes and topics

1. A theme has a stable string ID, name, description, weight, creation time,
   active flag, and update time.
2. Theme IDs are globally unique and retain the current safe-identifier rules.
3. A topic belongs to exactly one theme and has a stable key, canonical name,
   canonical description, weight, active flag, creation time, and update time.
4. A topic key is unique within its theme. The same key may exist in a different
   theme.
5. Localized topic name and description rows are keyed by theme, topic, and
   locale.
6. Deleting a theme or topic with descendants remains guarded. Recursive
   deletion must be explicit and must complete in one database transaction.

### Questions and answers

1. A question belongs to one theme, one topic, and one numeric difficulty.
2. A question is uniquely identified by theme ID, topic key, difficulty, and
   question ID.
3. Existing question IDs and the
   `<topic>-<difficulty>-<sequence>` allocation convention must be preserved.
4. Each question has exactly one localized prompt for every supported locale.
5. Each localized question has ordered correct answers and ordered wrong
   answers. Answer order must survive database reads and JSON publication.
6. Answers must record locale, kind (`correct` or `wrong`), zero-based position,
   and non-empty text.
7. Every locale for a question must have the same number of correct and wrong
   answers as the fallback locale.
8. Difficulty-specific option and wrong-answer counts remain authoritative.
9. A create or replace operation for a localized question set must validate all
   supported locales and answers before changing persistent data.
10. A question-set mutation must commit all locale prompts and answers in one
    transaction or change nothing.

### Mutation and publication

1. Successful Manager mutations must update PostgreSQL transactionally and
   regenerate the affected published JSON projection before reporting the
   content as published.
2. Publication must preserve the current paths and payloads:
   `themes.json`, `<theme>/index.json`, localized topic indexes, and localized
   question files.
3. Published question files continue to contain only `prompt`,
   `correctOptions`, and `wrongOptions`.
4. Publication must render from a consistent database snapshot so files from
   different database revisions cannot be mixed in one operation.
5. If publication fails, the previously valid S3 projection must remain
   recoverable and the Manager must record that the database revision is not
   published. A response must not claim that the Quiz API can reload the failed
   revision.
6. Retrying publication must be safe and must not duplicate database rows or
   alter question IDs.
7. A successful publication response continues to state that the Quiz API must
   reload before the new content becomes playable.
8. Only the publication component and migration tooling may write quiz-content
   objects to S3 after cutover.

### Import and cutover

1. The importer must accept the existing local or S3 content layout and must
   not modify the source objects.
2. Before writing, the importer must validate identifiers, metadata, JSON
   shape, supported locales, locale parity, difficulties, answer counts, and
   duplicate natural keys.
3. A validation failure must stop the import and identify the offending logical
   path without writing a partial catalog.
4. The production import must run as one logical migration. Failure must roll
   back inserted quiz content.
5. Re-running the importer with identical source content must be idempotent and
   must not create duplicate rows.
6. A conflict between existing database content and source content must fail
   closed unless an explicit operator-selected replacement mode is used.
7. The importer must produce a comparison report with counts by theme, topic,
   locale, difficulty, question, correct answer, and wrong answer.
8. Cutover must be controlled by environment configuration so the new Manager
   image can be deployed, imported, verified, and switched without rebuilding
   the image.
9. The final pre-cutover verification must regenerate the JSON projection and
   compare its logical content with the source catalog.
10. After cutover, operators must restart only the Quiz API after a successful
    publication, preserving quiz sessions stored in Redis.

## Acceptance Examples

### Browse a populated catalog without S3 reads

Given the production catalog has been imported into PostgreSQL,
when an authenticated editor opens the theme, catalog, or question list,
then the Manager obtains metadata and counts from PostgreSQL,
and no S3 `ListObjects`, `HeadObject`, or `GetObject` operation is executed.

### Import the current catalog without losing structure

Given a valid source containing themes `dev` and `dslab`, all supported
locales, and their current questions,
when the operator runs the import,
then each source theme, topic, question, prompt, correct answer, and wrong
answer has one corresponding database record,
and the comparison report shows equal source and target counts.

### Preserve localized answer order

Given one question has ordered correct and wrong options in `en-US` and
`pt-BR`,
when it is imported and published again,
then every generated JSON array contains the same values in the same order as
the source.

### Reject an incomplete localized mutation

Given `en-US` and `pt-BR` are supported,
when an API client replaces a question but omits `en-US`,
then the Manager rejects the request,
and no prompt or answer row for that question changes.

### Publish a database mutation for the Quiz API

Given an editor commits a valid question mutation,
when publication succeeds,
then the S3 projection contains the new JSON content,
the response reports that an API reload is required,
and restarting the Quiz API makes the question available without querying
PostgreSQL.

### Recover from publication failure

Given the current S3 projection is valid,
when a database mutation commits but S3 publication fails,
then the failure is visible to the editor and operator,
the revision is marked unpublished,
the previous published projection remains recoverable,
and a retry can publish the committed revision safely.

### Roll back the Manager during cutover

Given the database import completed but the PostgreSQL-backed Manager cannot be
accepted,
when operators restore the previous Manager image and legacy storage setting,
then it can read the unchanged S3 content,
and no Quiz API or player-session rollback is required.

## Data and Contracts

The logical relational model must provide these tables or equivalent normalized
relations:

| Relation | Identity | Required content |
| --- | --- | --- |
| `quiz_themes` | `id` | name, description, weight, active, created and updated timestamps |
| `quiz_topics` | `theme_id`, `key` | canonical name and description, weight, active, created and updated timestamps |
| `quiz_topic_translations` | `theme_id`, `topic_key`, `locale` | localized name and description |
| `quiz_questions` | `theme_id`, `topic_key`, `difficulty`, `id` | creation and update timestamps |
| `quiz_question_translations` | question identity, `locale` | prompt |
| `quiz_answers` | question identity, `locale`, `kind`, `position` | answer text |
| `quiz_publications` | publication ID | revision, status, affected theme, timestamps, checksum, and failure summary |

Required constraints:

- foreign keys prevent orphan topics, translations, questions, and answers;
- difficulty is restricted to 1 through 4;
- locale, identifier, prompt, and answer text values cannot be blank;
- answer kind is restricted to `correct` or `wrong`;
- answer position is non-negative and unique within question, locale, and kind;
- indexes support theme/topic ordering, question listing by locale and
  difficulty, aggregate counts, and publication status lookup;
- cascading deletes are executed only behind the existing explicit recursive
  deletion guard.

The protected administration API remains defined by
`docs/openapi-manager-admin.yaml`. Implementation must update that contract if
publication status or errors add response fields, while preserving existing
resource paths and request payloads.

## Quality Attributes

Performance:

- catalog and topic-list query counts must remain bounded as the number of
  questions grows;
- normal catalog navigation must make zero S3 calls;
- with the current production-sized dataset, theme, catalog, topic, and
  question-list reads should complete within 500 ms of server processing time
  under a single-user workload;
- publishing may scale with content size, but must expose its duration and
  object counts separately from navigation latency.

Reliability:

- database schema changes use versioned migrations and never run ad hoc table
  creation during a request;
- multi-row content writes are transactional;
- imports and publication retries are idempotent;
- S3 versioning remains an additional recovery mechanism;
- PostgreSQL backup and restore must include quiz content before production
  cutover.

Security:

- database credentials remain environment-provided secrets and support the
  `NAME__FILE` convention;
- question content and credentials must not appear in routine logs;
- existing Manager authentication, CSRF protection, administrative Bearer
  token, and S3 IAM boundaries remain in force;
- neither Manager web clients nor SPA containers receive database credentials.

Observability:

- log database migration and import outcomes with counts, duration, and a
  correlation ID;
- record publication revision, status, duration, object counts, and sanitized
  errors;
- expose enough status to distinguish database failure, validation failure,
  unpublished changes, and S3 publication failure;
- never log database passwords, Bearer tokens, prompts, or answer contents.

## Rollout and Operations

1. Create and review an ADR confirming PostgreSQL as the Manager authoring
   source and S3 as the Quiz API publication boundary.
2. Back up the Manager PostgreSQL volume and retain the current versioned S3
   objects.
3. Apply versioned schema migrations.
4. Deploy the compatible Manager image with legacy reads still selected.
5. Run validation and a dry-run import report.
6. Import into PostgreSQL and compare source and target aggregates.
7. Generate a publication projection from PostgreSQL and compare it with the
   current logical JSON content.
8. Switch Manager reads and writes to PostgreSQL through environment
   configuration.
9. Complete a CRUD and publication smoke test, then restart only the Quiz API.
10. Monitor navigation latency, database errors, and publication failures.

Rollback restores the previous Manager image and legacy storage selection. The
source S3 content must remain unchanged until PostgreSQL cutover and publication
have been accepted. Database migrations must not be destructively reversed
during an application rollback; the imported tables can remain unused.
