# QuickQuiz Dev API

Go API for QuickQuiz catalogs, ads, runs, answers, results, themes, and
session availability.

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
- `OPENAI_API_KEY`: OpenAI API key used only when generating a missing question solution.
- `OPENAI_BASE_URL`: OpenAI API base URL. Default: `https://api.openai.com/v1`.
- `OPENAI_MODEL`: OpenAI model used to generate question solutions. Default: `gpt-5.4-mini`.
- `OPENAI_ORGANIZATION`, `OPENAI_PROJECT`: optional OpenAI organization/project headers.
- `OPENAI_SOLUTION_PROMPT_FILE`: optional file path for the solution-generation prompt. The path may include `{{theme}}`. Default: `.local/{{theme}}/ai-prompts/question-solution-prompt.txt`.
- `OPENAI_TIMEOUT`: OpenAI request timeout. Default: `30s`.
- `AWS_REGION`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`: AWS credentials/config for S3.
- `S3_BUCKET`, `S3_PREFIX`, `S3_ENDPOINT_URL`, `S3_FORCE_PATH_STYLE`: S3 question storage settings.

## Endpoints

- `GET /healthz`
- `GET /api/catalog`
- `GET /api/ads?limit=3&topic=php&emphasis=2`
- `POST /api/session/reset`
- `POST /api/runs`
- `POST /api/runs/{runId}/answers`
- `POST /api/runs/{runId}/finish`
- `GET /api/runs/{runId}/result`
- `GET /api/runs/{runId}/questions/{questionId}/solution`

The API keeps business rules outside the HTTP layer and can later replace local question loading with S3 loading.

For local development, use `.local/themes.json` to publish themes, `.local/<theme>/index.json` to publish topics for a theme, and `.local/<theme>/<locale>/<topic>/<difficulty>/<question-id>.json` for question files. Example: `.local/dev/en-US/php/1/php-1-001.json`. This folder is ignored by Git.

Question JSON files contain only `prompt`, `correctOptions`, and `wrongOptions`. The loader derives `theme`, `id`, `locale`, `topic`, and `difficulty` from the path, and only loads active themes from `themes.json` and active topics listed in the theme `index.json`.

Generated question solutions are stored as derived local artifacts under `.local/<theme>/.solutions/<locale>/<topic>/<difficulty>/<question-id>.json`. A solution can only be requested for a question that was answered incorrectly in the requested run. When a solution is requested for the first time, the API generates and persists solutions for all supported locales that have the question package. Later requests read the stored solution unless the question content hash changes.

The solution-generation prompt is expected at `.local/<theme>/ai-prompts/question-solution-prompt.txt` by default. The manager writes this file when the `question_solution` AI prompt is saved, restored, or imported.

## Localization

Question content is scoped by the required `X-QuickQuiz-Theme` header and selected by BCP 47 `locale`. API precedence is explicit `locale`, `X-QuickQuiz-Locale`, `Accept-Language`, then `FALLBACK_LOCALE`. Keep machine-readable codes, enum values, logs, and metrics stable across locales.
