# Cloud Publishing

Optional cloud publishing process for the QuickQuiz Dev beta environment.

This deployment path is based on:

- Docker Hub images built from this repository.
- A single AWS EC2 instance provisioned with Terraform.
- Docker Compose on the instance.
- Traefik for HTTPS routing and Let's Encrypt certificate management.

## Domains

The cloud Compose stack expects these public domains:

```text
quickquiz.com.br
dev.quickquiz.com.br
dslab.quickquiz.com.br
api.quickquiz.com.br
ads.quickquiz.com.br
manager.quickquiz.com.br
```

Routing is handled by Traefik:

- `api.quickquiz.com.br` routes to the Go API.
- `ads.quickquiz.com.br` routes to the Go Ads API.
- `dev.quickquiz.com.br` routes to the QuickQuiz Dev SPA.
- `dslab.quickquiz.com.br` routes to the QuickQuiz DSLab SPA.
- `quickquiz.com.br` routes to the QuickQuiz Dev SPA.
- `manager.quickquiz.com.br` routes to the Symfony manager.

All domains must resolve to the EC2 public IP before the first cloud Compose start, otherwise Let's Encrypt certificate issuance can fail.

## Image Publishing

The cloud stack does not build images on the server. It pulls prebuilt images from Docker Hub.

Default image repositories:

```text
robmoraes/quick-quiz-api
robmoraes/quick-quiz-ads-api
robmoraes/quick-quiz-dev
robmoraes/quick-quiz-dslab
robmoraes/quick-quiz-manager-fpm
robmoraes/quick-quiz-manager-web
```

Publish all images with a shared tag:

```sh
docker login
make -C deploy build-images \
  TAG=v0.1.0-beta \
  SPA_DEV_API_BASE_URL=https://api.quickquiz.com.br \
  SPA_DEV_ADS_API_BASE_URL=https://ads.quickquiz.com.br \
  SPA_DSLAB_API_BASE_URL=https://api.quickquiz.com.br \
  SPA_DSLAB_ADS_API_BASE_URL=https://ads.quickquiz.com.br \
  OUTPUT=push
```

When `OUTPUT=push` is used, the Makefile publishes the requested version tag and also updates `latest` for each image repository. For example, `TAG=v0.1.0-beta` publishes:

```text
robmoraes/quick-quiz-api:v0.1.0-beta
robmoraes/quick-quiz-api:latest
```

Publish one image independently:

```sh
make -C deploy api API_TAG=v0.1.1-beta OUTPUT=push
make -C deploy ads-api ADS_API_TAG=v0.1.1-beta OUTPUT=push
make -C deploy spa-dev SPA_DEV_TAG=v0.1.1-beta SPA_DEV_API_BASE_URL=https://api.quickquiz.com.br SPA_DEV_ADS_API_BASE_URL=https://ads.quickquiz.com.br OUTPUT=push
make -C deploy spa-dslab SPA_DSLAB_TAG=v0.1.1-beta SPA_DSLAB_API_BASE_URL=https://api.quickquiz.com.br SPA_DSLAB_ADS_API_BASE_URL=https://ads.quickquiz.com.br OUTPUT=push
make -C deploy manager-fpm MANAGER_FPM_TAG=v0.1.1-beta OUTPUT=push
make -C deploy manager-web MANAGER_WEB_TAG=v0.1.1-beta OUTPUT=push
```

The build currently targets `linux/amd64`.

During the beta phase, image publishing is manual and intentionally stays outside GitHub Actions. The operator builds and pushes images from a local machine using the deploy Makefile.

## Infrastructure

Terraform lives in:

```text
deploy/terraform/aws-ec2
```

The current AWS layout provisions:

- One EC2 instance.
- One Elastic IP.
- One security group with HTTP, HTTPS, SSH, and outbound access.
- One EC2 key pair from a local public key.
- User-data bootstrap that installs Docker, Docker Compose, and prepares `/opt/quickquiz`.

Create a real Terraform variables file:

```sh
cp deploy/terraform/aws-ec2/terraform.tfvars.example deploy/terraform/aws-ec2/terraform.tfvars
```

Required values:

```hcl
ami_id           = "ami-..."
key_name         = "quickquiz-key"
public_key_path  = "~/.ssh/id_rsa.pub"
ssh_allowed_cidr = "203.0.113.10/32"
```

Apply infrastructure:

```sh
cd deploy/terraform/aws-ec2
terraform init
terraform plan
terraform apply
```

After apply, point the DNS records to the allocated public IP.

