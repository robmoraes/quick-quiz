# QuickQuiz Manager

Symfony webapp for editing and validating QuickQuiz Dev quiz pack JSON files in local or S3-compatible storage.

The quiz pack contract is documented at `../../docs/quiz-pack-contract.md`.

Monorepo documentation:

- [Documentation index](../../docs/README.md)
- [Manager documentation](../../docs/manager/README.md)
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

The `question_solution` AI prompt is stored in the Manager database and exported through the configured content backend as `<theme>/ai-prompts/question-solution-prompt.txt` when it is saved, restored, or imported from JSON. The Manager, Quiz API, and Ads API must use the same bucket and prefix when S3 is enabled.
