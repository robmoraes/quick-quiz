# Manager Documentation

The manager service lives in `apps/manager/` and is implemented with Symfony.

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
