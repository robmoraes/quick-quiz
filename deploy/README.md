# Local Deploy

[pt-BR](./README-pt-BR.md)

Docker packaging for running and testing QuickQuiz Dev locally with a production-oriented runtime layout.

## Services

- `api`: statically compiled Go API in a multi-stage image, running without root.
- `redis`: ephemeral run/session and generated-solution cache, limited to 256 MiB of data and 384 MiB of container memory.
- `manager-db`: PostgreSQL storage for Manager administrators and AI prompts.
- `ads-api`: statically compiled Go Ads API for advertising delivery and management.
- `spa-dev`: static Quasar/Vue build served by Nginx without root.
- `spa-dslab`: static DSLab-themed Quasar/Vue build served by Nginx without root.
- `manager-fpm`: Symfony manager app with production Composer dependencies, PHP-FPM, OPcache, and PDO PostgreSQL.
- `manager-web`: lightweight Nginx frontend for the manager through FastCGI.

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

The Manager stores administrators and AI prompts in PostgreSQL. The local database
is available only on the internal Docker network and persists in the
`manager-db-data` volume. Configure it with `MANAGER_DATABASE_URL` and the
`MANAGER_DB_*` variables.

Create a local manager admin:

```sh
docker compose --env-file deploy/compose.local/.env -f deploy/compose.local/docker-compose.yml exec manager-fpm \
  php bin/console manager:admin:create admin@example.com 'change-this-password'
```

The PHP-FPM container runs with `QUICKQUIZ_RUNTIME_UID` and `QUICKQUIZ_RUNTIME_GID`. Adjust these values if your local user is not `1000:1000`.

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
```

You can override each repository with:

```sh
API_REPOSITORY=example/api
ADS_API_REPOSITORY=example/ads-api
SPA_DEV_REPOSITORY=example/spa
SPA_DSLAB_REPOSITORY=example/spa-dslab
MANAGER_FPM_REPOSITORY=example/manager-fpm
MANAGER_WEB_REPOSITORY=example/manager-web
```

## Image Builds

The Makefile uses `docker buildx` and builds `linux/amd64` images by default.

For the optional cloud publishing flow using AWS EC2, Docker Compose, Traefik, and the project domains, see [Cloud Publishing](./CLOUD-PUBLISHING.md).

Export all six images as OCI artifacts under `deploy/dist`:

```sh
make -C deploy build-images \
  TAG=v0.1.0-beta \
  OUTPUT=oci
```

Push all six images with the same tag:

```sh
make -C deploy build-images \
  TAG=v0.1.0-beta \
  OUTPUT=push
```

When `OUTPUT=push` is used, the Makefile also tags and pushes the same image as `latest` for each repository. For example, `TAG=v0.1.0-beta` publishes both `robmoraes/quick-quiz-api:v0.1.0-beta` and `robmoraes/quick-quiz-api:latest`.

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

Build all six images with different tags:

```sh
make -C deploy build-images \
  API_TAG=v0.1.1-api \
  ADS_API_TAG=v0.1.1-ads-api \
  SPA_DEV_TAG=v0.1.0-dev \
  SPA_DSLAB_TAG=v0.1.0-dslab \
  MANAGER_FPM_TAG=v0.1.2-fpm \
  MANAGER_WEB_TAG=v0.1.2-web \
  OUTPUT=push
```

Load images into the local Docker daemon:

```sh
make -C deploy build-images \
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
