# Manager PostgreSQL migration record

The later [Manager 0.12.0 deployment](manager-topic-tags-release.md) records the
current image versions and backups after adding topic tags. This document
retains the original PostgreSQL cutover evidence.

## Completed cutover on 2026-09-19

At the completed cutover, Manager FPM/web ran `v0.11.2` with
`MANAGER_QUIZ_PERSISTENCE_PROVIDER=postgres`. PostgreSQL is now the source for
quiz authoring; publication still writes JSON to S3. The Quiz API remains on
`v0.7.0` and was restarted successfully to load the published catalog.

The catalog contains 2 themes, 34 topics, 433 canonical questions, and 866
question translations. Current and published database revisions are both `8`,
with status `published`. Reversible CRUD verification left no synthetic content;
source S3 and the database projection have the same logical checksum recorded
below. A disposable player session retained its question and options across
the API restart and was then reset.

Published releases:

- PR #40: `2bd4b2b58657eea75ade4cfa8d51c1ed2fd5d938`, tag `manager/v0.11.0`,
  [successful workflow](https://github.com/robmoraes/quick-quiz/actions/runs/35442483604).
- PR #41: `e91c18a604494b1ce0332584c9a2b59e29efa135`, tag `manager/v0.11.1`,
  [successful workflow](https://github.com/robmoraes/quick-quiz/actions/runs/35443690918).
- PR #42: `0930b06174a9f391448024d605e94b3076867cfc`, tag `manager/v0.11.2`,
  [successful workflow](https://github.com/robmoraes/quick-quiz/actions/runs/35445157069).

All three releases published Manager FPM and web images for `linux/amd64` and
`linux/arm64`. Versions `v0.11.0` and `v0.11.1` have the factory wiring defect
described below; use `v0.11.2`.

Multiarch image index digests used at cutover:

```text
robmoraes/quick-quiz-manager-fpm:v0.11.2
sha256:48362a3fbf0f42cf49c780b767724c9eccde91b351855807377de0a9e1d93255
robmoraes/quick-quiz-manager-web:v0.11.2
sha256:9b72d06a38ae553fe8900ae4d3227b85e23855dd50b76a0719f536e9df266488
```

## Backup and staged files

- Server: `i-043c1e8324ef79e4c`, `34.207.253.17`.
- Initial backup: `/opt/quickquiz/backups/manager-v0.11.0-20260919-CPcWFf`.
- Backup refreshed with editing paused, immediately before the import:
  `/opt/quickquiz/backups/manager-v0.11.1-20260919-WT7CDQ`.
- Validated database dump: `manager-before.dump`; its `pg_restore --list`
  output is stored as `dump-manifest.txt`.
- Prior Compose configuration: `compose/`; image references: `manager-images.txt`.
- S3 version manifest: `s3-versions-before.json`; bucket versioning is enabled.
- Archive/rollback version manifest: `orphan-archive-manifest.json` in the
  initial backup directory.
- Initial staging: `/opt/quickquiz/releases/manager-v0.11.0-20260919`.
- Failed-release staging: `/opt/quickquiz/releases/manager-v0.11.1-20260919`.
- Successful-release staging: `/opt/quickquiz/releases/manager-v0.11.2-20260919`.
- Successful-cutover backup: `/opt/quickquiz/backups/manager-v0.11.2-20260919-d4Dnyq`.

The earlier `v0.11.1` pre-import dump SHA-256 is
`790d2aaee8e83120eb9ce186f6a791555675ec0714dced1e845dcfc248b329d8`.
It contains the additive quiz schema before content import. Its Compose backup
was used for the earlier application rollback. The failed `v0.11.1` staging
`.env` remains historical; the active configuration under `/opt/quickquiz/compose`
selected `v0.11.2` and `postgres` at cutover.

The successful-cutover backup contains:

- `manager-before.dump` and `dump-manifest.txt`, taken while Manager editing was
  paused before activating `v0.11.2`; the catalog was already imported at revision 1.
- `manager-after.dump` and `dump-after-manifest.txt`, taken after verification
  completed at revision 8. Both dumps are nonempty and passed `pg_restore --list`.
- `compose/` before cutover and `compose-after/` with the final active configuration.
- `s3-versions-before.json` and `s3-versions-after.json`, each identifying 875
  current content objects; original versions and verification delete markers
  remain recoverable through S3 versioning.
- `containers-before.txt` and `services-before.txt` for the previous runtime state.

Dump SHA-256 values, also stored alongside the dumps:

```text
manager-before.dump
c4a095813be170f242dcbdaa3bced8d827f8f066d586c163e1151abdc2451ac2
manager-after.dump
a70b46f6690e7f04eff207a8bbbf7e0298b8443437fee4536eff0a130f1e5935
```

The initial S3 manifest contains 877 current objects totaling 645,909 bytes;
the refreshed manifest contains 875 current objects after the archive below.
The database dump remains on the server; an attempted local export was blocked
by automatic approval review and was not retried.

## Preflight findings

The first dry run rejected a partial `dev/en-US/index.json` topic metadata
index. Existing Manager and Quiz API behavior permits missing localized topic
metadata and falls back to central metadata. Version `0.11.1` corrects the
importer to preserve absent translation rows and their JSON representation.
Question locale parity remains mandatory.

A subsequent read-only audit identified two orphan files whose `quickquiz`
topic is absent from the central catalog:

```text
dev/en-US/quickquiz/1/quickquiz-1-001.json
dev/pt-BR/quickquiz/1/quickquiz-1-001.json
```

Their S3 ETags match the byte hashes of the corresponding versioned demo files
in `deploy/content-demo`. With explicit operator approval, both were copied to:

```text
s3://quickquiz-beta-content-379197597050-us-east-1/migration-archive/manager-postgres-20260919/orphan-questions/
```

The archive preserves each original relative path. Copies were verified by
ETag and size before conditional deletion from the active `questions/` prefix.
Deletion created recoverable S3 delete markers; the original version IDs remain
readable and were verified afterward. The archive manifest records source,
archive, and delete-marker version IDs. The importer continues to reject
questions whose theme or topic is absent from the catalog.

A read-only audit excluding exactly those two files passed the remaining
source validation and reported:

| Resource | Count |
| --- | ---: |
| Themes | 2 |
| Topics | 34 |
| Topic metadata translations | 59 |
| Canonical questions | 433 |
| Question translations | 866 |
| Correct answers | 1,334 |
| Wrong answers | 7,678 |

After the authorized archive, an unfiltered `manager:quiz:import --dry-run`
using the same corrected importer as PR #41 passed with the counts above.
The corrected source file was mounted read-only into an ephemeral `0.11.0`
container; the active Manager application was not changed. The report is stored
in the initial staging directory as `import-dry-run-after-archive.json`.

## Import, publication, and rollback

The stock `v0.11.1` image passed the full dry run. With Manager FPM/web stopped
to pause editing, the database backup and S3 version manifest were refreshed.
Import succeeded with the counts above and revision `1`; the independent
projection comparison reported no missing, extra, or changed items. Both
checksums were:

```text
33e8c21173d05e1ac817e33ff6a4cec1d04cc33faad90c9ddf7e3ab655cd6c54
```

Initial publication succeeded at revision `1`, writing 222 JSON objects in
43,920 ms. The writes normalized serialization/metadata while preserving the
logical catalog. Reports in the failed `v0.11.1` staging directory are
`import-dry-run.json`, `import-apply.json`, `comparison-before-switch.json`,
and `publication-initial.json`.

Container health checks passed on activation, but an authenticated controller
render failed because `QuizAuthoringServiceFactory::create()` was called
statically. The Symfony factory configuration referenced a class instead of
the factory service. CLI import/publication resolve their dependencies
independently and were unaffected. The previous image/configuration was
restored immediately; the schema, imported catalog, and successful JSON
publication were retained. A second full comparison after rollback also passed
with the same checksum and no differences; its report is
`comparison-after-rollback.json` in the failed `v0.11.1` staging directory.

Version `0.11.2` corrects the factory reference. Regression tests reproduce the
failure before the fix and exercise authenticated administrative requests plus
theme-page rendering through a compiled kernel for both providers. The full
local Manager suite passed with 166 tests and 544 assertions. The final local
`linux/amd64` image also passed those request/render smoke checks in `prod`
with both providers, without binding application source or configuration.

In an isolated `v0.11.1` container with only the corrected service configuration
and a rebuilt production cache, the imported production catalog rendered:

| Authenticated page | HTTP status | Server time |
| --- | ---: | ---: |
| `/themes` | 200 | 146.66 ms |
| `/catalog` | 200 | 210.52 ms |
| `/questions?locale=pt-BR&topic=php&difficulty=1` | 200 | 39.59 ms |
| `/stats` | 200 | 56.60 ms |

These measurements use an in-memory authenticated test session and cached
OpenAI model list; they do not measure browser latency or login. The PostgreSQL
read benchmark over 10 iterations reported p50/p95 of 32.20/32.84 ms for `dev`
and 19.69/20.18 ms for `dslab`. Reports are `ui-verification-fixed.json`,
`benchmark-dev.json`, and `benchmark-dslab.json` in the failed `v0.11.1` staging directory.
These isolated checks did not change the running Manager.

## Successful v0.11.2 cutover

The stock published image passed authenticated administrative requests and
rendering in an isolated container without application/configuration patches.
With editing paused, backups were refreshed and the complete S3/database
comparison passed again. Only Manager FPM/web were recreated for activation.

The active image passed four authenticated controller renders with an in-memory
test session and cached OpenAI model list:

| Page | HTTP status | Server time |
| --- | ---: | ---: |
| `/themes` | 200 | 97.31 ms |
| `/catalog` | 200 | 311.71 ms |
| `/questions?locale=pt-BR&topic=php&difficulty=1` | 200 | 51.56 ms |
| `/stats` | 200 | 75.35 ms |

These are server measurements, excluding browser/network latency and login.
PostgreSQL read benchmarks over 10 iterations reported p50/p95 of 32.71/33.87 ms
for `dev` and 19.92/20.40 ms for `dslab`, below the 500 ms single-user target.

External HTTPS administrative verification passed 21 checks: unauthenticated
requests were rejected, authenticated reads returned the imported catalog, and
an inactive synthetic theme/topic/question was created, read, updated, and
removed. All seven mutations reported successful publication, advancing revision
1 to revision 8. After cleanup, the complete S3/database comparison passed with
no missing, extra, or changed items and the original checksum.

Only the Quiz API was then restarted. Catalog checks in both `en-US` and `pt-BR`
matched the active Manager topic IDs, localized labels, and per-topic question
counts:

| Theme | Topics | Questions per locale |
| --- | ---: | ---: |
| `dev` | 12 | 281 |
| `dslab` | 22 | 152 |

Before restart the API still held an older DSLab snapshot with 20 topics and
128 questions; reloading made the current 22 topics and 152 questions available.
A disposable Redis-backed run retained its active status and identical question
and options after restart. Only that test session was reset.

Manager and API health checks passed. Container image IDs, start timestamps,
and restart counters confirm that only Manager FPM/web and the Quiz API changed;
PostgreSQL, Redis, Ads API, both SPAs, and Traefik were not restarted. The node
had approximately 1,182 MiB available RAM after verification. This is an idle
post-cutover observation, not a capacity/load test.

Evidence under `/opt/quickquiz/releases/manager-v0.11.2-20260919`:

- `image-verification.jsonl`, `live-ui-verification.jsonl`;
- `comparison-preflight.json`, `comparison-before-switch.json`,
  `comparison-after-crud.json`;
- `manager-verification.json`, `benchmark-dev.json`, `benchmark-dslab.json`;
- `api-catalog-before.json`, `api-catalog-after.json`, `api-continuity-result.json`;
- `runtime-after.json`, `services-after.txt`, `containers-after.txt`, `backup-path.txt`.

Reports contain metadata, counts, timings, and checksums, not tokens, prompts,
or answers. Database dumps and secret-bearing Compose configuration remain
protected on the server outside containers.

## Recovery and future deployments

Use the live Compose secret override for all remote operations:
`docker compose --env-file .env -f docker-compose.yml -f compose.secrets.yml`.

For another node, provision the underlying infrastructure and secret mounts as
recorded in the [infrastructure migration runbook](production-stateless-migration.md).
Use the latest [Manager deployment versions](manager-topic-tags-release.md)
and the PostgreSQL provider; the older
infrastructure runbook's image list describes its original migration date.
Restore the latest protected PostgreSQL backup into a clean `manager-db` database
before starting Manager. Retain/recover the matching S3 publication and versions;
Redis remains external to application containers, but rebuilding its ephemeral
store does not recover old player sessions.

Run database migrations explicitly, then validate authenticated Manager requests,
page rendering, and publication status. Compare PostgreSQL projection with S3;
if publication is pending, publish/retry from PostgreSQL and verify success before
starting or restarting the Quiz API. PostgreSQL is now authoritative: do not
routinely re-import legacy JSON over its newer authoring state. Import is only
for an explicit migration/reconciliation operation.

For application rollback or publication recovery, follow the
[quiz cutover runbook](../manager/postgresql-quiz-cutover.md). Retain the schema
and database contents. The prior `v0.10.0` Manager can use the retained JSON with
`legacy`, but never reactivate the defective `v0.11.0`/`v0.11.1` images. If legacy
editing resumes, reconcile changes before returning to PostgreSQL.
