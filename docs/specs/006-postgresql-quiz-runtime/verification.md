# Implementation verification — 2026-10-11

Implementation branch: `feat/postgresql-quiz-runtime`. Spec/ADR approval is
recorded separately from deployment. No remote access, production mutation,
release tag, image push or change to the existing local preview was performed.
All content and credentials below refer to disposable synthetic fixtures.

## Functional checks

| Area | Evidence |
| --- | --- |
| Go runtime | `go test -race ./...`: application, configuration, HTTP and stores pass |
| Manager | PHP 8.3.33 / PostgreSQL 17: 220 tests, 914 assertions in 5.791 s; 54.5 MB PHPUnit peak (final review run) |
| Reader permission boundary | Actual TCP reader login; SELECT on eight allowlisted views; base tables, Manager users/prompts, writes, public DDL and TEMP denied |
| Runtime contract | Real PostgreSQL tests reject backend/locales mismatch and incompatible column types even with empty views; bounded pool max 4 |
| Catalog | Synthetic literature/agronomy/Dev/DSLab; exact audience tags, inactive filtering, localized fallback, weight/ID theme ordering, grouped counts with duplicate/deleted consumed IDs |
| Run snapshots | Limits 5/10, all translations, served option identity, only presented IDs consumed, edit/delete/retirement isolation, legacy compatibility, locale sharing, reset and hardcore rules |
| Explanations | Fake generator, captured content, current instructions, neutral Go/PHP default parity, cache content/prompt/config/model/locale identity; no real OpenAI calls |
| Redis | Redis 7.4 real plus unit adapters: backfill dry-run/apply/acknowledgment, interrupted retry, remaining TTL/run JSON preserved, incomplete-index failures, concurrent request counters, no global SCAN in player paths |
| Atomic publication | Manager rollback on writer/publication failures, active-parent/locale validation, topic-tags-only revision isolation, no S3 I/O/full-bank render for database CRUD |
| Export/activation | Streamed local export crosses the 100-localized-package batch; exact inventory/read-back and JSON object/array distinction; stale evidence, changed targets and pending revisions rejected |
| CLI | Actual migrate, provision reader with prioritized `__FILE`, export, comparison, backend dry-run/apply and idempotent publish commands exercised |
| Builds/config | API Docker build with Go 1.25 / Alpine 3.22; CGO-disabled Linux ARM64 compilation; local/cloud Compose and PostgreSQL overlay validation |
| Contracts | Canonical public and Manager OpenAPI additions merged; YAML duplicate-key/local-reference checks and legacy/PostgreSQL publication profile examples pass |

## Cross-service HTTP rehearsal

`deploy/validation/quiz-runtime-smoke.py` is an automated acceptance client for
an explicitly acknowledged disposable Manager/API pair. The run used the built
API container, the current Manager code on the PHP base image, separate
PostgreSQL/Redis, a view-only API credential, and no S3 bucket or question mount
for the database path.

The scenario passed in 1.026 seconds:

1. Administrative requests without a token were denied.
2. Manager created an inactive literature theme, localized topic and question;
   player discovery hid the draft and private topic tags.
3. Activating the theme made it discoverable under `arts`, not `dev`.
4. A Manager question replacement appeared in a new run on the already-running
   API; the existing run retained the original localized question/options.
5. After deleting the authored question, the original run still graded the
   captured correct option and returned its result.
6. Existing fixed-theme DSLab and Dev catalog requests continued working.

Additional container-level checks passed:

- Revoking only prompt-view access made `/readyz` return 503 while the catalog
  remained usable. Optional instructions did not become a gameplay dependency.
- Pausing the disposable PostgreSQL made `/readyz` and catalog fail within the
  configured deadline (`503 storage_unavailable`), while `/healthz`, captured
  state and answer remained functional through Redis.
- Restarting the API preserved the current question and served option IDs.

## Deliberate fallback rehearsal

At synthetic revision 9, the explicit export produced 302 verified legacy
objects, including retained inactive content and prompts. Export did not change
the publication revision. Backend dry-run/apply then selected `content_storage`
under the explicit write-freeze acknowledgment.

A fresh container using the same v2-compatible API image and local export loaded
in 0.196 seconds (small synthetic bank, including Docker launch). It served new
catalog requests and continued/graded a Redis run originally created from
PostgreSQL with unchanged options. The actual migration report visited five
runs and found zero legacy-provider-dependent visits. Revisioned comparison
against the exported target then allowed an explicit return to PostgreSQL.

This rehearsal used the **local** legacy provider. S3 adapters retain their
existing automated contract tests; no AWS bucket was created and no complete
remote S3 fallback/reload was timed. A release targeting S3 fallback must verify
its fresh target and measure reload time before use. No pre-v2 image is an
eligible rollback target for retained snapshots.

## Capacity and release gate

The [capacity artifacts](evidence/local-100k-200k/README.md) record 24,000
synthetic HTTP operations across 100k/200k canonical questions with zero errors.
Maximum phase p95 RSS was 23.54/23.58 MiB; captured state/answer performed zero
SQL queries. Worst p95 creation was 82.4 ms and catalog 52.7 ms. Receipts include
fixture sizes, SQL plans, RSS, dependency counts and resource limits. They distinguish the shared local host from a dedicated release
node. Acceptance of a 2-vCPU/2-GiB **complete production stack** is a separate
release gate; neither functional correctness nor isolated API/PG/Redis caps
prove capacity for every deployed service or arbitrary payload sizes.

## Review and release preparation

The final review repeated the full Go race suite against disposable PostgreSQL
and Redis and the complete Manager suite against a separate disposable database.
The new Manager runtime tests require `QUICKQUIZ_MANAGER_POSTGRES_TEST_URL` and
the exact database name `quickquiz_manager_runtime_test`; the ordinary application
DSN alone skips them, and a different database name fails before connection.
All disposable review containers were removed.

The `Validate quiz runtime` pull-request workflow runs those dependency-backed
suites in separate jobs, with PostgreSQL 17, Redis 7.4, PHP 8.3 and both PDO
drivers. It uses synthetic credentials and never runs the large capacity fixture.
Its structure was checked locally; actual GitHub execution is a separate check.
Both canonical OpenAPI documents passed duplicate-key/reference validation
(321 resolved references); final spec/runbook/ADR links and version defaults
were verified. The Git-visible diff contains no secret signatures or real bank
fixtures.

Candidate versions are API `0.9.0` and Manager `0.14.0`; canonical public and
Manager OpenAPI documents are `0.4.0` and `0.5.0`. App release tags and image
publication remain after merge. The database/run snapshot contracts retain
versions 1/2 respectively; they are independent of app release versions.

The only visible Manager change is the theme-context helper text. Its
[screenshot](screenshots/manager-theme-context.png) renders the real Twig
form/layout/assets with a synthetic literature fixture and PostgreSQL
publication status. This is visual evidence, separate from the functional
cross-service rehearsal above.

Release preparation must still publish images, back up the authoritative
database, rehearse the exact deployment configuration, retain compatible
rollback images, and obtain the separately requested production authorization.
