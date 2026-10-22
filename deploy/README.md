# Local Deploy

[pt-BR](./README-pt-BR.md)

Docker packaging for running and testing QuickQuiz Dev locally with a production-oriented runtime layout.

## Services

- `api`: statically compiled Go API in a multi-stage image, running without root.
- `redis`: ephemeral run/session and generated-solution cache, limited to 256 MiB of data and 384 MiB of container memory.
- `manager-db`: PostgreSQL for Manager administrators, AI prompts and selected authored content.
- `ads-api`: statically compiled Go Ads API for advertising delivery and management.
- `spa-dev`: static Quasar/Vue build served by Nginx without root.
- `spa-dslab`: static DSLab-themed Quasar/Vue build served by Nginx without root.
- `manager-fpm`: Symfony manager app with production Composer dependencies, PHP-FPM, OPcache, and PDO PostgreSQL.
- `manager-web`: lightweight Nginx frontend for the manager through FastCGI.
- `web` (optional profile): Nuxt SSR discovery and generic quiz UI; see the
  [local Web runbook](../docs/runbooks/generic-quiz-web-local.md).

## Local Run

Copy the sample environment file when you want to override ports, secrets, image tags, or the content path:

```sh
cp deploy/compose.local/.env-example deploy/compose.local/.env
```

Start the stack:

```sh
docker compose --env-file deploy/compose.local/.env -f deploy/compose.local/docker-compose.yml up -d --build
```

Local URLs:

- API health: `http://localhost:8080/healthz`
- SPA Dev: `http://localhost:8082`
- Manager: `http://localhost:8081`

The local Compose starts Redis with the stack and configures API runs, generated solutions, and Manager sessions to use it through the internal Docker network. Redis is not installed on the host, exposes no host port, and has no persistent volume; restarting it invalidates active quiz runs and Manager login sessions, while generated solutions are recreated on demand. The official image supports both `linux/amd64` and `linux/arm64`. The API writes no runtime state to its filesystem.

By default, Compose mounts `deploy/content-demo` as the local demo content. The API mounts it read-only at `/app/.local`; the Manager mounts the same directory at `/content` with write access for local tests. To use another content directory, set `QUICKQUIZ_CONTENT_ROOT` in `deploy/compose.local/.env`. To exercise shared S3-compatible storage, set `QUESTION_STORAGE_PROVIDER=s3`, `ADS_STORAGE_PROVIDER=s3`, and `MANAGER_CONTENT_STORAGE_PROVIDER=s3`, then configure the shared `AWS_REGION`, `S3_BUCKET`, `S3_PREFIX`, `S3_ENDPOINT_URL`, and `S3_FORCE_PATH_STYLE` values.

The Manager stores administrators, AI prompts and selected authored content in PostgreSQL. The local database
is available only on the internal Docker network and persists in the
`manager-db-data` volume. Configure it with `MANAGER_DATABASE_URL` and the
`MANAGER_DB_*` variables.

To keep local database files inside the workspace, set `MANAGER_DB_STORAGE` in
`deploy/compose.local/.env`, for example
`MANAGER_DB_STORAGE=../../.local/services/postgres/quickquiz-dev`. Relative paths
resolve from `deploy/compose.local`. The root `.local/` directory is ignored by
Git; use `.local/backups/postgres/` for logical backups. Give each database/Compose
project a separate directory. Changing this setting does not migrate an existing
named volume: export with `pg_dump` and restore into the new database first.
Leave it unset to keep the existing `manager-db-data` named volume. Stop PostgreSQL
before copying its data directory; use `pg_dump` for backups while it is running.
Redis remains ephemeral.

Create a local manager admin:

```sh
docker compose --env-file deploy/compose.local/.env -f deploy/compose.local/docker-compose.yml exec manager-fpm \
  php bin/console manager:admin:create admin@example.com 'change-this-password'
```

The PHP-FPM container runs with `QUICKQUIZ_RUNTIME_UID` and `QUICKQUIZ_RUNTIME_GID`. Adjust these values if your local user is not `1000:1000`.

## SPA Dev Theme Audience

