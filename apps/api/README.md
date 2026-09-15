# QuickQuiz Dev API

Go API for QuickQuiz catalogs, runs, answers, results, themes, solutions, and
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

Environment variables may be loaded from files by defining `NAME__FILE`.
The file value takes priority over `NAME`; missing, unreadable, or empty files
stop startup. Trailing CR/LF characters are removed.

- `HTTP_ADDR`: HTTP server address. Default: `:8080`.
- `LOG_LEVEL`: structured log threshold: `debug`, `info`, `warn`, or `error`. Default: `info`.
- `CORS_ALLOWED_ORIGINS`: comma-separated browser origins or `*`. Default: `*`.
- `HTTP_READ_HEADER_TIMEOUT`, `HTTP_READ_TIMEOUT`, `HTTP_WRITE_TIMEOUT`, `HTTP_IDLE_TIMEOUT`: HTTP server timeouts. Defaults: `5s`, `15s`, `15s`, and `60s`.
- `STORAGE_STARTUP_TIMEOUT`: startup deadline for loading S3-backed content. Default: `30s`.
- `RUN_QUESTION_LIMIT`: fixed maximum number of questions per run. Default: `10`.
- `QUESTION_STORAGE_PROVIDER`: question storage backend, `local` or `s3`. Default: `local`.
- `QUESTION_SOURCE`: local content root with `themes.json` and theme folders. Default: `.local`.
- `FALLBACK_LOCALE`: fallback BCP 47 content locale. Default: `en-US`.
- `SUPPORTED_LOCALES`: comma-separated supported BCP 47 locales. Default: `en-US,pt-BR`.
- `SESSION_TTL`: inactive run lifetime. Default: `30m`.
- `RUN_STORAGE_PROVIDER`: run/session storage backend, `memory` or `redis`. Default: `memory`.
- `SOLUTION_STORAGE_PROVIDER`: generated-solution storage backend, `local`, `memory`, or `redis`. Default: `local`.
- `SOLUTION_TTL`: Redis lifetime for generated solutions. Default: `168h`.
- `REDIS_ADDR`, `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_DB`, `REDIS_TLS`: Redis connection settings.
- `REDIS_CONNECT_TIMEOUT`: Redis startup connection deadline. Default: `5s`.
- `REDIS_KEY_PREFIX`, `REDIS_SOLUTION_KEY_PREFIX`: separate Redis namespaces for runs and generated solutions.
- `SHUTDOWN_TIMEOUT`: graceful shutdown timeout. Default: `10s`.
- `OPENAI_API_KEY`: OpenAI API key used only when generating a missing question solution.
- `OPENAI_BASE_URL`: OpenAI API base URL. Default: `https://api.openai.com/v1`.
- `OPENAI_MODEL`: OpenAI model used to generate question solutions. Default: `gpt-5.4-mini`.
- `OPENAI_ORGANIZATION`, `OPENAI_PROJECT`: optional OpenAI organization/project headers.
- `OPENAI_SOLUTION_PROMPT_FILE`: local prompt path used with `QUESTION_STORAGE_PROVIDER=local`. The path may include `{{theme}}`. Default: `.local/{{theme}}/ai-prompts/question-solution-prompt.txt`.
- `OPENAI_TIMEOUT`: OpenAI request timeout. Default: `30s`.
- `AWS_REGION`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`: AWS credentials/config for S3.
- `S3_BUCKET`, `S3_PREFIX`, `S3_ENDPOINT_URL`, `S3_FORCE_PATH_STYLE`: S3 question catalog settings. `S3_BUCKET` is required for the `s3` provider.

## Endpoints

- `GET /healthz`
- `GET /api/catalog`
- `POST /api/session/reset`
- `POST /api/runs`
- `POST /api/runs/{runId}/answers`
- `POST /api/runs/{runId}/finish`
- `GET /api/runs/{runId}/state`
- `GET /api/runs/{runId}/result`
- `GET /api/runs/{runId}/questions/{questionId}/solution`

Run and session state can be stored in process memory or Redis. The repository-wide local Docker Compose uses Redis by default; direct `go run` execution keeps the `memory` default. Each Redis run key expires according to `SESSION_TTL`.

The API selects local or S3 question loading at startup. The S3 provider reads the same object layout below under `S3_PREFIX`; `local` remains the default and requires no AWS access.

For local development, use `.local/themes.json` to publish themes, `.local/<theme>/index.json` to publish topics for a theme, and `.local/<theme>/<locale>/<topic>/<difficulty>/<question-id>.json` for question files. Example: `.local/dev/en-US/php/1/php-1-001.json`. This folder is ignored by Git.

Question JSON files contain only `prompt`, `correctOptions`, and `wrongOptions`. The loader derives `theme`, `id`, `locale`, `topic`, and `difficulty` from the path, and only loads active themes from `themes.json` and active topics listed in the theme `index.json`.

The S3 provider covers the read-only question catalog. Generated question solutions use `SOLUTION_STORAGE_PROVIDER`: `local` stores derived artifacts under `.local/<theme>/.solutions/<locale>/<topic>/<difficulty>/<question-id>.json`, `memory` keeps them for the API process lifetime, and `redis` stores them under `REDIS_SOLUTION_KEY_PREFIX` for `SOLUTION_TTL`. The Docker Compose profiles use Redis, so generated solutions survive API restarts without creating API filesystem state. Losing the cache only causes a missing solution to be generated again. A solution can only be requested for a question that was answered incorrectly in the requested run.

The solution-generation prompt follows the question content backend. The local provider reads `OPENAI_SOLUTION_PROMPT_FILE`; the S3 provider reads `<S3_PREFIX>/<theme>/ai-prompts/question-solution-prompt.txt` from `S3_BUCKET` on demand. The Manager writes the same object when the `question_solution` AI prompt is saved, restored, or imported. If no custom prompt can be loaded, the generator uses its built-in prompt.

## Localization

Question content is scoped by the required `X-QuickQuiz-Theme` header and selected by BCP 47 `locale`. API precedence is explicit `locale`, `X-QuickQuiz-Locale`, `Accept-Language`, then `FALLBACK_LOCALE`. Keep machine-readable codes, enum values, logs, and metrics stable across locales.
