# Tasks: PostgreSQL-backed Quiz Authoring

## Documentation and Foundation

1. [x] Record and link the persistence decision.
   - Add ADR 002 for PostgreSQL authoring and S3 publication.
   - Link the spec, plan, ADR 001, and Manager administration documentation.
   - Validation: Markdown links resolve and `git diff --check` passes.

2. [x] Add versioned PostgreSQL migrations.
   - Add the migration ledger and the quiz theme, topic, translation, question,
     answer, catalog-state, and publication tables.
   - Include primary keys, foreign keys, checks, cascades, and query indexes.
   - Add `manager:database:migrate` with advisory locking and checksum checks.
   - Validation: an empty PostgreSQL database migrates successfully; a second
     run is a no-op; a modified applied migration is rejected.

3. [x] Add shared database and domain foundations.
   - Add a lazy shared `ManagerDatabase` connection/transaction service.
   - Extract persistence-independent identifier, locale, difficulty, payload,
     timestamp, and question-ID rules from `QuizPackService`.
   - Keep existing legacy behavior covered while extracting rules.
   - Validation: focused domain tests and the existing Manager suite pass.

## PostgreSQL Persistence

4. [x] Implement PostgreSQL theme and topic reads.
   - Add repository queries for ordered themes, canonical topics, localized
     topic metadata, and grouped canonical question counts.
   - Ensure query count is bounded independently of question count.
   - Validation: PostgreSQL integration tests cover empty and populated data,
     ordering, localization fallback, active flags, and aggregates.

5. [x] Implement PostgreSQL question reads and statistics.
   - List summaries by theme, topic, locale, and optional difficulty.
   - Read complete localized question sets with ordered correct and wrong
     answers.
   - Produce content statistics with grouped SQL queries.
   - Validation: integration tests cover every difficulty, missing content,
     locale parity, answer order, and current statistics fields.

6. [x] Implement transactional PostgreSQL mutations.
   - Add theme and topic create, replace, and guarded recursive deletion.
   - Add localized question-set create, replace, and deletion.
   - Allocate automatic question IDs safely under concurrent requests.
   - Increment catalog revision and create pending publication state in the
     same transaction.
   - Validation: integration tests cover conflicts, validation rollback,
     cascades, recursive guards, concurrent allocation, and no partial locale
     writes.

## Import and Projection

7. [x] Implement the legacy-content importer and dry-run report.
   - Read through `ContentStorage` and validate the complete source before any
     database write.
   - Map themes, topics, translations, questions, prompts, and ordered answers.
   - Add `manager:quiz:import --dry-run`, `--apply`, and explicit `--replace`.
   - Validation: automated fixture tests cover valid import, malformed paths,
     invalid JSON, duplicate keys, unsupported locales, and answer-count drift.

8. [x] Make imports idempotent and comparable.
   - Fail closed on non-identical conflicts without `--replace`.
   - Roll back the complete import on any write failure.
   - Produce sanitized aggregate reports without content or credentials.
   - Validation: importing identical content twice creates no duplicates;
     conflict and injected-failure tests leave the prior database unchanged.

9. [x] Implement deterministic JSON projection rendering.
   - Render `themes.json`, central and localized topic indexes, and localized
     question files from repository snapshots.
   - Preserve existing paths, fields, array ordering, timestamps, and JSON
     semantics.
   - Add canonical logical checksums and `manager:quiz:compare`.
   - Validation: round-trip fixtures compare logically equal to current JSON;
     missing, extra, and changed objects are reported precisely.

10. [x] Implement compensated publication and retry.
    - Publish only affected objects for normal mutations and support full
      publication for migration and recovery.
    - Capture previous object state and compensate partial writes or deletes.
    - Track pending, published, and failed status with revision, checksum,
      duration, object count, and sanitized failure summary.
    - Add `manager:quiz:publish` for full publication and retry.
    - Validation: storage-failure tests prove restoration, durable unpublished
      state, idempotent retry, and correct published revision advancement.

## Application Integration

11. [x] Add the persistence-provider boundary.
    - Define `QuizAuthoringService` from the operations consumed by controllers
      and `QuizAdministrationService`.
    - Make the existing `QuizPackService` the legacy implementation.
    - Add `PostgresQuizAuthoringService` over repository and publication
      services.
    - Select with `MANAGER_QUIZ_PERSISTENCE_PROVIDER=legacy|postgres`, defaulting
      to `legacy` until cutover.
    - Validation: the same contract suite passes against legacy and PostgreSQL
      implementations where behavior is shared.