## Server Layout

The EC2 bootstrap prepares this base layout:

```text
/opt/quickquiz/
/opt/quickquiz/compose/
/opt/quickquiz/traefik/letsencrypt/acme.json
```

Recommended runtime layout:

```text
/opt/quickquiz/compose/docker-compose.yml
/opt/quickquiz/compose/.env
/opt/quickquiz/themes.json
/opt/quickquiz/dev/...
/opt/quickquiz/.manager/manager.sqlite
/opt/quickquiz/traefik/letsencrypt/acme.json
```

The cloud Compose file mounts `QUICKQUIZ_CONTENT_ROOT` into:

- `/app/.local` read-only for the API.
- `/content` read-write for the manager.

The manager SQLite path defaults to:

```text
sqlite:////content/.manager/manager.sqlite
```

That means the database is persisted under:

```text
${QUICKQUIZ_CONTENT_ROOT}/.manager/manager.sqlite
```

## Cloud Compose

Cloud Compose files live in:

```text
deploy/compose.cloud
```

Copy them to the server:

```sh
scp deploy/compose.cloud/docker-compose.yml ec2-user@<server-ip>:/opt/quickquiz/compose/docker-compose.yml
scp deploy/compose.cloud/.env-example ec2-user@<server-ip>:/opt/quickquiz/compose/.env
```

Edit `/opt/quickquiz/compose/.env` on the server.

Production domain values for this environment:

```env
ROOT_DOMAIN=quickquiz.com.br
DEV_DOMAIN=dev.quickquiz.com.br
DSLAB_DOMAIN=dslab.quickquiz.com.br
API_DOMAIN=api.quickquiz.com.br
ADS_DOMAIN=ads.quickquiz.com.br
MANAGER_DOMAIN=manager.quickquiz.com.br
```

Set images to the tag being published:

```env
QUICKQUIZ_IMAGE_API=robmoraes/quick-quiz-api:v0.1.0-beta
QUICKQUIZ_IMAGE_ADS_API=robmoraes/quick-quiz-ads-api:v0.1.0-beta
QUICKQUIZ_IMAGE_DEV=robmoraes/quick-quiz-dev:v0.1.0-beta
QUICKQUIZ_IMAGE_DSLAB=robmoraes/quick-quiz-dslab:v0.1.0-beta
QUICKQUIZ_IMAGE_MANAGER_FPM=robmoraes/quick-quiz-manager-fpm:v0.1.0-beta
QUICKQUIZ_IMAGE_MANAGER_WEB=robmoraes/quick-quiz-manager-web:v0.1.0-beta
```

Set the content root:

```env
QUICKQUIZ_CONTENT_ROOT=/opt/quickquiz
```

Start or update the stack:

```sh
cd /opt/quickquiz/compose
docker compose --env-file .env pull
docker compose --env-file .env up -d
```

Check services:

```sh
docker compose --env-file .env ps
docker compose --env-file .env logs -f traefik
docker compose --env-file .env logs -f api
docker compose --env-file .env logs -f ads-api
```

## Content Publication

The Quiz API loads quiz content into memory during startup. Content changes made by the manager are not visible to a running Quiz API process until the API is restarted. The Ads API reads and writes advertising storage directly on request.

This makes content publication explicit:

1. Prepare or edit content with the manager.
2. Validate the content package.
3. Restart the API during the chosen publication window.

Restart only the API:

```sh
cd /opt/quickquiz/compose
docker compose --env-file .env restart api
```

Deploy a new application image tag:

```sh
cd /opt/quickquiz/compose
docker compose --env-file .env pull
docker compose --env-file .env up -d
```

## Manager Admin

Create the first manager admin on the server:

```sh
cd /opt/quickquiz/compose
docker compose --env-file .env exec manager-fpm \
  php bin/console manager:admin:create admin@example.com 'change-this-password'
```

The manager creates the SQLite database and `.manager` directory when the admin repository is first used.

## Operational Notes

- Keep `MANAGER_APP_SECRET` stable between manager restarts so sessions remain valid.
- Keep `ACME_EMAIL` set to a real mailbox for Let's Encrypt notifications.
- Back up `QUICKQUIZ_CONTENT_ROOT`, especially quiz JSON files and `.manager/manager.sqlite`.
- The Terraform state and `.env` files can contain sensitive or environment-specific values and should not be committed.
- If API startup fails, check that `${QUICKQUIZ_CONTENT_ROOT}/themes.json` and the active theme package exist and are valid.
