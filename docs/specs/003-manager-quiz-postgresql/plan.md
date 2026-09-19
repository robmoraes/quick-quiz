# Plan: PostgreSQL-backed Quiz Authoring

## Overview

Migrate Manager quiz authoring from JSON object storage to normalized
PostgreSQL tables while preserving the current Manager UI, protected
administration API, published JSON layout, and Quiz API in-memory read model.

The implementation will keep the legacy path selectable until production
import and comparison are accepted:

```text
Legacy rollout path
Manager -> QuizPackService -> local/S3 JSON

Target path
Manager -> PostgreSQL quiz service -> publication renderer -> local/S3 JSON
Quiz API -> published JSON -> in-memory snapshot
```

Normal target-path reads never call content storage. Content storage is used by
migration commands, publication, comparison, and rollback only.

## Architecture Boundaries

### Authoring contract

Introduce `QuizAuthoringService`, an application-facing interface covering the
operations currently consumed from `QuizPackService` by Manager controllers and
`QuizAdministrationService`:

- theme list, lookup, create, replace, and guarded deletion;
- topic list, lookup, localization, create, replace, and guarded deletion;
- question summary list, localized-set read, create, replace, and deletion;
- ID allocation, content statistics, locale parity, and validation reports;
- selected-theme and theme-scoped operation support.

The existing `QuizPackService` implements the interface as the legacy adapter.
A new `PostgresQuizAuthoringService` implements the same domain contract over
PostgreSQL. Controllers depend on the interface, keeping HTTP and templates
independent of the persistence provider.

Symfony selects the implementation from
`MANAGER_QUIZ_PERSISTENCE_PROVIDER=legacy|postgres`. The default remains
`legacy` until migration verification is complete. The selection is runtime
environment configuration and never requires rebuilding an image.

### Domain rules

Extract normalization and validation that is independent of persistence from
`QuizPackService` into small services or value objects. Shared rules include:

- safe identifiers;
- UTC timestamp normalization;
- supported locale validation;
- difficulty metadata and answer counts;
- question payload normalization;
- exact locale parity;
- question ID generation and validation.

Both persistence implementations and the importer use these rules. Controllers
must not duplicate them.

### PostgreSQL repository

Add a `QuizContentRepository` behind `PostgresQuizAuthoringService`. It owns SQL
queries, transactions, row mapping, aggregate counts, and revision state. Keep
business validation and HTTP concerns outside the repository.

Add a lazily initialized `ManagerDatabase` service around the existing
`DatabaseConnectionFactory` so one PDO connection and transaction boundary can
be shared by repositories and commands. Existing admin and AI-prompt
repositories do not need to migrate in this feature.

### Publication boundary

Add these components:

- `QuizProjectionRenderer`: maps a consistent PostgreSQL snapshot to the
  existing logical JSON keys and payloads;
- `QuizProjectionPublisher`: applies a rendered change set to `ContentStorage`,
  restoring the previous object values if a later operation fails;
- `QuizPublicationService`: serializes writes, tracks revisions and status, and
  coordinates database commits with synchronous publication;
- `QuizContentComparator`: compares logical source and rendered content without
  depending on formatting or JSON object key order.

`ContentStorage` remains responsible only for local/S3 object primitives. It
must not know the relational model.

## Relational Model

Use PostgreSQL-native constraints and `TIMESTAMPTZ` timestamps. SQL names may be
adjusted during implementation, but the following identities and relationships
must remain.

### `quiz_themes`

- `id TEXT PRIMARY KEY`;
- `name TEXT NOT NULL`;
- `description TEXT NOT NULL DEFAULT ''`;
- `weight INTEGER NOT NULL`;
- `active BOOLEAN NOT NULL`;
- `created_at TIMESTAMPTZ NOT NULL`;
- `updated_at TIMESTAMPTZ NOT NULL`.

Add checks for non-blank ID and name. Index `(active, weight, id)` for ordered
catalog reads.

### `quiz_topics`

- `theme_id TEXT NOT NULL REFERENCES quiz_themes(id) ON DELETE CASCADE`;
- `topic_key TEXT NOT NULL`;
- canonical `name`, `description`, `weight`, and `active` fields;
- `created_at` and `updated_at` as `TIMESTAMPTZ`;
- primary key `(theme_id, topic_key)`.

Index `(theme_id, active, weight, topic_key)`.

### `quiz_topic_translations`

- `theme_id`, `topic_key`, and `locale` as the primary key;
- localized `name` and `description`;
- `updated_at TIMESTAMPTZ NOT NULL`;
- composite foreign key to `quiz_topics` with cascading deletion.

### `quiz_questions`

