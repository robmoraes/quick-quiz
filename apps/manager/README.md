# QuickQuiz Manager

Symfony webapp for authoring QuickQuiz content in PostgreSQL or legacy JSON storage.

The quiz pack contract is documented at `../../docs/quiz-pack-contract.md`.

Monorepo documentation:

- [Documentation index](../../docs/README.md)
- [Manager documentation](../../docs/manager/README.md)
- [Quiz administration API](../../docs/manager/admin-api.md)
- [Data documentation](../../docs/data/README.md)

## Development

The manager development environment uses Docker and Docker Compose. PHP,
Composer, Symfony commands, tests, and Redis run inside containers. Redis stores
Manager login sessions and is not installed on the host.

```sh
cd apps/manager
cp .env-example .env
docker compose run --rm manager composer install
docker compose run --rm manager php bin/console manager:admin:create admin@example.com change-me-123
docker compose up manager
```

Open:

```text
http://localhost:8081
```

Session storage is configured with `MANAGER_SESSION_REDIS_DSN`,
`MANAGER_SESSION_TTL`, and `MANAGER_SESSION_PREFIX`. The Compose defaults use
Redis database 1 so Manager sessions remain separate from Quiz API run data.
Admin accounts and AI prompts use PostgreSQL through `MANAGER_DATABASE_URL`.
The Compose service stores database files in the `manager-db-data` volume.

Operational settings are read from the container environment. Database, Redis,
content storage, internal service URLs, HTTP timeouts, trusted proxies, OpenAI,
locale, version, and PHP-FPM capacity can be changed by recreating the container;
they do not require rebuilding the image. The PHP and Nginx versions remain
build-time image dependencies. See `.env-example` for the complete variable set.

Sensitive values support `NAME__FILE`. When both forms are present, the file
wins. Missing, unreadable, or empty files stop Manager startup. The development
Compose mounts `${MANAGER_SECRETS_ROOT:-.secrets}` read-only at `/run/secrets`.

## Runtime security

The PHP base is maintained and published manually; Manager CI reuses that image
and audits both supported architectures. Follow the
[security procedure](../../docs/runbooks/manager-runtime-security.md) for local
acceptance, dependency/image audits and release prerequisites. Application login
and bearer tokens remain required alongside infrastructure-owned access controls.

## Commands

```sh
docker compose run --rm manager php bin/console manager:admin:create admin@example.com change-me-123
docker compose run --rm manager composer test
```

## Content

The storage backend is selected with `MANAGER_CONTENT_STORAGE_PROVIDER`. Local Compose defaults to `local`, mounts `../api/.local` at `/content`, and sets:

```text
MANAGER_CONTENT_STORAGE_PROVIDER=local
MANAGER_CONTENT_ROOT=/content
```

For S3-compatible storage, set the provider to `s3` and configure `AWS_REGION`, `S3_BUCKET`, `S3_PREFIX`, `S3_ENDPOINT_URL`, and `S3_FORCE_PATH_STYLE`. AWS credentials come from the standard SDK credential chain, including environment credentials or an instance/task role.

Question files are saved as JSON with only:

- `prompt`
- `correctOptions`
- `wrongOptions`

Themes are managed through:

```text
/content/themes.json
/content/<theme>/index.json
/content/<theme>/<locale>/index.json
/content/<theme>/<locale>/<topic>/<difficulty>/<question-id>.json
/content/<theme>/ai-prompts/question-solution-prompt.txt
```

After login, select a theme before managing catalog topics or questions. Inactive
themes remain editable in the manager but are not served by the player API.

In `content_storage` publication mode, the `question_solution` AI prompt is stored in the Manager database and exported through the configured content backend as `<theme>/ai-prompts/question-solution-prompt.txt` when it is saved, restored, or imported from JSON. The Manager, Quiz API, and Ads API must use the same bucket and prefix when S3 is enabled.

## PostgreSQL runtime publication

