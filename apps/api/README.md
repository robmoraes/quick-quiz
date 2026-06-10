# QuickQuiz Dev API

Go API for QuickQuiz catalogs, runs, answers, results, themes, and session
availability.

Monorepo documentation:

- [Documentation index](../../docs/README.md)
- [API documentation](../../docs/api/README.md)
- [Quiz pack contract](../../docs/quiz-pack-contract.md)

## Running locally

```sh
go run ./cmd/api
```

## Building

Build the API binary from apps/api:

```sh
mkdir -p bin
go build -o bin/quickquiz-api ./cmd/api
```

Run the built binary:

```sh
./bin/quickquiz-api
```

## Distribution

Docker distribution files:

- [Docker distribution package](deploy/docker/README.md)
- [systemd distribution package](deploy/systemd/README.md)

Deployment guides:

- [Install on a server with systemd and Nginx](docs/install-systemd-nginx.md)
- [Install with Docker from Docker Hub](docs/install-dockerhub.md)

Environment variables:

- `HTTP_ADDR`: HTTP server address. Default: `:8080`.
- `RUN_QUESTION_LIMIT`: fixed maximum number of questions per run. Default: `10`.
- `QUESTION_STORAGE_PROVIDER`: question storage backend, `local` or `s3`. Default: `local`.
- `QUESTION_SOURCE`: local content root with `themes.json` and theme folders. Default: `.local`.
- `FALLBACK_LOCALE`: fallback BCP 47 content locale. Default: `en-US`.
- `SUPPORTED_LOCALES`: comma-separated supported BCP 47 locales. Default: `en-US,pt-BR`.
- `SESSION_TTL`: inactive run lifetime. Default: `30m`.
- `SHUTDOWN_TIMEOUT`: graceful shutdown timeout. Default: `10s`.
- `AWS_REGION`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`: AWS credentials/config for S3.
- `S3_BUCKET`, `S3_PREFIX`, `S3_ENDPOINT_URL`, `S3_FORCE_PATH_STYLE`: S3 question storage settings.

## Endpoints

- `GET /healthz`
- `GET /api/catalog`
- `POST /api/session/reset`
- `POST /api/runs`
- `POST /api/runs/{runId}/answers`
- `POST /api/runs/{runId}/finish`
- `GET /api/runs/{runId}/result`

The API keeps business rules outside the HTTP layer and can later replace local question loading with S3 loading.

For local development, use `.local/themes.json` to publish themes, `.local/<theme>/index.json` to publish topics for a theme, and `.local/<theme>/<locale>/<topic>/<difficulty>/<question-id>.json` for question files. Example: `.local/dev/en-US/php/1/php-1-001.json`. This folder is ignored by Git.

Question JSON files contain only `prompt`, `correctOptions`, and `wrongOptions`. The loader derives `theme`, `id`, `locale`, `topic`, and `difficulty` from the path, and only loads active themes from `themes.json` and active topics listed in the theme `index.json`.

## Localization

Question content is scoped by the required `X-QuickQuiz-Theme` header and selected by BCP 47 `locale`. API precedence is explicit `locale`, `X-QuickQuiz-Locale`, `Accept-Language`, then `FALLBACK_LOCALE`. Keep machine-readable codes, enum values, logs, and metrics stable across locales.
