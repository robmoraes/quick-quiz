# Local Deploy

[pt-BR](./README-pt-BR.md)

Docker packaging for running and testing QuickQuiz Dev locally with a production-oriented runtime layout.

## Services

- `api`: statically compiled Go API in a multi-stage image, running without root.
- `ads-api`: statically compiled Go Ads API for advertising delivery and management.
- `spa-dev`: static Quasar/Vue build served by Nginx without root.
- `spa-dslab`: static DSLab-themed Quasar/Vue build served by Nginx without root.
- `manager-fpm`: Symfony manager app with production Composer dependencies, PHP-FPM, OPcache, and SQLite.
- `manager-web`: lightweight Nginx frontend for the manager through FastCGI.

## Local Run

Copy the sample environment file when you want to override ports, secrets, image tags, or the content path:

```sh
cp deploy/compose/.env.example deploy/compose/.env
```

Start the stack:

```sh
docker compose --env-file deploy/compose/.env -f deploy/compose/docker-compose.yml up -d --build
```

Local URLs:

- API health: `http://localhost:8080/healthz`
- SPA Dev: `http://localhost:8082`
- Manager: `http://localhost:8081`

By default, Compose mounts `deploy/content-demo` as the local demo content. The API mounts it read-only at `/app/.local`; the manager mounts the same directory at `/content` with write access for local tests. To use another content directory, set `QUICKQUIZ_CONTENT_ROOT` in `deploy/compose/.env`.

The manager SQLite database is stored under the mounted content directory by default:

```text
<QUICKQUIZ_CONTENT_ROOT>/.manager/manager.sqlite
```

The manager creates `.manager/manager.sqlite` when the admin repository is first used, such as during a login attempt or when creating an admin user.

Create a local manager admin:

```sh
docker compose --env-file deploy/compose/.env -f deploy/compose/docker-compose.yml exec manager-fpm \
  php bin/console manager:admin:create admin@example.com 'change-this-password'
```

The PHP-FPM container runs with `QUICKQUIZ_RUNTIME_UID` and `QUICKQUIZ_RUNTIME_GID`. Adjust these values if your local user is not `1000:1000`.

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
  SPA_DEV_API_BASE_URL=https://api.quickquiz.com.br \
  SPA_DEV_ADS_API_BASE_URL=https://ads.quickquiz.com.br \
  SPA_DSLAB_API_BASE_URL=https://api.quickquiz.com.br \
  SPA_DSLAB_ADS_API_BASE_URL=https://ads.quickquiz.com.br \
  OUTPUT=oci
```

Push all six images with the same tag:

```sh
make -C deploy build-images \
  TAG=v0.1.0-beta \
  SPA_DEV_API_BASE_URL=https://api.quickquiz.com.br \
  SPA_DEV_ADS_API_BASE_URL=https://ads.quickquiz.com.br \
  SPA_DSLAB_API_BASE_URL=https://api.quickquiz.com.br \
  SPA_DSLAB_ADS_API_BASE_URL=https://ads.quickquiz.com.br \
  OUTPUT=push
```

When `OUTPUT=push` is used, the Makefile also tags and pushes the same image as `latest` for each repository. For example, `TAG=v0.1.0-beta` publishes both `robmoraes/quick-quiz-api:v0.1.0-beta` and `robmoraes/quick-quiz-api:latest`.

SPA image builds require both the Quiz API base URL and the Ads API base URL;
use `SPA_DEV_API_BASE_URL`/`SPA_DEV_ADS_API_BASE_URL` or
`SPA_DSLAB_API_BASE_URL`/`SPA_DSLAB_ADS_API_BASE_URL` for app-specific values.
`VITE_API_BASE_URL` and `VITE_ADS_API_BASE_URL` remain available as shared
fallbacks for local one-off builds. There is intentionally no default because
the values are compiled into the static frontend bundle.

Build or push only one image with an individual tag:

```sh
make -C deploy api API_TAG=v0.1.1-beta OUTPUT=push
make -C deploy ads-api ADS_API_TAG=v0.1.1-beta OUTPUT=push
make -C deploy spa-dev SPA_DEV_TAG=v0.1.1-beta SPA_DEV_API_BASE_URL=https://api.quickquiz.com.br SPA_DEV_ADS_API_BASE_URL=https://ads.quickquiz.com.br OUTPUT=push
make -C deploy spa-dslab SPA_DSLAB_TAG=v0.1.1-beta SPA_DSLAB_API_BASE_URL=https://api.quickquiz.com.br SPA_DSLAB_ADS_API_BASE_URL=https://ads.quickquiz.com.br OUTPUT=push
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
  SPA_DEV_API_BASE_URL=https://api.quickquiz.com.br \
  SPA_DEV_ADS_API_BASE_URL=https://ads.quickquiz.com.br \
  SPA_DSLAB_API_BASE_URL=https://api.quickquiz.com.br \
  SPA_DSLAB_ADS_API_BASE_URL=https://ads.quickquiz.com.br \
  OUTPUT=push
```

Load images into the local Docker daemon:

```sh
make -C deploy build-images \
  SPA_DEV_API_BASE_URL=https://api.quickquiz.com.br \
  SPA_DEV_ADS_API_BASE_URL=https://ads.quickquiz.com.br \
  SPA_DSLAB_API_BASE_URL=https://api.quickquiz.com.br \
  SPA_DSLAB_ADS_API_BASE_URL=https://ads.quickquiz.com.br \
  OUTPUT=load
```

The GitHub Actions release workflow publishes app images when supported release
tags are pushed. The workflow uses the `production` GitHub Environment and reads
Docker Hub repositories and SPA API URLs from environment variables:

- `DOCKERHUB_API_IMAGE`
- `DOCKERHUB_ADS_API_IMAGE`
- `DOCKERHUB_SPA_DEV_IMAGE`
- `DOCKERHUB_SPA_DSLAB_IMAGE`
- `DOCKERHUB_MANAGER_FPM_IMAGE`
- `DOCKERHUB_MANAGER_WEB_IMAGE`
- `SPA_DEV_API_BASE_URL`
- `SPA_DEV_ADS_API_BASE_URL`
- `SPA_DSLAB_API_BASE_URL`
- `SPA_DSLAB_ADS_API_BASE_URL`
