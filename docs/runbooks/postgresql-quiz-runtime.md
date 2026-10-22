# PostgreSQL quiz runtime

This guide documents the application's PostgreSQL runtime/publication contract
and disposable local rehearsal. Environment-specific configuration, selected
versions and deployment/recovery records belong to the infrastructure workspace;
see [deployment responsibilities](../deployment.md).
See [spec 006](../specs/006-postgresql-quiz-runtime/spec.md)
and [ADR 004](../architecture/adr/004-postgresql-quiz-runtime.md).

## Configuration

| Service | Setting | Purpose |
| --- | --- | --- |
| Manager | `MANAGER_QUIZ_PERSISTENCE_PROVIDER=postgres` | Authoritative quiz writer |
| Manager | `MANAGER_QUIZ_PUBLICATION_PROVIDER=postgres` | Atomic content/publication transaction |
| Manager | `MANAGER_DATABASE_URL` / `__FILE` | Writer credential; never shared with API |
| API | `QUESTION_STORAGE_PROVIDER=postgres` | Scoped live reads; no JSON preload |
| API | `QUIZ_DATABASE_URL` / `__FILE` | Dedicated SELECT-only reader |
| API | `SOLUTION_PROMPT_STORAGE_PROVIDER=postgres` | Current theme explanation instructions |
| API | `RUN_STORAGE_PROVIDER=redis`, `SOLUTION_STORAGE_PROVIDER=redis` | Runs/snapshots and explanation cache |
| Both | `FALLBACK_LOCALE`, `SUPPORTED_LOCALES` | Must match the activated catalog declaration |

API pool defaults: 4 connections, connect 5s, query 2s, idle lifetime 60s.
`__FILE` takes priority; an invalid selected secret file fails configuration.
An absent prompt-provider setting inherits the question provider. Legacy defaults
remain unchanged; installing the migration does not activate PostgreSQL reads.

## Disposable local rehearsal

Use new project/volume names, unused published ports, synthetic content, and
locally built candidate images. Do not reuse the preview's volume or import
from production. The Compose base already supplies PostgreSQL and Redis; nothing
needs installing on the host.

1. Copy `deploy/compose.local/.env-example` to an ignored environment file.
   Set a distinct `COMPOSE_PROJECT_NAME`, ports, candidate image tags and
   `QUICKQUIZ_CONTENT_ROOT` pointing to an isolated synthetic bank. Initially
   use PostgreSQL authoring and `content_storage` publication.
2. Start only the database/Redis, then run the Manager commands in that Compose
   project. Examples below assume a shell function named `dc` containing the
   chosen env/project/base Compose arguments; include `deploy/compose.secrets.yml`
   when mounting secrets. For export/verification files, prepare an operator-only
   overlay with a persistent writable bind mount (the base Manager filesystem
   is read-only):

```yaml
services:
  manager-fpm:
    volumes:
      - /absolute/path/to/operator-runtime:/operator:rw
```

Create that host directory with mode `0700`, owned by the configured
`QUICKQUIZ_RUNTIME_UID:GID`. Include the overlay in `dc`; keep reports outside
individual fresh export subdirectories. Preserve the files through cutover and
copy them into the operator's backup location afterward. Quiz CRUD does not
require this mount.

```sh
dc up -d manager-db redis
dc run --rm --no-deps manager-fpm php bin/console manager:database:migrate
# Fresh synthetic database only: inspect before import; never reimport production.
dc run --rm --no-deps manager-fpm php bin/console manager:quiz:import --dry-run
dc run --rm --no-deps manager-fpm php bin/console manager:quiz:import --apply
# Explicitly publish this newly imported synthetic bank before comparison.
dc run --rm --no-deps manager-fpm php bin/console manager:quiz:publish
```

3. Provision the dedicated reader with the operator/writer connection. Supply
   `QUIZ_DATABASE_READER_PASSWORD__FILE` to the one-off command container using
   an existing secret mount, or pass `QUIZ_DATABASE_READER_PASSWORD` from the
   operator environment. For the file variant, explicitly pass
   `-e QUIZ_DATABASE_READER_PASSWORD__FILE=/run/secrets/quiz_database_reader_password`
   to `dc run` and use the secrets overlay; provisioning variables are not
   automatically forwarded by the base Compose service. The command emits no credential. This explicitly
   revokes PUBLIC TEMP on the dedicated database; the owner retains privileges.

