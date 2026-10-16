# Generic Quiz Web: local operation

Scope: local-only implementation of [spec 007](../specs/007-generic-quiz-web/spec.md).
Existing SPA Dev/DSLab and production routes remain separate. No Web release
tag, registry publication, DNS or production capacity decision is included.

## Prerequisite: PostgreSQL quiz runtime

The five public `/api/discovery` endpoints require PostgreSQL. Local/S3 question
providers keep their existing gameplay behavior and return
`503 discovery_unavailable` for discovery.

Back up a persistent local database before any migration. Run the Manager
migration command using its writer/operator credential. Migration `0005` only
adds the active-filtered `quiz_runtime_v1.topic_tags` view; old views and runtime
contract version 1 remain unchanged. Then explicitly reconcile the dedicated
reader with the updated `manager:quiz:provision-reader` command, using its
existing password supplied securely. The API never receives the writer DSN.

```sh
dc run --rm --no-deps manager-fpm php bin/console manager:database:migrate
dc run --rm --no-deps -e QUIZ_DATABASE_READER_PASSWORD manager-fpm \
  php bin/console manager:quiz:provision-reader --role=quickquiz_runtime --apply
```

Here `dc` is the operator's Compose wrapper for the selected environment,
including any secret mounts. Provisioning is explicit and emits no credential.
An existing reader does not automatically receive new view permissions. Check
API `/readyz` after deploying the candidate locally.

For a new/disposable environment, initialize the synthetic bank and activate
the PostgreSQL runtime as described in the
[PostgreSQL runbook](postgresql-quiz-runtime.md#disposable-local-rehearsal).
Do not reimport into an existing bank or flush its Redis. If the existing local
preview predates that cutover, use a separate project/database/Redis rehearsal
instead of migrating existing sessions as a side effect of viewing this Web.

## Start Web

Use a protected ignored environment file based on
`deploy/compose.local/.env-example`. Set the reader DSN and match the configured
catalog locales. Set `WEB_PUBLIC_API_BASE_URL` to the browser-reachable API
origin; `WEB_API_BASE_URL` uses Docker DNS. Keep `WEB_INDEXABLE=false` locally.
The existing API CORS setting must allow the Web origin.

```sh
docker compose --env-file deploy/compose.local/.env \
  -f deploy/compose.local/docker-compose.yml \
  -f deploy/compose.local/compose.postgres.yml \
  -f deploy/compose.local/compose.web.yml \
  --profile web up -d --build web
```

Web defaults to `http://localhost:8085/pt-BR/`. It starts the API's selected
dependencies through Compose. Its `/healthz` is process liveness; `/readyz`
checks API readiness and public discovery for each configured locale within
one shared deadline. Failure is 503, never a successful empty catalog.

## Runtime configuration

| Compose variable | Container variable | Default |
| --- | --- | --- |
| `WEB_API_BASE_URL` | `NUXT_API_BASE_URL` | `http://api:8080` |
| `WEB_PUBLIC_API_BASE_URL` | `NUXT_PUBLIC_API_BASE_URL` | `http://localhost:8080` |
| `WEB_SITE_URL` | `NUXT_PUBLIC_SITE_URL` | `http://localhost:8085` |
| `WEB_DEFAULT_LOCALE` | `NUXT_PUBLIC_DEFAULT_LOCALE` | `pt-BR` |
| `WEB_SUPPORTED_LOCALES` | `NUXT_PUBLIC_SUPPORTED_LOCALES` | `en-US,pt-BR` |
| `WEB_INDEXABLE` | `NUXT_PUBLIC_INDEXABLE` | `false` |
| `WEB_API_TIMEOUT_MS` | `NUXT_API_TIMEOUT_MS` | `5000` |

Each table entry supports `NAME__FILE` with priority over `NAME`. The Docker
entrypoint resolves files before Nitro starts, and invalid selected files fail
without falling back or logging values. Mount the files read-only through an
operator Compose overlay. Public settings are delivered to the browser; they
must never contain secrets. URLs must be plain HTTP(S) origins without
credentials, paths, query strings or fragments.

`WEB_HTTP_PORT` and `WEB_MEMORY_LIMIT` configure Compose only. The initial
container limit is 384 MiB with a 256 MiB Node heap ceiling; these are local
guardrails, not evidence that production's existing node has spare capacity.
Changing runtime configuration recreates the container with the same image.

The Dockerfile uses multiarch Node bases, builds platform-independent Nitro
output and runs the final service as the `node` user on a read-only filesystem.
Manual local build:

```sh
make -C deploy web PLATFORM=linux/amd64 OUTPUT=load
```

The Web is deliberately outside `build-images` and image-release workflows
until its production rollout is planned. PR validation runs lint, type checks,
tests and an SSR production build.

## Recovery

Stop/recreate only Web to revert a frontend change. Retain additive schema and
reader grants when rolling the application back to a compatible earlier API.
Do not remove tag tables or undo migrations destructively. Discovery publication
and tag edits appear on the next uncached read without rebuilding/restarting Web.
Existing open pages refresh on reload or an explicit navigation/retry.
