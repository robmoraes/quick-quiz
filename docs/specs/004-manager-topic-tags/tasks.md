# Tasks: Manager Topic Tags

Status: Implementation and local verification complete; production rollout is pending.

1. [x] Implement the tag-set rules and optional-input semantics.
   - Normalize/validate slugs, enforce limits, deduplicate, and sort.
   - Validation: unit cases for boundaries, malformed values, omission, and clearing.

2. [x] Add migration 0002 and PostgreSQL tag persistence.
   - Create shared identities and topic associations with keys and foreign keys.
   - Replace associations transactionally under the existing catalog lock.
   - Validation: migration twice, constraints, shared identity, rollback,
     concurrent saves, and deletion without changing another topic's set.

3. [x] Integrate topic reads and saves with tags.
   - Return tags in PostgreSQL topic/catalog reads with bounded query counts.
   - Persist metadata and supplied tags together; omitted tags preserve data.
   - Validation: create/read/update/clear, both locales, same topic key in different
     themes, ordinary metadata saves, and localization/AI save preservation.

4. [x] Separate tags-only persistence from publication.
   - Classify normalized metadata changes under the shared lock and return an
     explicit per-operation result.
   - Validation: no storage calls or changes to publication state for tags-only
     saves; mixed changes retain normal publication and failure behavior.

5. [x] Preserve tags in migration tools and exclude them from JSON.
   - Retain associations for surviving identities in replacement imports.
   - Audit projection and catalog serialization boundaries.
   - Validation: exact projection/checksum stability, idempotent import,
     replacement survival/removal, and full rollback on import failure.

6. [x] Extend the administrative API and its OpenAPI contract.
   - Add optional input/read tags and the topic-specific tags-only response.
   - Reject supplied tags in legacy mode with the documented 409 error.
   - Validation: authentication, 422 rollback, legacy compatibility, old-client
     omission, response schemas, and unchanged unrelated mutation envelopes.

7. [x] Add tag editing/display to Manager Web.
   - Add the simple field and catalog display with escaped values and accessible
     validation; keep tags global across topic locales.
   - Validation: compiled-kernel form save/readback, failed form redisplay, CSRF,
     empty-field clearing, and unsupported-provider behavior.

8. [x] Complete verification and release documentation.
   - Run Manager PHPUnit with PostgreSQL, parse OpenAPI, and verify bounded reads.
   - Update Manager usage/recovery guidance and ADR 002's publication distinction.
   - Increment the Manager version when preparing its release.
   - Validation: only Manager implementation/contracts change; published JSON,
     player API, Ads API, and SPA code/contracts remain unchanged.

9. [ ] Apply the approved Manager-only rollout.
   - Back up PostgreSQL, apply the additive migration, and deploy Manager images.
   - Verify a reversible topic tag save, API readback, and publication isolation.
   - Record evidence; retain the schema for application rollback.
   - No catalog re-import, Quiz API restart, or SPA deployment is required.

## Completion Criteria

- Manager Web and the administrative API manage the same durable topic tag sets.
- Older clients omitting tags preserve existing associations and behavior.
- Tags-only writes require PostgreSQL and make zero content-storage calls.
- Projection bytes, checksums, and publication revisions exclude tags.
- Validation, concurrency, import, deletion, and rollback behavior are covered
  by executable checks derived from the [specification](spec.md).

## Local Verification Evidence

Verified with PHP 8.3.33, PostgreSQL 17, and PHPUnit 11.5.55 in Docker:

- Full Manager suite: **198 tests, 752 assertions**, all passing.
- Compiled-kernel tests cover both providers, authenticated administrative CRUD,
  topic form creation/editing/clearing, escaped errors, CSRF, and the 50-character
  boundary. AI controller tests preserve unsaved tags and validate before calls.
  Login rendering (including an error) does not discover or expose AI models;
  the authenticated footer retains the configured model selector.
- Separate-process concurrent replacements keep complete tag sets. Injected
  SQL/storage failures verify rollback and publication isolation. Import tests
  preserve surviving associations and restore them on failed replacement.
- A 409-question fixture plus 100 extra tagged topics keeps accurate counts;
  all 101 topics and their tags load in one SQL statement.
- Manager OpenAPI 0.3.0 parses with all local references resolved; its new
  components match the specification excerpt. Player contracts are unchanged.
- Manager FPM and Web images built locally for `linux/amd64` as
  `quickquiz-manager-fpm-smoke:v0.12.0` and
  `quickquiz-manager-web-smoke:v0.12.0`. The FPM image passed migration
  presence/idempotency, authenticated topic CRUD, tags-only publication
  isolation, and compiled Twig rendering without a source-code mount.
- Docker context allowlists now include versioned Manager migrations.
- Screenshots captured from the built image using synthetic data:
  [topic form](screenshots/topic-tags-form.png) and
  [catalog badges](screenshots/topic-tags-catalog.png), and
  [login without AI controls](screenshots/manager-login.png).

No production migration, image publication, or deployment was performed.
See the [Manager tag rollout guide](../../manager/topic-tags.md).
