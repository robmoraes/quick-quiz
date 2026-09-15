# Manager PHP Base Multi-architecture Release

Use this runbook to update and publish the PHP base used by the QuickQuiz
Manager development and production images.

## Scope

- Source: `deploy/docker/php-base/Dockerfile`
- Docker Hub repository: `robmoraes/quick-quiz-php-base`
- Platforms: `linux/amd64` and `linux/arm64`
- Publishing: manual only; this image is excluded from GitHub Actions and from
  the `build-images` target.

Versioned tags follow `<php>-alpine<alpine>-r<revision>`. Treat them as
immutable. Publish a new revision for every change; `latest` follows the most
recent approved revision.

## Prerequisites

- Docker Engine with the Buildx plugin.
- Permission to push to the `robmoraes` Docker Hub namespace.
- A reviewed upstream PHP-FPM Alpine image that supports both target platforms.
- A clean short-lived branch for the update.

## Prepare an Ubuntu/Debian host once

Install persistent emulation support:

```bash
sudo apt-get update
sudo apt-get install -y qemu-user-static binfmt-support
```

Create and select a reusable builder:

```bash
docker buildx create \
  --name multiarch \
  --driver docker-container \
  --use \
  --bootstrap
```

If it already exists, select and start it:

```bash
docker buildx use multiarch
docker buildx inspect multiarch --bootstrap
```

Confirm that `linux/amd64` and `linux/arm64` appear under `Platforms`:

```bash
docker buildx inspect multiarch
```

## Prepare an update

1. Select an exact official PHP-FPM Alpine tag.
2. Confirm that its manifest contains both target platforms:

   ```bash
   docker buildx imagetools inspect php:<php-version>-fpm-alpine<alpine-version>
   ```

3. Record the manifest digest and update `PHP_UPSTREAM_IMAGE` in
   `deploy/docker/php-base/Dockerfile`.
4. Increment `PHP_BASE_TAG` in `deploy/Makefile`.
5. Update the same versioned base reference in:

   - `deploy/docker/manager/Dockerfile`
   - `apps/manager/Dockerfile`
   - `apps/manager/compose.yaml`
   - `apps/manager/.env-example`

6. Confirm that all active references agree:

   ```bash
   rg -n "quick-quiz-php-base|PHP_BASE_TAG" \
     deploy apps/manager \
     --glob '!**/vendor/**'
   ```

Only add extensions or operating-system packages required by every Manager
image. Keep Composer, Git, unzip, and development tools in downstream layers.

## Build and validate locally

Export a multi-platform OCI artifact without publishing:

```bash
make -C deploy php-base \
  BUILDER=multiarch \
  DIST_DIR=/tmp/quickquiz-php-base \
  OUTPUT=oci
```

Load each platform separately into the local Docker daemon:

```bash
make -C deploy php-base \
  BUILDER=multiarch \
  PLATFORM=linux/amd64 \
  PHP_BASE_REPOSITORY=quickquiz-php-base-test \
  PHP_BASE_TAG=amd64 \
  OUTPUT=load

make -C deploy php-base \
  BUILDER=multiarch \
  PLATFORM=linux/arm64 \
  PHP_BASE_REPOSITORY=quickquiz-php-base-test \
  PHP_BASE_TAG=arm64 \
  OUTPUT=load
```

Test PHP, the required extensions, PHP-FPM, and ARM emulation:

```bash
docker run --rm --platform linux/amd64 \
  quickquiz-php-base-test:amd64 \
  php -r 'foreach (["PDO", "pdo_pgsql", "pdo_sqlite", "SimpleXML", "Zend OPcache"] as $extension) { if (!extension_loaded($extension)) { exit(1); } } echo PHP_VERSION, " ", php_uname("m"), PHP_EOL;'

docker run --rm --platform linux/arm64 \
  quickquiz-php-base-test:arm64 \
  php -r 'foreach (["PDO", "pdo_pgsql", "pdo_sqlite", "SimpleXML", "Zend OPcache"] as $extension) { if (!extension_loaded($extension)) { exit(1); } } echo PHP_VERSION, " ", php_uname("m"), PHP_EOL;'

docker run --rm --platform linux/amd64 \
  quickquiz-php-base-test:amd64 \
  php-fpm -t

docker run --rm --platform linux/arm64 \
  quickquiz-php-base-test:arm64 \
  php-fpm -t
```

Review and commit the change before publishing:

```bash
git diff --check
git diff -- deploy/docker/php-base deploy/docker/manager \
  apps/manager/Dockerfile apps/manager/compose.yaml \
  apps/manager/.env-example deploy/Makefile

git add deploy/docker/php-base deploy/docker/manager \
  apps/manager/Dockerfile apps/manager/compose.yaml \
  apps/manager/.env-example deploy/Makefile

git commit -m "build(manager): update multiarch PHP base"
git status --short
```

The final command must show a clean working tree before the image is published.

## Publish

Authenticate and publish the versioned tag plus `latest`:

```bash
docker login
make -C deploy php-base BUILDER=multiarch OUTPUT=push
```

The first authorized push can create the Docker Hub repository automatically.
The repository is currently public.

## Verify the publication

Inspect both published tags:

```bash
docker buildx imagetools inspect \
  robmoraes/quick-quiz-php-base:<versioned-tag>

docker buildx imagetools inspect \
  robmoraes/quick-quiz-php-base:latest
```

Confirm that:

- both tags have the same top-level digest;
- `linux/amd64` and `linux/arm64` manifests are present;
- no versioned tag from an earlier release was replaced.

Build the downstream Manager for both platforms without publishing it:

```bash
make -C deploy manager-fpm \
  BUILDER=multiarch \
  MANAGER_FPM_TAG=php-base-test \
  DIST_DIR=/tmp/quickquiz-manager \
  OUTPUT=oci
```

## Recovery

Manager images pin a versioned base tag. If a published revision is defective,
return the Manager references to the previous known-good revision and rebuild
the Manager images. Publish the correction as a new base revision instead of
overwriting the defective versioned tag.
