# Manager Documentation

The manager service lives in `apps/manager/` and is implemented with Symfony.
Its login sessions are stored in Redis; the local Compose provides Redis without
installing it on the host.

## Responsibilities

- Edit quiz pack JSON files.
- Manage themes and topic catalogs.
- Manage localized topic metadata.
- Create and edit question files.
- Validate content against the quiz pack contract.
- Keep inactive content editable while unpublished for the API.
- Support optional AI-assisted recommendation and localization flows.
- Expose token-protected theme, topic, and localized question CRUD for trusted automation.

## Local Development

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

The session connection, TTL, and key namespace are controlled by
`MANAGER_SESSION_REDIS_DSN`, `MANAGER_SESSION_TTL`, and
`MANAGER_SESSION_PREFIX`. Local Compose uses Redis database 1. Admin accounts
and AI prompts use PostgreSQL through `MANAGER_DATABASE_URL`; its data remains
in the `manager-db-data` Docker volume.

The automation API is documented in [Quiz Administration API](admin-api.md) and [OpenAPI](../openapi-manager-admin.yaml).

Run tests:

```sh
cd apps/manager
docker compose run --rm manager composer test
```

## Content Storage

`MANAGER_CONTENT_STORAGE_PROVIDER` selects `local` or `s3`. Local development uses the API content folder:

```text
MANAGER_CONTENT_STORAGE_PROVIDER=local
MANAGER_CONTENT_ROOT=../api/.local
```

Docker Compose mounts that folder at `/content`. With `s3`, configure `AWS_REGION`, `S3_BUCKET`, `S3_PREFIX`, `S3_ENDPOINT_URL`, and `S3_FORCE_PATH_STYLE`; authentication follows the standard AWS SDK credential chain.

The Manager, Quiz API, and Ads API must use the same content backend, bucket, and prefix, and must agree on the same quiz pack contract. If the manager
writes invalid paths, missing locale packages, wrong publication flags, or
extra metadata into question files, the API may reject the content or serve an
incorrect catalog.

Read before changing manager content code:

- [Quiz Pack Contract](../quiz-pack-contract.md)
- [Data Documentation](../data/README.md)
- Service README: [apps/manager/README.md](../../apps/manager/README.md)
