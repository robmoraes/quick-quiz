# Install with Docker from Docker Hub

This guide runs the QuickQuiz Dev API with Docker using an image already
published on Docker Hub.

## Assumptions

- Docker is installed on the server.
- The image is published on Docker Hub.
- Replace `docker.io/your-org/quickquiz-api:latest` with the real image tag.
- Local question content is mounted from `/opt/quickquiz/api/.local`.
- The container listens on port `8080`.

## Prepare Local Content

When using the local storage provider, prepare the question content on the host:

```sh
sudo mkdir -p /opt/quickquiz/api/.local
sudo chown -R "$USER":"$USER" /opt/quickquiz
```

The expected layout is:

```text
/opt/quickquiz/api/.local/themes.json
/opt/quickquiz/api/.local/<theme>/index.json
/opt/quickquiz/api/.local/<theme>/en-US/index.json
/opt/quickquiz/api/.local/<theme>/en-US/<topic>/<difficulty>/<question-id>.json
```

## Configure Environment

Create `/opt/quickquiz/api/api.env`:

```sh
cat >/opt/quickquiz/api/api.env <<'EOF'
HTTP_ADDR=:8080
QUESTION_STORAGE_PROVIDER=local
QUESTION_SOURCE=/app/.local
FALLBACK_LOCALE=en-US
SUPPORTED_LOCALES=en-US,pt-BR
RUN_QUESTION_LIMIT=10
SESSION_TTL=30m
SHUTDOWN_TIMEOUT=10s
OPENAI_API_KEY=
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_MODEL=gpt-5.4-mini
OPENAI_SOLUTION_PROMPT_FILE=/app/.local/{{theme}}/ai-prompts/question-solution-prompt.txt
OPENAI_TIMEOUT=30s
EOF
```

If question content is loaded from S3, set `QUESTION_STORAGE_PROVIDER=s3` and add
the required AWS and S3 variables documented in `apps/api/README.md`. In that
case, the `.local` volume mount is optional.

## Pull the Image

```sh
docker pull docker.io/your-org/quickquiz-api:latest
```

## Run the Container

```sh
docker run -d \
  --name quickquiz-api \
  --restart unless-stopped \
  --env-file /opt/quickquiz/api/api.env \
  -p 8080:8080 \
  -v /opt/quickquiz/api/.local:/app/.local:ro \
  docker.io/your-org/quickquiz-api:latest
```

Verify the API:

```sh
curl -fsS http://127.0.0.1:8080/healthz
```

Expected response:

```json
{"status":"ok"}
```

## Run Behind Nginx

If Nginx terminates HTTP or HTTPS on the host, keep the container bound to the
host and proxy requests to it:

```nginx
server {
    listen 80;
    server_name api.example.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

For production, configure TLS with your certificate provider.

## Upgrade

Pull the new image and recreate the container:

```sh
docker pull docker.io/your-org/quickquiz-api:latest
docker stop quickquiz-api
docker rm quickquiz-api
docker run -d \
  --name quickquiz-api \
  --restart unless-stopped \
  --env-file /opt/quickquiz/api/api.env \
  -p 8080:8080 \
  -v /opt/quickquiz/api/.local:/app/.local:ro \
  docker.io/your-org/quickquiz-api:latest
```

## Publish an Image to Docker Hub

If you also need to publish the image, build and push it from a repository that
contains the API Dockerfile:

```sh
docker login
cd apps/api
docker build -t docker.io/your-org/quickquiz-api:latest -f deploy/docker/Dockerfile .
docker push docker.io/your-org/quickquiz-api:latest
```

Use immutable tags for releases, for example
`docker.io/your-org/quickquiz-api:2026-06-07`.
