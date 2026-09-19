# Production stateless migration

This runbook records the migration of the single-node QuickQuiz beta environment
from local runtime state to S3, Redis, and PostgreSQL.

The later [Manager quiz PostgreSQL cutover](manager-postgresql-migration.md)
records the current Manager release, authoritative quiz database, and recovery
backups as of 2026-09-19. The release list below describes the original
2026-09-15 infrastructure migration.

## Target

- EC2: `i-043c1e8324ef79e4c`
- Region/AZ: `us-east-1` / `us-east-1d`
- Elastic IP: `34.207.253.17`
- Instance type: `t3.small`
- Pre-migration EBS snapshot: `snap-008f672539b6aaacb`
- Content: `s3://quickquiz-beta-content-379197597050-us-east-1/questions/`
- Compose root: `/opt/quickquiz/compose`
- Secrets root: `/opt/quickquiz/secrets`

The instance role `quickquiz-beta-ec2` can list only the
`questions/` prefix and can get, put, and delete objects below it. The bucket
has public access blocked, SSE-S3 encryption, and versioning enabled.

The original Terraform state is not available on this workstation. Do not run
`terraform apply` against an empty state. Recover the original state or import
the existing EC2, network, S3, and IAM resources first.

## Release

Use immutable application image tags:

```text
robmoraes/quick-quiz-api:v0.7.0
robmoraes/quick-quiz-ads-api:v0.2.0
robmoraes/quick-quiz-dev:v0.8.0
robmoraes/quick-quiz-dslab:v0.3.0
robmoraes/quick-quiz-manager-fpm:v0.9.0
robmoraes/quick-quiz-manager-web:v0.9.0
```

Redis and PostgreSQL run only on the internal Compose network. Redis is
ephemeral. PostgreSQL uses the `manager-db-data` Docker volume.

## Preflight and backup

Confirm current health and capacity:

```sh
ssh -i ~/.ssh/id_ed25519_quickquiz ec2-user@34.207.253.17
docker compose -f /opt/quickquiz/compose/docker-compose.yml \
  --env-file /opt/quickquiz/compose/.env ps
free -h
df -h /
```

Before pulling new images:

1. Copy the current Compose directory and archive `/opt/quickquiz/data`.
2. Tag every running application image with a local
   `rollback-stateless-20260915` tag.
3. Create an EBS snapshot while the instance is stopped for resizing.

The completed snapshot contains the stopped 8 GiB root volume. The prepared server backup is:

```text
/opt/quickquiz/backups/stateless-20260915
```

## Content migration

Copy only canonical content. Do not upload the Manager SQLite database or
derived solution cache:

```sh
aws s3 sync /opt/quickquiz/data \
  s3://quickquiz-beta-content-379197597050-us-east-1/questions \
  --exclude ".manager/*" \
  --exclude "*/.solutions/*" \
  --only-show-errors
```

Run the sync once before the maintenance window and once after stopping the
Manager and Ads API. Compare local eligible file count and byte total with S3.

## Secret files

Keep secrets out of `.env`. Each application uses the existing
`NAME__FILE` contract.

```text
/opt/quickquiz/secrets/api/openai_api_key
/opt/quickquiz/secrets/api/redis_password
/opt/quickquiz/secrets/redis/redis_password
/opt/quickquiz/secrets/manager/app_secret
/opt/quickquiz/secrets/manager/admin_api_token
/opt/quickquiz/secrets/manager/database_url
/opt/quickquiz/secrets/manager/openai_api_key
/opt/quickquiz/secrets/manager/session_redis_dsn
/opt/quickquiz/secrets/manager-db/db_password
```

Directories must have mode `0700`; secret files must have mode `0600`.
The Manager administration API reads
`MANAGER_ADMIN_API_TOKEN__FILE=/run/secrets/admin_api_token`. The EC2
instance role supplies AWS credentials through IMDS, so no AWS access key files
are created.

## SQLite to PostgreSQL

Start Redis and PostgreSQL from the new Compose configuration before switching
the public services:

```sh
cd /opt/quickquiz/releases/stateless-20260915
docker compose --env-file .env \
  -f docker-compose.yml -f compose.secrets.yml \
  up -d redis manager-db
```

Stop `manager-fpm` to prevent writes to SQLite, then migrate the existing
administrator and AI prompt rows:

```sh
docker stop quickquiz-manager-fpm-1

docker compose --env-file .env \
  -f docker-compose.yml -f compose.secrets.yml \
  run --rm --no-deps \
  -e SOURCE_DATABASE_URL=sqlite:////migration/manager.sqlite \
  -e TARGET_DATABASE_URL__FILE=/run/secrets/database_url \
  -v /opt/quickquiz/data/.manager/manager.sqlite:/migration/manager.sqlite:ro \
  -v /opt/quickquiz/releases/stateless-20260915/migrate-manager-sqlite-to-postgres.php:/migration/migrate.php:ro \
  manager-fpm php /migration/migrate.php
```

The command is transactional, requires empty PostgreSQL target tables, preserves
password hashes, and verifies source and target row counts.

## Cutover

Promote the staged files and recreate the stack:

```sh
cp /opt/quickquiz/releases/stateless-20260915/docker-compose.yml \
  /opt/quickquiz/compose/docker-compose.yml
cp /opt/quickquiz/releases/stateless-20260915/compose.secrets.yml \
  /opt/quickquiz/compose/compose.secrets.yml
cp /opt/quickquiz/releases/stateless-20260915/.env \
  /opt/quickquiz/compose/.env
chmod 600 /opt/quickquiz/compose/.env

cd /opt/quickquiz/compose
docker compose --env-file .env \
  -f docker-compose.yml -f compose.secrets.yml pull
docker compose --env-file .env \
  -f docker-compose.yml -f compose.secrets.yml up -d
```

## Validation

All containers must be running and healthy:

```sh
docker compose --env-file .env \
  -f docker-compose.yml -f compose.secrets.yml ps
curl -fsS https://api.quickquiz.com.br/healthz
curl -fsS https://ads.quickquiz.com.br/healthz
curl -fsS https://dev.quickquiz.com.br/healthz
curl -fsS https://dslab.quickquiz.com.br/healthz
curl -fsS https://manager.quickquiz.com.br/healthz
```

Also validate a catalog request, Manager login with the migrated administrator,
an advertising read, and an advertising update. Confirm the updated ad object
has a new S3 version.

## Rollback

Restore the saved Compose directory, change all application images to their
local `rollback-stateless-20260915` tags, and recreate the old stack without
pulling:

```sh
cp -a /opt/quickquiz/backups/stateless-20260915/compose/. \
  /opt/quickquiz/compose/
cd /opt/quickquiz/compose
docker compose --env-file .env up -d
```

The old local content and SQLite database remain untouched during migration.
If the instance cannot boot or its local state is damaged, restore the
pre-migration EBS snapshot.

## Migration result

The migration completed on 2026-09-15 with these checks:

- 702 canonical files and 534,793 bytes matched between local storage and S3;
- one administrator and five AI prompts migrated to PostgreSQL;
- all nine containers started with zero restarts;
- Redis returned `PONG`;
- the Manager read `themes.json` through its S3 storage adapter;
- API, Ads API, both SPAs, and Manager health endpoints returned HTTP 200;
- a disposable quiz run returned HTTP 201 and its session reset returned HTTP 200;
- no panic, fatal error, access denial, or permission error appeared in deployment logs.