`SPA_DEV_THEME_TAG=dev` in the Compose environment becomes `SPA_THEME_TAG` in
the Dev container and `themeTag` in `/runtime-config.js`. An absent variable
defaults to `dev`; an explicitly blank or invalid tag prevents startup. Change
the value by recreating the Dev container with the same image. The browser URL
does not override this setting. SPA DSLab keeps its existing configuration.

To expose Kubernetes on the Dev site, create/activate that theme in PostgreSQL
Manager, assign its `dev` tag, publish successfully, and reload the Quiz API.
Confirm `GET /api/themes?tag=dev` includes it. No SPA rebuild is needed. Existing
topic deep links use the hash router, for example `/#/?topic=git`, and apply
within the initially selected theme.

For the first release, back up PostgreSQL and published content, apply migration
`0003_theme_tags.sql` through the existing Manager migration command, release
Manager/API support, and tag the existing Dev theme before deploying the new
SPA Dev image. Local Compose still defaults to legacy authoring; the demo JSON
already includes the `dev` tag. To edit theme tags through Manager, use its
existing PostgreSQL authoring/import setup.

Rollback SPA Dev before returning the API to a version without `/api/themes`.
Keep additive tag tables. Pause publication if Manager is rolled back to a
publisher that omits theme tags. The full sequence is in
[spec 005's plan](../docs/specs/005-tagged-theme-selection/plan.md).

## File-backed secrets

Runtime secrets support `NAME__FILE`. The referenced file takes priority over
`NAME`; missing, unreadable, or empty files stop the affected service. Trailing
line endings are removed. The SPAs do not accept secrets because their runtime
configuration is delivered to the browser.

For Docker Compose, create one directory per service under an absolute root:
`api`, `ads-api`, `redis`, `manager`, and `manager-db`. Set
`QUICKQUIZ_SECRETS_ROOT`, configure the corresponding `__FILE` variables with
paths under `/run/secrets`, and add the read-only mount overlay:

```sh
docker compose \
  --env-file deploy/compose.local/.env \
  -f deploy/compose.local/docker-compose.yml \
  -f deploy/compose.secrets.yml \
  up -d
```

Kubernetes can use the same application contract by mounting Secret keys and
setting each `NAME__FILE` to its mounted path.

## Image Repositories

The deploy Makefile defaults to these Docker Hub repositories:

```text
robmoraes/quick-quiz-api
robmoraes/quick-quiz-ads-api
robmoraes/quick-quiz-dev
robmoraes/quick-quiz-dslab
robmoraes/quick-quiz-manager-fpm
robmoraes/quick-quiz-manager-web
robmoraes/quick-quiz-web
robmoraes/quick-quiz-php-base
```

You can override each repository with:

```sh
API_REPOSITORY=example/api
ADS_API_REPOSITORY=example/ads-api
SPA_DEV_REPOSITORY=example/spa
SPA_DSLAB_REPOSITORY=example/spa-dslab
MANAGER_FPM_REPOSITORY=example/manager-fpm
MANAGER_WEB_REPOSITORY=example/manager-web
WEB_REPOSITORY=example/web
PHP_BASE_REPOSITORY=example/php-base
```

## Image Builds

The Makefile uses `docker buildx` and builds `linux/amd64` and `linux/arm64` images by default.

Multi-platform builds need a `docker-container` builder with ARM execution support.
The release workflow configures QEMU and Buildx automatically. For a persistent manual build setup on Ubuntu/Debian, install ARM emulation
and create one reusable builder:

```sh
sudo apt-get update
sudo apt-get install -y qemu-user-static binfmt-support
docker buildx create --name multiarch --driver docker-container --use --bootstrap
export BUILDER=multiarch
```

Docker Desktop already provides emulation, so only the builder creation and
`BUILDER` export are normally required there.

Maintainer-specific cloud configuration and operational records live in a
separate private infrastructure workspace. See
[deployment responsibilities](../docs/deployment.md) for the boundary between
application image publication and environment deployment.

### Manager PHP base

The `robmoraes/quick-quiz-php-base` image is built from
[`deploy/docker/php-base/Dockerfile`](./docker/php-base/Dockerfile). It is
deliberately excluded from the `build-images` target and GitHub Actions.
Build and publish it manually:

```sh
docker login
make -C deploy php-base BUILDER=multiarch OUTPUT=push
```

The default tag is `8.3.33-alpine3.24-r1`; pushing also updates `latest`.
PHP, Alpine, or package security updates require reviewing the Dockerfile,
incrementing the revision, and rebuilding both architectures.

Export all seven application images as OCI artifacts under `deploy/dist`:

```sh
make -C deploy build-images \
  TAG=v0.1.0-beta \
  OUTPUT=oci
```

Push all seven application images with the same tag:

```sh
make -C deploy build-images \
  TAG=v0.1.0-beta \
  OUTPUT=push
```

When `OUTPUT=push` is used, the Makefile also tags and pushes the same image as `latest` for each repository. For example, `TAG=v0.1.0-beta` publishes both `robmoraes/quick-quiz-api:v0.1.0-beta` and `robmoraes/quick-quiz-api:latest`.

`OUTPUT=push` publishes one multi-platform manifest for both architectures. OCI
exports also contain both architectures. Docker cannot load a multi-platform
manifest into the local image store, so use one explicit platform with
`OUTPUT=load`:

```sh
make -C deploy build-images PLATFORM=linux/amd64 OUTPUT=load
```

Use `PLATFORM=linux/arm64` for an ARM-only local export or load.

SPA images are environment-independent. At container startup, Compose maps the
app-specific `SPA_*_API_BASE_URL` values to `SPA_API_BASE_URL` and
`SPA_ADS_API_BASE_URL`; endpoint changes only require recreating the container.

Build or push only one image with an individual tag:

```sh
make -C deploy api API_TAG=v0.1.1-beta OUTPUT=push
make -C deploy ads-api ADS_API_TAG=v0.1.1-beta OUTPUT=push
make -C deploy spa-dev SPA_DEV_TAG=v0.1.1-beta OUTPUT=push
make -C deploy spa-dslab SPA_DSLAB_TAG=v0.1.1-beta OUTPUT=push
make -C deploy manager-fpm MANAGER_FPM_TAG=v0.1.1-beta OUTPUT=push
make -C deploy manager-web MANAGER_WEB_TAG=v0.1.1-beta OUTPUT=push
```

Build all seven application images with different tags:

```sh
make -C deploy build-images \
  API_TAG=v0.1.1-api \
  ADS_API_TAG=v0.1.1-ads-api \
  SPA_DEV_TAG=v0.1.0-dev \
  SPA_DSLAB_TAG=v0.1.0-dslab \
  MANAGER_FPM_TAG=v0.1.2-fpm \
  MANAGER_WEB_TAG=v0.1.2-web \
  WEB_TAG=v0.1.0-web \
  OUTPUT=push
```

Load images into the local Docker daemon:

```sh
make -C deploy build-images \
  PLATFORM=linux/amd64 \
  OUTPUT=load
```

The GitHub Actions release workflow publishes app images when supported release
tags are pushed. The workflow uses the `production` GitHub Environment and reads
Docker Hub repositories from environment variables:

- `DOCKERHUB_API_IMAGE`
- `DOCKERHUB_ADS_API_IMAGE`
- `DOCKERHUB_SPA_DEV_IMAGE`
- `DOCKERHUB_SPA_DSLAB_IMAGE`
- `DOCKERHUB_MANAGER_FPM_IMAGE`
- `DOCKERHUB_MANAGER_WEB_IMAGE`
- `DOCKERHUB_WEB_IMAGE`

Web releases use independent tags such as `web/v0.1.0` and publish the configured
Web repository with the version plus `latest` for both architectures. Node 24
validation runs before the image push. See the [Web image release guide](../docs/runbooks/generic-quiz-web-image-release.md).
Publishing an image does not deploy it to an environment.

## PostgreSQL quiz runtime

The opt-in `compose.local/compose.postgres.yml` overlay selects PostgreSQL question
and prompt reads plus atomic Manager publication. It requires migrations, a
separate reader credential and explicit backend activation; the overlay alone
does not migrate an environment. Follow the [runbook](../docs/runbooks/postgresql-quiz-runtime.md)
in a disposable project/volume for rehearsal. Defaults remain legacy. Database
publication makes edits visible without an API restart; existing v2 runs retain
their captured questions in Redis. Use `/readyz` for dependency checks and
`/healthz` for liveness. This implementation does not itself change production.
