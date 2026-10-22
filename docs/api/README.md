# API Documentation

The API service lives in `apps/api/` and is implemented in Go.

## Responsibilities

- Serve active catalog metadata.
- Start quiz runs.
- Select questions and answer options.
- Validate answers.
- Track session exhaustion through the configured run store.
- Return run results.
- Read live PostgreSQL content by scope, or quiz packs through legacy local/S3 providers.

## Local Development

```sh
cd apps/api
go run ./cmd/api
```

Default address:

```text
http://localhost:8080
```

Run tests:

```sh
cd apps/api
go test ./...
```

## API Contract

- [OpenAPI YAML](../openapi.yaml)
- Service README: [apps/api/README.md](../../apps/api/README.md)

Important endpoints:

- `GET /healthz` (liveness)
- `GET /readyz` (configured dependency and contract readiness)
- `GET /api/catalog`
- `GET /api/session/topics`
- `GET /api/session/difficulties`
- `POST /api/session/reset`
- `POST /api/runs`
- `POST /api/runs/{runId}/answers`
- `POST /api/runs/{runId}/finish`
- `GET /api/runs/{runId}/result`

## Content Inputs

The API reads quiz packs from `QUESTION_SOURCE` when
`QUESTION_STORAGE_PROVIDER=local`. The default source is `apps/api/.local`.

Do not bypass the [quiz pack contract](../quiz-pack-contract.md). The API
derives content metadata from paths and rejects invalid packages instead of
guessing missing values.


PostgreSQL runtime is opt-in and requires an explicitly activated backend plus a
SELECT-only reader credential. New requests see committed edits immediately;
existing v2 runs use Redis snapshots. The [migration runbook](../runbooks/postgresql-quiz-runtime.md)
covers staged Redis compatibility, health, export and rollback. Environment
configuration and production cutover records are maintained separately; see
[deployment responsibilities](../deployment.md).
