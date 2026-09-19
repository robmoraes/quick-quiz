# Manager topic tags

Manager 0.12.0 adds reusable topic tags when
`MANAGER_QUIZ_PERSISTENCE_PROVIDER=postgres`. On the topic form, enter tags
separated by commas (`aws, redes, seguranca`); clear the field to remove them.
Tags appear in the topic list and are global across locales. The administrative
API exposes the same data; see [its usage guide](admin-api.md#topic-tags-postgresql).

PostgreSQL tables `quiz_tags` and `quiz_topic_tags` own this data. The existing
database backup must include both tables. Tags are absent from published JSON,
S3 backups, the Quiz API, and the SPAs. No new service, secret, or environment
variable is required.

## Manager-only upgrade

The initial deployment completed on 2026-09-19; see the
[production release record](../runbooks/manager-topic-tags-release.md). For future
upgrades, use the deployed Compose files and secret overlay already in use. Keep `MANAGER_QUIZ_PERSISTENCE_PROVIDER=postgres`.

1. Back up PostgreSQL and retain the current Manager image tags, following the
   [database backup instructions](postgresql-quiz-cutover.md#prepare-and-back-up).
2. After the Manager release is published, set both Manager image variables to
   `v0.12.0` and `MANAGER_VERSION=0.12.0` in the deployment environment.
3. Pull the images and run migrations in a one-off **new-image** container before
   recreating the running Manager. For the deployed Compose directory:

   ```sh
   docker compose --env-file .env pull manager-fpm manager-web
   docker compose --env-file .env run --rm --no-deps manager-fpm php bin/console manager:database:migrate
   docker compose --env-file .env up -d --no-deps manager-fpm manager-web
   ```

   Include the same `-f` files/secret overlay as the existing deployment, if they
   are not already selected by `COMPOSE_FILE`. The runner applies
   `0002_topic_tags.sql` once; subsequent runs are no-ops. No content re-import,
   S3 rewrite, or Quiz API restart is needed for this migration.
4. Verify topic reads and a reversible tag edit through the Manager and the
   administrative API. A tags-only save returns `topic_tags_only`; publication
   revisions/checksum remain unchanged, including any previous failure warning.

Application rollback restores the previous Manager images/version and retains
the additive tables. That Manager cannot edit tags. Avoid replacement imports
from a pre-tags Manager: its delete/reinsert behavior loses associations.

## Recovery and imports

Restore a PostgreSQL backup to recover tags; JSON alone cannot recover them.
With Manager 0.12.0+, an identical import is a no-op. An explicit replacement
import retains associations only for surviving `(theme_id, topic_key)` pairs;
new topics start without tags and deleted topics lose their associations.
Normal topic/theme deletion cascades associations, but leaves shared tag
identities available to other topics. Orphan dictionary pruning is outside
this feature.
