# Manager PostgreSQL migration record

## State on 2026-09-19

The database import and initial JSON publication succeeded. Activation of
Manager `v0.11.1` failed authenticated request verification and was rolled back
to `v0.10.0` with the legacy provider. Manager FPM/web health checks passed after
rollback. The Quiz API remains on `v0.7.0` and was not restarted.

PostgreSQL retains 433 canonical questions with current and published revisions
both at `1`. The source and database checksums matched before activation.
No quiz CRUD verification or player-session restart verification was performed
on the failed release. Production cutover remains pending the `v0.11.2` factory
wiring correction described below.

Published releases:

- PR #40: `2bd4b2b58657eea75ade4cfa8d51c1ed2fd5d938`, tag `manager/v0.11.0`,
  [successful workflow](https://github.com/robmoraes/quick-quiz/actions/runs/35442483604).
- PR #41: `e91c18a604494b1ce0332584c9a2b59e29efa135`, tag `manager/v0.11.1`,
  [successful workflow](https://github.com/robmoraes/quick-quiz/actions/runs/35443690918).

Both releases published Manager FPM and web images for `linux/amd64` and
`linux/arm64`. Do not reactivate either release; use the corrected version.

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
- Latest staging: `/opt/quickquiz/releases/manager-v0.11.1-20260919`.

The refreshed dump SHA-256 is
`790d2aaee8e83120eb9ce186f6a791555675ec0714dced1e845dcfc248b329d8`.
It contains the additive quiz schema before content import. Its Compose backup
was restored for the application rollback. The latest staging `.env` still
selects `v0.11.1` and `postgres`; it is **not** the active configuration under
`/opt/quickquiz/compose`, which selects `v0.10.0` and `legacy`.

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
logical catalog. Reports in the latest staging directory are
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
`comparison-after-rollback.json` in the latest staging directory.

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
`benchmark-dev.json`, and `benchmark-dslab.json` in the latest staging directory.
These isolated checks did not change the running Manager.

## Resume

Use the live Compose secret override for all remote operations:
`docker compose --env-file .env -f docker-compose.yml -f compose.secrets.yml`.

1. Merge the factory correction, publish `manager/v0.11.2`, and wait for both
   multiarch images. Stage the new version separately from the failed release.
2. Verify authenticated administrative requests and page rendering using the
   stock release image in an isolated container before activation. Container
   health and CLI commands alone do not validate controller dependencies.
3. Pause editing, refresh database/S3 backups, and compare the current S3 source
   with PostgreSQL again. Legacy editing may have changed S3 since rollback.
   An identical import is a no-op; reconcile any difference during this
   controlled window before explicitly using `--apply --replace` if needed.
4. Follow the [cutover runbook](../manager/postgresql-quiz-cutover.md). Switch
   Manager to `postgres` only after full comparison succeeds; verify the live
   authenticated UI, administrative API, reversible CRUD, and publication.
5. Compare again after removing verification content. Create a disposable
   player session, restart only the Quiz API after successful publication, and
   confirm the same run/question remains available before resetting that
   verification session. Record final image references and active revisions.