- `theme_id`, `topic_key`, `difficulty`, and `question_id` as the primary key;
- `created_at` and `updated_at` as `TIMESTAMPTZ`;
- composite foreign key to `quiz_topics` with cascading deletion;
- check `difficulty BETWEEN 1 AND 4`.

Index `(theme_id, topic_key, difficulty, question_id)` supports ordered lists
and ID allocation.

### `quiz_question_translations`

- question composite identity plus `locale` as the primary key;
- `prompt TEXT NOT NULL`;
- `updated_at TIMESTAMPTZ NOT NULL`;
- composite foreign key to `quiz_questions` with cascading deletion;
- check that locale and prompt are non-blank.

### `quiz_answers`

- question composite identity, `locale`, `kind`, and `position` as the primary
  key;
- `answer_text TEXT NOT NULL`;
- composite foreign key to `quiz_question_translations` with cascading
  deletion;
- check `kind IN ('correct', 'wrong')`;
- check `position >= 0` and non-blank answer text.

Ordering by `kind, position` must reproduce the source arrays exactly.

### Revision and publication state

Add `quiz_catalog_state` as one singleton row containing `current_revision` and
`published_revision`. Add `quiz_publications` with:

- generated publication ID;
- database revision;
- optional affected theme;
- status `pending`, `published`, or `failed`;
- content checksum and object count when available;
- created, started, and finished timestamps;
- sanitized failure summary.

Every committed mutation increments `current_revision` and creates a pending
publication record. Successful publication advances `published_revision`.

## Versioned Database Migrations

Add ordered SQL migrations under `apps/manager/migrations/` and a small runner
using the existing PDO connection. The runner will:

1. take a PostgreSQL advisory lock;
2. create and read a `manager_schema_migrations` ledger;
3. apply pending files in lexical order, each in a transaction;
4. record migration name, checksum, and applied time;
5. reject a changed checksum for an already-applied migration;
6. release the lock on success or failure.

Expose the runner as `php bin/console manager:database:migrate`. The container
must not create quiz tables during HTTP requests. This scope does not require
converting the existing `admins` or `ai_prompts` initialization behavior.

The quiz schema is PostgreSQL-specific. Fast domain tests use fakes; repository
and migration behavior is verified against the Compose PostgreSQL service.

## Query Design

Use bounded aggregate queries rather than per-question loops:

- theme lists read only `quiz_themes`;
- topic lists join localized metadata and a grouped canonical-question count;
- catalog discovery obtains themes, topics, and counts in a bounded set of
  queries independent of question volume;
- question lists fetch only the selected theme, topic, locale, and optional
  difficulty;
- statistics use SQL aggregates grouped by locale, topic, and difficulty;
- full localized question reads fetch prompts and ordered answers for one
  question identity.

No read operation in the PostgreSQL implementation receives `ContentStorage`.
Tests use a storage spy to prove normal navigation does not access S3 methods.

## Transactions and Concurrency

All authoring mutations run inside a PostgreSQL transaction. Theme and topic
deletions check descendants before an explicit recursive cascade. Localized
question sets validate every locale before beginning writes and replace their
prompts and answers atomically.

Serialize quiz mutations and their synchronous publications with a dedicated
PostgreSQL session advisory lock:

1. acquire the mutation/publication lock;
2. validate and commit the database mutation, incrementing the revision;
3. open a repeatable-read snapshot while the lock still prevents another quiz
   writer;
4. render and publish only the affected objects and indexes;
5. mark the publication published or failed;
6. release the lock in a `finally` path.

Database reads remain available while publication runs. Unique constraints are
the final guard against duplicate natural keys and question IDs.

When publication fails, the database revision remains durable and visible in
the Manager as unpublished. The compensating object writer restores the prior
S3 projection. A later retry publishes the current database revision.

## Import and Comparison

Add Symfony commands:

- `manager:quiz:import --dry-run` validates source content and prints counts;
- `manager:quiz:import --apply` imports into empty quiz tables in one
  transaction;
- `manager:quiz:import --apply --replace` requires explicit replacement and
  replaces quiz rows transactionally;
- `manager:quiz:compare` compares the source objects with a database-rendered
  projection;
- `manager:quiz:publish [--theme=<id>]` publishes or retries the current
  database revision.

The importer reads through the configured `ContentStorage`, not direct file or
AWS SDK calls. It validates the complete source before opening the write
transaction. Natural-key upserts may be used internally, but conflicting
non-identical content fails unless `--replace` is present.

The report includes themes, topics, topic translations, questions, question
translations, correct answers, and wrong answers, broken down by theme, locale,
and difficulty where useful. It must never print prompts, answer text, secrets,
or database URLs.

