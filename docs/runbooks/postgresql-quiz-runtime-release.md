# PostgreSQL quiz runtime production release

Deployed on **2026-10-11** to `quickquiz-beta-ec2`, using the existing single-node
Docker Compose stack. Source: [PR #47](https://github.com/robmoraes/quick-quiz/pull/47),
merge `fa83557deebef25b08aedb101acbc00c4bd3f4d7`.
Final verification completed at `2026-10-11T18:51:42Z`.

The subsequent [SPA Dev 0.9.1 patch](spa-dev-theme-caption-release.md) changed
only the player frontend. The API, Manager and PostgreSQL runtime versions and
recovery procedures recorded here remain applicable.

Target: EC2 `i-043c1e8324ef79e4c`, `us-east-1d`, `34.207.253.17`,
`t3.small` (2 vCPU / 2 GiB). Production commands use the existing secret overlay:

```sh
cd /opt/quickquiz/compose
docker compose --env-file .env -f docker-compose.yml -f compose.secrets.yml ps
```

| Service | Previous version | Deployed version | Successful release workflow |
| --- | --- | --- | --- |
| Quiz API | `v0.8.0` | `v0.9.0` | [API](https://github.com/robmoraes/quick-quiz/actions/runs/38163521938) |
| Manager FPM and Web | `v0.13.0` | `v0.14.0` | [Manager](https://github.com/robmoraes/quick-quiz/actions/runs/38163539565) |

Published image index digests:

```text
API         sha256:36c33d9cb50125f7bccb7b2d7128a2136384e37982dde7d6eada8307434317a3
Manager FPM sha256:b12858f3acfd1a92156ef65233d073b4d5670e60c4734268ed80c5dd18a11027
Manager Web sha256:6d936e6d2ebfb662027ee097695714fc35665f133135272a2e14a809c54593be
```

See the [runtime runbook](postgresql-quiz-runtime.md) and
[spec 006](../specs/006-postgresql-quiz-runtime/spec.md) for commands and contracts.
This rollout used no Terraform changes or AWS instance resize.

## Cutover performed

1. Created protected PostgreSQL, Redis, published-content and configuration
   backups. Applied additive migration `0004_quiz_runtime.sql`; existing quiz
   content was retained without reimport. Migration SHA-256:
   `1b7efd52538a77caa6b8c1e17ede5fba5620bea3c852c11c6e1b321ebe589e1b`.
2. Provisioned the dedicated `quickquiz_runtime` API reader. Its eight
   `quiz_runtime_v1` views are readable; actual TCP connections verified denial
   of base-table access, writes and DDL.
3. Stopped Manager authoring writes at `18:41:43Z`, then stopped the old API
   writers. Ran the explicit Redis index dry-run and apply, preserving existing
   run records, prefix, database and TTL. No legacy-dependent runs remained,
   so intermediate serving with the S3 provider was unnecessary.
4. Compared PostgreSQL revision **2262** with a local mirror of all **36,119**
   published S3 objects. The mirror was verified by size and MD5 against S3
   ETags. Current and published revisions matched. Backend activation passed
   dry-run and apply with writes frozen and the comparison target revalidated.
5. Selected PostgreSQL for quiz runtime and explanation instructions, and for
   Manager authoring/publication. Recreated only API, Manager FPM and Manager
   Web. Removed the API question bind mount and Manager `/content` mount;
   protected operator files use a separate writable mount.
6. Verified synthetic canaries, removed their themes and sessions, and completed
   the final checks. Canary mutations advanced the catalog to **2270**, with
   current and published revisions equal.

## Active configuration

```dotenv
QUESTION_STORAGE_PROVIDER=postgres
SOLUTION_PROMPT_STORAGE_PROVIDER=postgres
QUIZ_DATABASE_URL__FILE=/run/secrets/quiz_database_url
QUIZ_DATABASE_MAX_CONNECTIONS=4
QUIZ_DATABASE_CONNECT_TIMEOUT=5s
QUIZ_DATABASE_QUERY_TIMEOUT=2s
QUIZ_DATABASE_MAX_IDLE_TIME=60s
API_STORAGE_STARTUP_TIMEOUT=30s
MANAGER_QUIZ_PERSISTENCE_PROVIDER=postgres
MANAGER_QUIZ_PUBLICATION_PROVIDER=postgres
```

API runs and explanation cache remain in Redis. Database `0`, prefix
`quickquiz:runs:`, `SESSION_TTL=30m`, fallback `en-US`, supported locales
`en-US,pt-BR`, and existing Manager sessions were preserved. The API uses its
reader secret; Manager retains its separate writer credential. Secret values
and real question content remain outside Git.

Quiz mutations now publish atomically in PostgreSQL. New operations see committed
changes without JSON publication or an API restart. S3 retains the historical
revision-2262 publication and is no longer the live quiz runtime source.

## Verification and limits

- Four synthetic runs covered `en-US` and `pt-BR`, content editing/deletion and
  API restart continuity. New runs observed edits without restarting API;
  captured runs retained their content. Canary cleanup left no canary themes.
- Hashes of ten authoring tables matched the baseline after cleanup. Final
  counts: **34 themes, 473 topics, 18,007 canonical questions, 36,014 translations
  and 202,452 answers**. S3 remained unchanged.
- All nine containers were running; all eight configured health checks passed.
  Traefik remained running.
  Public service health endpoints and API `/readyz` returned 200. Manager login
  displayed `0.14.0`. No OOM or crash restart was recorded. The six unrelated
  containers retained their identities.
- Ten-request HTTPS catalog samples from the node measured median/observed
  maximum of **27.6/32.5ms for Dev** and **26.1/26.9ms for DSLab**. These are
  smoke observations, not load-test percentiles.
- Idle observations: approximately **895MiB available RAM** and **1.8GiB free
  disk**. The production functional checks passed; the **100,000-question
  capacity gate remains unvalidated**, and a **complete S3 fallback was not
  rehearsed**.

## Protected evidence and backups

```text
/opt/quickquiz/releases/postgresql-runtime-20261011
/opt/quickquiz/backups/postgresql-runtime-20261011-qh8hrhuc
```

These server-only directories have mode `0700`; secret files have mode `0600`.
They retain pre-freeze/frozen/post-deploy PostgreSQL dumps, Redis RDB snapshots,
published-content archives, role/configuration recovery material, image
identities and verification reports. Final backup SHA-256 values:

```text
manager-after.dump
3c8e00c8e83c530e6fbd01e7500f15a7ce2f44b8e3d19fe65684b03058592114
redis-after.rdb
aad0b545bf9f545209ba5a96befd1546b71547fa6ea357b587a12c021a8219ff
```

These backups are on the node only. This rollout did not establish an off-node
backup policy. Local sanitized evidence is ignored under
`_temp/releases/postgresql-runtime-20261011/`.

The API reader DSN is stored at `/opt/quickquiz/secrets/api/quiz_database_url`.
The provisioning password is at
`/opt/quickquiz/secrets/manager/quiz_database_reader_password`; it is passed only
to reader-provisioning commands. Manager's existing writer secret is unchanged.

## Rollback and recovery

The verified PostgreSQL/v2-compatible images have these node-local rollback tags:

```text
quickquiz-rollback-api:postgres-runtime-20261011
quickquiz-rollback-manager-fpm:postgres-runtime-20261011
quickquiz-rollback-manager-web:postgres-runtime-20261011
```

For application rollback, retain PostgreSQL backend state, migration `0004` and
Redis records, and use compatible images with the matching protected Compose
configuration and secrets overlay. API `v0.8.0` and Manager `v0.13.0` are
ineligible for rollback of the PostgreSQL/v2 runtime.

For node recovery, restore the latest PostgreSQL backup and roles, protected
configuration/secrets, and a compatible Redis RDB when preserving captured
sessions. Use compatible release images and verify API readiness and publication
state before traffic. Do not reimport quizzes or reverse the additive schema.
Take newer backups when authoring continues beyond this record.

A local/S3 fallback requires a fresh, complete, verified export of the latest
committed revision, a v2-compatible reader, and coordinated provider switching
under a write freeze, following the runtime runbook. The historical S3
revision 2262 is insufficient after later PostgreSQL mutations.

Temporary SSH ingress rule `sgr-0e4b227f099949e25` was revoked after verification.
A security-group rule readback confirmed that only the original SSH rule,
`sgr-05af3ef14c51673f4`, remained.