```sh
dc run --rm --no-deps manager-fpm php bin/console manager:quiz:provision-reader \
  --role=quickquiz_runtime --dry-run
dc run --rm --no-deps -e QUIZ_DATABASE_READER_PASSWORD manager-fpm \
  php bin/console manager:quiz:provision-reader --role=quickquiz_runtime --apply
```

4. Complete the compatibility/index stage below if Redis contains runs. A
   genuinely empty Redis DB can initialize its index automatically. An existing
   unmarked DB cannot be mistaken for an empty session history.
5. Start `manager-fpm`/`manager-web` with the initial `content_storage` publication
   setting, then freeze authoring, prompt and tag writes. Read the current/published revision
   from the protected publication endpoint. They must be equal. Run revisioned
   comparison against the configured legacy source; an actual zero legacy-run
   count is required, not an assumed value. Persist the report outside content.

```sh
dc exec -T manager-fpm php bin/console manager:quiz:compare \
  --expected-revision="$REVISION" --legacy-provider-runs=0 --report=/operator/compare.json
dc exec -T manager-fpm php bin/console manager:quiz:runtime-backend \
  --provider=postgres --expected-revision="$REVISION" \
  --verification-report=/operator/compare.json --dry-run
dc exec -T manager-fpm php bin/console manager:quiz:runtime-backend \
  --provider=postgres --expected-revision="$REVISION" \
  --verification-report=/operator/compare.json --apply --writes-frozen
```

The commands share the persistent operator mount. Activation re-reads the
target and rejects stale evidence or pending legacy publication. The publish
command above is for the new synthetic setup only: in production, inspect and
explicitly reconcile pending changes before approving any promotion.

6. Configure the reader DSN and add `deploy/compose.local/compose.postgres.yml`
   **after** the base file and **before** the secrets overlay. This opt-in overlay
   selects PostgreSQL questions/prompts/publication and removes the API question
   bind mount. Its default reader password is for disposable local use only;
   supply the provisioned secret for any persistent environment.
7. Start candidate API/Manager, verify `/readyz`, theme discovery, a new run and
   a Manager edit appearing on the running API without a restart. Keep Ads on
   its existing storage. Then unfreeze writes.

The PostgreSQL schema/backend change and provider configuration form one
coordinated cutover; do not leave old publication writers operating against a
new backend. A candidate API independently refuses PostgreSQL startup while
legacy-provider-dependent runs remain.

## Existing Redis runs: compatibility stage

Deploy a v2-compatible API image **with the existing local/S3 question provider**
first. Stop all pre-index API writers before the final index pass. Do not flush
Redis or change the run prefix/database. This preserves served option IDs and
lets legacy runs finish/expire while all new runs capture v2 snapshots.

```sh
# Run inside the candidate API container with its existing Redis configuration.
quickquiz-api migrate-run-index --dry-run
quickquiz-api migrate-run-index --apply --confirm-legacy-writers-stopped
```

The JSON report includes the number of runs still requiring the legacy question
provider. Apply preserves run JSON and remaining TTL; it is retryable. Player
operations needing session consumption return storage unavailable until the
completion marker exists. The administrative migration may scan old keys;
player availability/create/reset operations use the scoped index.

Repeat the report until the actual legacy-provider-dependent count is zero
before the PostgreSQL cutover. Historical answered v1 records cannot prove their
old answer content and explicitly return `solution_unavailable`. Existing v2
runs survive content changes/deletions and PostgreSQL outages while Redis is
available. Never roll back to an image predating v2 snapshot support while such
runs remain.

## Health and publication

`/healthz` is process liveness. `/readyz` checks the selected PostgreSQL contract
and Redis dependencies with a bounded deadline. Dependency errors return
`503 storage_unavailable`; missing content remains distinct.

Compose/Traefik continue using liveness for the shared route: withdrawing every
request on a PostgreSQL outage would also interrupt Redis-backed captured runs.
Use readiness for cutover checks and monitoring; inspect errors and dependency
health before routing a candidate.