12. [x] Switch Manager read paths to the authoring contract.
    - Update theme, catalog, question, stats, and administration services to
      depend on the interface.
    - Keep route parameters, templates, filters, sorting, and response shapes.
    - Validation: controller tests cover both provider selections, and a storage
      spy proves PostgreSQL reads make zero `ContentStorage` calls.

13. [x] Switch Manager mutations to transactional PostgreSQL plus publication.
    - Route all UI and administrative CRUD through the authoring contract.
    - Serialize mutation/publication, preserve CSRF and auth checks, and surface
      publication status.
    - Return a clear failure when the database committed but publication did
      not, without claiming the revision is playable.
    - Validation: controller and service tests cover success, database failure,
      S3 failure, unpublished state, and retry.

14. [x] Add publication status and retry interfaces.
    - Add protected `GET` and `POST /api/admin/quiz/publication` endpoints.
    - Add a Manager warning and authenticated retry action for unpublished
      revisions.
    - Update `docs/openapi-manager-admin.yaml` and usage documentation.
    - Validation: authentication, status, retry, error-shape, and OpenAPI parse
      tests pass.

## Configuration, Performance, and Operations

15. [x] Update Compose and runtime configuration.
    - Add the persistence-provider variable to local and cloud Compose.
    - Preserve database and AWS `NAME`/`NAME__FILE` secret handling.
    - Keep migrations as an explicit release command.
    - Update environment examples and configuration inventory.
    - Validation: local and cloud `docker compose config` pass without exposing
      secret values.

16. [x] Add bounded-query and production-sized automated checks.
    - Build a deterministic fixture representing at least the current catalog
      volume.
    - Assert bounded query counts and zero storage calls for navigation.
    - Add a Compose benchmark command for catalog and question-list reads.
    - Validation: PostgreSQL reads satisfy the plan's query bounds and the
      controlled server-time target without separate human test-case artifacts.

17. [x] Update migration and rollback runbooks.
    - Document backup, migration, dry run, import, comparison, provider switch,
      publication, Quiz API restart, verification, and rollback commands.
    - Include unpublished-revision diagnosis and publication retry.
    - Validation: command examples match implemented Symfony and Compose
      interfaces and contain no real credentials.

18. [x] Run the complete pre-merge verification suite.
    - Run Manager PHPUnit with PostgreSQL integration tests.
    - Run migration twice against a clean database.
    - Run import, comparison, publication, and retry tests.
    - Parse the OpenAPI contract and validate Compose configurations.
    - Confirm unrelated Go API and SPA contracts remain unchanged.

## Production Cutover

The [production migration record](../../runbooks/manager-postgresql-migration.md)
tracks the staged release, backups, and remaining source-catalog cleanup.

19. Prepare production without enabling PostgreSQL quiz reads.
    - Build and publish the Manager images.
    - Back up the Manager database and retain current S3 versions.
    - Deploy with provider `legacy` and run database migrations.
    - Run import dry run, apply import, aggregate comparison, and projection
      comparison.

20. Enable and verify PostgreSQL authoring.
    - Set the provider to `postgres` and recreate only Manager FPM/Web as
      required.
    - Verify health, authentication, themes, catalog, question reads, and
      publication status.
    - Run a reversible CRUD/publication check and confirm the final logical
      content.
    - Verify production navigation server time against the 500 ms target.

21. Activate the published revision in the Quiz API.
    - Publish the verified current database revision.
    - Restart only the Quiz API.
    - Verify API health, catalog counts, question availability, Redis-backed
      session continuity, and zero unexpected container restarts.

## Completion Criteria

- Every requirement and acceptance example in `spec.md` is covered by code,
  automated tests, or operational verification.
- Normal PostgreSQL-backed Manager navigation makes zero S3 reads.
- Source JSON, imported database, rendered projection, and published S3 content
  have matching logical counts and values at cutover.
- All Manager, migration, importer, publisher, OpenAPI, and Compose checks pass.
- The protected administration API and Manager UI operate from PostgreSQL.
- The Quiz API continues serving an in-memory snapshot from published JSON.
- Rollback remains possible without destructive database migration reversal.