Set `MANAGER_QUIZ_PERSISTENCE_PROVIDER=postgres` for database authoring.
`MANAGER_QUIZ_PUBLICATION_PROVIDER=content_storage` remains the default, preserving
local/S3 publication. After an explicit verified backend transition, select
`MANAGER_QUIZ_PUBLICATION_PROVIDER=postgres`: each validated quiz mutation and its
published revision commit together. New API operations see the commit without a
restart. Inactive themes/topics remain editable but are excluded from runtime
views. Prompt saves/restores/imports use the database directly in this mode.
Quiz CRUD/login do not need a question mount or an S3 bucket; Ads and explicit
legacy exports retain their own storage dependency.

Operator commands (run inside the Manager container):

```sh
php bin/console manager:database:migrate
php bin/console manager:quiz:provision-reader --role=quickquiz_runtime --dry-run
# Supply QUIZ_DATABASE_READER_PASSWORD or its __FILE variant before --apply.
php bin/console manager:quiz:provision-reader --role=quickquiz_runtime --apply

php bin/console manager:quiz:compare --expected-revision=R \
  --legacy-provider-runs=0 --report=/tmp/quiz-comparison.json
php bin/console manager:quiz:runtime-backend --provider=postgres \
  --expected-revision=R --verification-report=/tmp/quiz-comparison.json --dry-run
# Stop old writers and freeze quiz, prompt and tag writes before applying.
php bin/console manager:quiz:runtime-backend --provider=postgres \
  --expected-revision=R --verification-report=/tmp/quiz-comparison.json \
  --apply --writes-frozen
```

Replace `R` with the actual equal current/published revision. The comparison uses
configured legacy content storage, streams question packages, and includes
inactive authoring content and solution instructions. `--legacy-provider-runs=0`
is an explicit operator attestation from the API's real compatibility metric;
it is never inferred by Manager. Pending legacy revisions must be reconciled
explicitly with existing publication/recovery procedures. Coordinate Manager/API
configuration and verify the candidate before routing traffic or unfreezing.
Neither application startup nor a database outage switches providers.

Provisioning requires an operator database credential with role/grant privileges,
not the API credential. It creates a dedicated login with SELECT on the eight
`quiz_runtime_v1` views, no base-table grants, ownership or role memberships.
It explicitly revokes database TEMP from PUBLIC on this dedicated application
database; the database owner retains its privileges. API credentials use a
separate `QUIZ_DATABASE_URL` secret. Passwords do not appear in CLI reports.

Prefer application rollback to a PostgreSQL-compatible image. Deliberate storage
fallback first requires a complete fresh export of the latest authored revision
and a version-2-compatible API reader; an old S3 prefix is unsafe:

```sh
php bin/console manager:quiz:export --format=legacy-json --target=local \
  --directory=/tmp/fresh-quiz-export --expected-revision=R --dry-run
php bin/console manager:quiz:export --format=legacy-json --target=local \
  --directory=/tmp/fresh-quiz-export --expected-revision=R --apply \
  --snapshot-reader-version=2 --report=/tmp/quiz-export.json
php bin/console manager:quiz:runtime-backend --provider=content_storage \
  --expected-revision=R --verification-report=/tmp/quiz-export.json --dry-run
# Keep the write freeze through provider/routing coordination and verification.
php bin/console manager:quiz:runtime-backend --provider=content_storage \
  --expected-revision=R --verification-report=/tmp/quiz-export.json \
  --apply --writes-frozen
```

For S3 export replace local target/directory with `--target=s3 --bucket=NAME
--prefix=isolated/new/prefix`; AWS region, endpoint and path-style settings use
the existing environment. Export never alters catalog/publication revisions.
Every applied object is read back and the exact target inventory is checked.
Reports include counts, locale parity and a logical checksum. Keep reports
outside the export target. A failed export remains incomplete; retry into a fresh
target. Backend commands recheck revision, source checksum and target contents;
they never accept a dry-run export as fallback evidence. All authoring, prompt
and tag writes must stay frozen from the final export through the switch.
