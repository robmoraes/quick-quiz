# Manager PostgreSQL migration record

## State on 2026-09-19

PR #40 was merged as `2bd4b2b58657eea75ade4cfa8d51c1ed2fd5d938`.
Tag `manager/v0.11.0` triggered the successful
[release workflow](https://github.com/robmoraes/quick-quiz/actions/runs/35442483604).
Both Manager FPM and web images were published for `linux/amd64` and
`linux/arm64` and pulled onto the EC2 instance.

The production cutover is pending. Manager FPM/web remain on `v0.10.0`, and
the Quiz API remains on `v0.7.0`. Their health checks passed after preflight.
The additive migration `0001_quiz_authoring_schema.sql` was applied; quiz
content tables remain empty and both catalog revisions remain zero. The two
orphan demo files were archived with explicit operator approval as recorded
below. The API was not restarted.

## Backup and staged files

- Server: `i-043c1e8324ef79e4c`, `34.207.253.17`.
- Backup directory: `/opt/quickquiz/backups/manager-v0.11.0-20260919-CPcWFf`.
- Validated database dump: `manager-before.dump`; its `pg_restore --list`
  output is stored as `dump-manifest.txt`.
- Prior Compose configuration: `compose/`; image references: `manager-images.txt`.
- S3 version manifest: `s3-versions-before.json`; bucket versioning is enabled.
- Archive/rollback version manifest: `orphan-archive-manifest.json`.
- Staged release: `/opt/quickquiz/releases/manager-v0.11.0-20260919`.

The initial S3 manifest contains 877 current objects totaling 645,909 bytes.
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
in the staged release as `import-dry-run-after-archive.json`.

The source checksum is
`33e8c21173d05e1ac817e33ff6a4cec1d04cc33faad90c9ddf7e3ab655cd6c54`.
`applied=false` confirms that this did not import data. `comparison.equal=false`
is expected because the PostgreSQL quiz tables are still empty; the full
post-import comparison remains required before cutover.

## Resume

1. Merge the importer compatibility fix and publish `manager/v0.11.1`.
2. Update the staged Manager image references and version to `0.11.1`.
3. Refresh the database backup and S3 version manifest after pausing editing.
4. Follow the [cutover runbook](../manager/postgresql-quiz-cutover.md), starting
   with a complete dry run, import, and projection comparison.
5. Switch the Manager provider to `postgres` only after those checks pass;
   publish and restart only the Quiz API after successful verification.
