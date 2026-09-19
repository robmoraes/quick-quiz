# Manager quiz authoring: PostgreSQL cutover

PostgreSQL becomes the source for Manager quiz authoring. The Manager publishes
JSON to the existing content backend; the Quiz API still loads that JSON into
memory at startup. Administrators, AI prompts, sessions, ads, and player state
are outside this migration.

The application defaults to `MANAGER_QUIZ_PERSISTENCE_PROVIDER=legacy`. Keep
that value until the source catalog has been backed up, imported, and compared.
Use `postgres` only after the checks below succeed. Both values are runtime
configuration; changing them does not rebuild the image.

## Prepare and back up

Pause quiz editing for the import and cutover window. Retain the current Manager
image tag and S3 object versions. Confirm S3 bucket versioning is enabled.
Run database backup from the deployed Compose directory:

```sh
cd /opt/quickquiz/compose
docker compose --env-file .env exec -T manager-db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > manager-before-quiz.dump
```

Store the dump securely outside the container and verify it is nonempty. Do
not commit the dump, database URL, administrative token, or quiz content.

## Migrate and compare while legacy remains active

Deploy the compatible Manager image with the provider still set to `legacy`,
then run:

```sh
docker compose --env-file .env exec manager-fpm php bin/console manager:database:migrate
docker compose --env-file .env exec manager-fpm php bin/console manager:quiz:import --dry-run
docker compose --env-file .env exec manager-fpm php bin/console manager:quiz:import --apply
docker compose --env-file .env exec manager-fpm php bin/console manager:quiz:compare
```

The importer validates the complete JSON catalog before writing and reports
aggregate counts without prompts or answers. It ignores `ads/ads.json` and
`<theme>/ai-prompts/*`. Identical re-imports are no-ops. A conflicting import
fails unless the operator explicitly runs `--apply --replace`; replacement is
intended only during a controlled migration window. `manager:quiz:compare`
exits successfully only when source JSON and the database projection match
logically, including localized answer order. Keep the provider on `legacy` if
validation or comparison fails.

## Switch and verify

Set `MANAGER_QUIZ_PERSISTENCE_PROVIDER=postgres` in `.env`, then recreate the
Manager FPM container:

```sh
docker compose --env-file .env up -d --no-deps --force-recreate manager-fpm
docker compose --env-file .env ps manager-fpm manager-db
```

Verify authenticated theme, catalog, topic, question, and stats pages. Verify
`GET /api/admin/quiz/publication` with the existing administrative Bearer
token. PostgreSQL navigation should make no S3 reads. Make a reversible CRUD
change, confirm its response has `publication.status=published` and
`apiReloadRequired=true`, then compare again. Measure server-side navigation
time against the 500 ms single-user target. For a controlled PostgreSQL read
measurement, run:

```sh
docker compose --env-file .env exec manager-fpm php bin/console manager:quiz:benchmark --theme=dev --locale=pt-BR --iterations=10
```

The benchmark reports only aggregate counts and timing, never question text.

After a successful publication, restart only the Quiz API to load the new JSON
snapshot. Redis-backed player sessions remain external to the API process:

```sh
docker compose --env-file .env restart api
docker compose --env-file .env ps api manager-fpm manager-db
```

## Failed publication and rollback

A failed publication returns HTTP 503 with `publication_failed`, leaves the
committed database revision marked `failed`, and does not claim it is playable.
The Manager warning displays current and published revisions. After fixing the
storage problem, retry from the warning, `POST /api/admin/quiz/publication`, or:

```sh
docker compose --env-file .env exec manager-fpm php bin/console manager:quiz:publish
# Optional when the pending revision changes exactly one theme:
docker compose --env-file .env exec manager-fpm php bin/console manager:quiz:publish --theme=dev
```

Only restart the Quiz API after publication succeeds. The publisher restores
previous objects if a storage operation fails; retain S3 versions for recovery
if compensation itself fails.

For application rollback, set the provider back to `legacy`, restore the prior
Manager image tag if necessary, and recreate `manager-fpm`. Do not reverse the
schema migration. Legacy reads the retained JSON publication, and the Quiz API
can stay on its current image. If the latest database revision was unpublished,
legacy will show the previous published content until publication is retried.