In PostgreSQL publication mode, mutation success reports `backend=postgres`,
`apiReloadRequired=false`, committed revision and null export checksum/count.
Topic tags alone create no revision. An optional JSON export is an independent
operator job and never delays a live content transaction.

## Explicit legacy export and rollback

Prefer an application rollback to a known PostgreSQL/v2-compatible image,
retaining the additive schema and data. Take/verify PostgreSQL backups and retain
Redis persistence according to the existing recovery policy. S3 is an export,
not the authoritative backup of PostgreSQL content.

Fallback to local/S3 requires a fresh, full, verified export of the latest
revision and an explicitly attested v2-compatible reader image. Freeze **all**
authoring, prompt and tag writes through export, provider switch and validation.
The export includes inactive retained content, theme tags and explanation
prompts; topic tags remain in PostgreSQL and are omitted from legacy JSON
(the new public discovery API can expose them). Failed or partial targets must
not be used. Retry into a fresh directory/prefix.

```sh
php bin/console manager:quiz:export --format=legacy-json --target=local \
  --directory=/operator/new-export --expected-revision="$REVISION" --dry-run
php bin/console manager:quiz:export --format=legacy-json --target=local \
  --directory=/operator/new-export --expected-revision="$REVISION" \
  --snapshot-reader-version=2 --report=/operator/export.json --apply
# S3 uses --target=s3 --bucket=NAME --prefix=isolated/new-prefix instead of directory.
php bin/console manager:quiz:runtime-backend --provider=content_storage \
  --expected-revision="$REVISION" --verification-report=/operator/export.json --dry-run
php bin/console manager:quiz:runtime-backend --provider=content_storage \
  --expected-revision="$REVISION" --verification-report=/operator/export.json \
  --apply --writes-frozen
```

Configure Manager `content_storage` publication and the API legacy provider to
that exact verified target together. Start the candidate, measure its full-bank
reload duration, verify snapshots/session continuity and then restore routing
and writes. Never fall back automatically on a database outage or use an old
S3 prefix after newer PostgreSQL writes. Never destructively reverse the schema.

## Automated evidence

Go: configuration, runtime adapter, snapshots, prompts, HTTP dependency errors,
Redis indexing/backfill and real SELECT-only PostgreSQL tests. Manager: atomic
transactions, no-storage CRUD/prompts, login/container boot, reader grants,
streamed export and activation/rollback guards. The Go PostgreSQL fixture accepts
only the dedicated `quickquiz_runtime_test` database and is destructive there.

```sh
cd apps/api
go test -race ./...
# Explicit disposable PostgreSQL only:
QUICKQUIZ_POSTGRES_TEST_URL="$DISPOSABLE_POSTGRES_URL" \
  go test ./internal/store -run TestPostgres -count=1 -v
# Explicit disposable Redis only:
QUICKQUIZ_TEST_REDIS_ADDR="$DISPOSABLE_REDIS_ADDR" \
  go test ./internal/store -run 'TestRedis' -count=1 -v
# Manager runtime integration opts in separately and requires this exact DB:
# quickquiz_manager_runtime_test. Never reuse the application's configured DB.
cd ../manager
QUICKQUIZ_MANAGER_POSTGRES_TEST_URL="$DISPOSABLE_MANAGER_POSTGRES_URL" \
  vendor/bin/phpunit --filter QuizRuntimeIntegrationTest
```

The `Validate quiz runtime` pull-request workflow supplies isolated PostgreSQL
databases for Go and Manager and Redis for Go, using synthetic credentials only.
It runs Go with the race detector and the complete Manager suite with
`pdo_pgsql`/`pdo_sqlite`. The large synthetic capacity benchmark remains a
separate reproducible operator check, not a pull-request CI workload.

Functional receipts are in [implementation verification](../specs/006-postgresql-quiz-runtime/verification.md).
Capacity evidence uses synthetic data; see the spec's acceptance budget and its
`evidence/` artifacts. Passing functional tests alone does not prove the proposed
2-vCPU/2-GiB capacity budget. Functional checks of a deployed bank do not establish
the proposed 100k load budget; capacity evidence must match the target environment.
