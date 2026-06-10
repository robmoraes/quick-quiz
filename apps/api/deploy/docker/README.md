# Docker Distribution

This directory contains the Docker distribution files for the QuickQuiz Dev
API.

## Files

- `Dockerfile`: multi-stage production image build.
- `compose.yaml`: local or server runtime example.
- `api.env.example`: environment template for the container.

## Build

Run from the apps/api directory:

```sh
docker build -t quickquiz-api:local -f deploy/docker/Dockerfile .
```

## Run

Create an environment file:

```sh
cp deploy/docker/api.env.example deploy/docker/api.env
```

Run with Docker:

```sh
docker run -d \
  --name quickquiz-api \
  --restart unless-stopped \
  --env-file deploy/docker/api.env \
  -p 8080:8080 \
  -v "$(pwd)/.local:/app/.local:ro" \
  quickquiz-api:local
```

Or run with Compose from this directory:

```sh
docker compose up -d --build
```

By default, Compose uses `api.env.example`. To use a local env file instead:

```sh
QUICKQUIZ_ENV_FILE=./api.env docker compose up -d --build
```

Verify the API:

```sh
curl -fsS http://127.0.0.1:8080/healthz
```

Expected response:

```json
{"status":"ok"}
```

## Publish to Docker Hub

Replace `docker.io/your-org/quickquiz-api` with the real Docker Hub repository:

```sh
docker login
docker build -t docker.io/your-org/quickquiz-api:latest -f deploy/docker/Dockerfile .
docker push docker.io/your-org/quickquiz-api:latest
```

Use immutable tags for releases:

```sh
docker tag quickquiz-api:local docker.io/your-org/quickquiz-api:2026-06-07
docker push docker.io/your-org/quickquiz-api:2026-06-07
```