Comparison canonicalizes decoded JSON and array order, then reports missing,
extra, and changed logical keys. It does not treat whitespace or JSON object
key order as a difference.

## Publication Behavior

Preserve the current object layout and encoding:

- `themes.json`;
- `<theme>/index.json`;
- `<theme>/<locale>/index.json`;
- `<theme>/<locale>/<topic>/<difficulty>/<question-id>.json`.

Render only affected objects for normal mutations:

- theme metadata changes publish `themes.json`;
- topic metadata changes publish the theme central and localized indexes;
- question changes publish or delete one file per supported locale;
- recursive deletion also removes descendant published objects;
- the migration and recovery command can render and publish a complete
  projection.

Before a multi-object change, capture the previous object state. On failure,
restore overwritten and deleted objects and remove newly created objects. S3
versioning remains a second recovery layer.

Add a deterministic logical checksum over sorted keys and canonical JSON. Store
it with successful publication records and expose it through status tooling.

## HTTP and UI Compatibility

Keep existing theme, topic, question, and catalog request payloads and resource
paths. Update `docs/openapi-manager-admin.yaml` for publication status:

- successful mutations include revision, status `published`, and
  `apiReloadRequired=true`;
- a committed database mutation whose publication fails returns HTTP 503 with
  code `publication_failed`, the revision, status `failed`, and
  `apiReloadRequired=false`;
- add protected `GET /api/admin/quiz/publication` for current versus published
  revision status;
- add protected `POST /api/admin/quiz/publication` to retry publication of the
  current revision.

The server-rendered Manager displays a persistent warning when
`current_revision != published_revision` and provides an authenticated retry
action. It must never report an unpublished revision as playable.

After successful publication, the operational instruction remains to restart
only the Quiz API. Quiz sessions remain in Redis.

## Configuration and Secrets

Add:

```text
MANAGER_QUIZ_PERSISTENCE_PROVIDER=legacy|postgres
```

Keep:

- `MANAGER_DATABASE_URL` and `MANAGER_DATABASE_URL__FILE` for PostgreSQL;
- `MANAGER_CONTENT_STORAGE_PROVIDER`, local path settings, and S3 settings for
  legacy import and publication;
- existing AWS credential `NAME`/`NAME__FILE` handling.

The Manager web container and SPAs receive no database or administrative API
credentials. Compose configuration keeps PostgreSQL health and dependency
checks. Migrations run as an explicit release step, not during container health
checks or HTTP startup.

## Automated Verification

No separate manual test-case documents are required. Implement verification in
the automated suite:

- unit tests for identifiers, payload normalization, difficulty rules, locale
  parity, projection rendering, checksums, and comparison;
- PostgreSQL integration tests for migrations, constraints, transactions,
  aggregate queries, cascades, revision state, and concurrent ID allocation;
- importer tests for dry run, idempotency, conflict, replacement, rollback, and
  aggregate parity;
- publisher tests for exact JSON compatibility, changed-key selection,
  compensation, retry, and unpublished status;
- contract tests for the existing CRUD endpoints and new publication endpoints;
- tests proving PostgreSQL read paths make zero `ContentStorage` calls;
- Compose-backed performance evidence for the current production-sized fixture;
- full Manager PHPUnit, OpenAPI parse, and Compose configuration checks.

Performance assertions in the unit suite should verify bounded query behavior,
not fragile wall-clock thresholds. The 500 ms target is checked in a controlled
Compose smoke benchmark and again after production rollout.

## Delivery Slices

1. Add the ADR, plan, tasks, schema migrations, and migration runner.
2. Add shared domain rules and PostgreSQL repository reads with integration
   tests.
3. Add importer, dry-run report, comparison, and a production-sized fixture.
4. Add PostgreSQL read implementation behind the legacy-default feature flag.
5. Add transactional mutations, revision tracking, renderer, and publisher.
6. Update the UI, administration API, OpenAPI, Compose, and runbooks.
7. Rehearse import and publication locally, then build and deploy without
   enabling PostgreSQL reads.
8. Back up production, migrate, import, compare, enable PostgreSQL, publish,
   restart the Quiz API, and verify latency and content counts.

Each slice keeps `main` releasable. The feature flag stays on `legacy` until all
required target-path behavior is present.

## Rollback

Application rollback selects `legacy`, deploys the prior Manager image, and
continues reading the retained S3 projection. New quiz tables and migration
ledger rows remain in PostgreSQL and are not destructively reversed.

Before production cutover, back up PostgreSQL and retain current S3 versions.
If a new projection is incorrect, restore the previous S3 object versions or
republish from the verified source, then restart only the Quiz API.
