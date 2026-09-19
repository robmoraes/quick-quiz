# Manager 0.12.0 production release

## Deployment on 2026-09-19

Manager FPM and Web now run `v0.12.0` on `quickquiz-beta-ec2`
(`i-043c1e8324ef79e4c`, `34.207.253.17`). The persistence provider remains
`postgres`, and published quiz content remains in S3. Topic tags are available
in Manager Web and the administrative API. The unauthenticated login page no
longer displays or discovers AI models; authenticated pages retain the selector.

- Source: PR #44, commit `ef1e0dfb928e25d28b7378171209c73beebc4251`.
- Release tag: `manager/v0.12.0`.
- [Successful release workflow](https://github.com/robmoraes/quick-quiz/actions/runs/35460673387).
- Both images include `linux/amd64` and `linux/arm64`; production uses amd64.
- Previous Manager release: `v0.11.2`.

Published image index digests, also verified on the server:

```text
robmoraes/quick-quiz-manager-fpm:v0.12.0
sha256:68bfad57aa19af74e018b1a0ee27c5ab9a822ad1965fc6fcfd201d6442d0cad4
robmoraes/quick-quiz-manager-web:v0.12.0
sha256:0bbbe789573658626e4957cf6064d811256acc2674e96ad82817a9346d231dfe
```

## Migration and activation

The new images were pulled after the release workflow succeeded. A protected
PostgreSQL custom-format dump was created and validated with `pg_restore --list`.
The current Compose configuration, service identities, and start timestamps were
saved. The staged `.env` changes only the two Manager image references and
`MANAGER_VERSION=0.12.0`.

The published FPM image applied `0002_topic_tags.sql` in a one-off container
before activation, while the previous Manager stayed available. This additive
migration creates `quiz_tags` and `quiz_topic_tags`; migration 0001 remains
unchanged. Migration 0002's SHA-256 is:

```text
0977eda304384eb0d9c423f742de0e4dbd5c0b325ac5828b1f2a2b041813d442
```

Authenticated theme/catalog/topic rendering and anonymous login passed in the
new image before switching. The staged environment was then promoted and only
`manager-fpm` and `manager-web` were recreated with Compose's `--no-deps --wait`.
The activation script retained an automatic application rollback on failure;
it was not needed.

All remote Compose operations used:

```sh
cd /opt/quickquiz/compose
docker compose --env-file .env -f docker-compose.yml -f compose.secrets.yml
```

## Verification

The release source passed 198 local PHPUnit tests and 752 assertions with
PostgreSQL. Production checks confirmed:

- Anonymous administrative requests return HTTP 401; authenticated requests work.
- Public HTTPS login returns HTTP 200, shows version 0.12.0, and has no AI selector.
- A temporary tag was saved, read back in another locale, and removed through
  the administrative API. Both writes returned `topic_tags_only` and
  `apiReloadRequired=false`. The original associations were restored and the
  unused synthetic tag identity was removed.
- Both tag tables are empty after verification, as they were just introduced.
- Catalog state and publication records are exactly unchanged: current and
  published revisions remain **8**.
- S3 metadata for all **875** objects is identical before and after the rollout
  (keys, ETags, sizes, and modification timestamps).
- The catalog still contains 2 themes, 34 topics, 433 canonical questions,
  866 question translations, and 9,012 answers.
- Container IDs, start timestamps, and restart counters confirm that only the
  two Manager containers changed. Quiz API, Ads API, PostgreSQL, Redis, both
  SPAs, and Traefik were not restarted.
- Public health endpoints for Manager, Quiz API, Ads API, and both SPAs returned
  HTTP 200. Recent Manager logs contained no PHP fatal, uncaught-exception,
  permission-denied, or SQLSTATE errors.

Authenticated renders in the active FPM container returned HTTP 200:

| Page | Server time |
| --- | ---: |
| `/themes` | 217.84 ms |
| `/catalog` | 192.94 ms |
| Existing topic form | 14.26 ms |

These measurements use an in-memory authenticated verification session and a
cached model list; they exclude browser latency. The node had approximately
1,188 MiB available RAM and 2.9 GiB free disk after verification. This is an
idle observation, not a capacity test.

## Backups and evidence

Protected server backup directory:

```text
/opt/quickquiz/backups/manager-v0.12.0-20260919-8DN7Rk
```

Both dumps are nonempty and passed `pg_restore --list`:

```text
manager-before.dump
ec17a66bee9dfd055f5d21fffcab6e56e9e0d6f49f2db0c7925dd2b962dc0590
manager-after.dump
b818d3555b9829b8192e57a7ba2e75dd4c6c116d1a07a891d00d3eacb047a73a
```

The directory also contains dump manifests/checksums, `compose/` before the
release, `compose-after/`, `database-before.txt`, `runtime-before.json`, and
`services-before.txt`. Database dumps and secret-bearing configuration remain
on the server; no secret or question content was exported to this repository.

Release staging and verification evidence:

```text
/opt/quickquiz/releases/manager-v0.12.0-20260919
```

Relevant files:

- `backup-path.txt`, `migration.txt`;
- `catalog-before.json`, `catalog-after.json`, `rollout-result.json`;
- `image-ui-verification.json`, `live-ui-verification.json`, `https-verification.json`;
- `tag-cleanup.json`, `database-after.txt`, `image-digests.txt`;
- `runtime-after.json`, `services-after.txt`;
- staging Compose files and scripts used for verification/activation.

## Rollback and recovery

For rollback of this deployment, restore the prior Manager image references
(`v0.11.2`) and `MANAGER_VERSION=0.11.2` from the saved environment, retain
`MANAGER_QUIZ_PERSISTENCE_PROVIDER=postgres`, and recreate only Manager FPM/Web
with the existing secret overlay. Retain migration 0002 and its data; the old
application does not expose tags. Do not use replacement imports from a
pre-tags Manager because they delete/reinsert topic associations.

Tags require a PostgreSQL backup for recovery; S3 JSON does not contain them.
For another node, restore the latest PostgreSQL backup, use the image versions
in this record, and follow the
[infrastructure recovery instructions](production-stateless-migration.md) and
[PostgreSQL authoring recovery guidance](manager-postgresql-migration.md#recovery-and-future-deployments).
Run database migrations before starting Manager. No content re-import or Quiz
API reload was required for this release.
