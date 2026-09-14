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

Run tests:

```sh
cd apps/manager
docker compose run --rm manager composer test
```

## Content Root

By default, local development points the manager at the API local content
folder:

```text
MANAGER_CONTENT_ROOT=../api/.local
```

In Docker Compose, this is mounted as:

```text
MANAGER_CONTENT_ROOT=/content
```

The manager and API must agree on the same quiz pack contract. If the manager
writes invalid paths, missing locale packages, wrong publication flags, or
extra metadata into question files, the API may reject the content or serve an
incorrect catalog.

Read before changing manager content code:

- [Quiz Pack Contract](../quiz-pack-contract.md)
- [Data Documentation](../data/README.md)
- Service README: [apps/manager/README.md](../../apps/manager/README.md)
