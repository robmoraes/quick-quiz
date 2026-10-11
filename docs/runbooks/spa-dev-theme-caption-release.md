# SPA Dev theme-caption patch release

Deployed **SPA Dev 0.9.1** on `2026-10-11T19:52:27Z` to
`quickquiz-beta-ec2` (`i-043c1e8324ef79e4c`, `us-east-1d`). The theme caption
now sits above the quiz panel, keeping the panel aligned and the desktop footer
within the viewport.

- Source: [PR #49](https://github.com/robmoraes/quick-quiz/pull/49), merge
  `34ddb0e5af78e95b5fc5fc088cf3fc2b33b1cd31`.
- Tag: `spa-dev/v0.9.1`.
- Successful [release workflow](https://github.com/robmoraes/quick-quiz/actions/runs/38169602969).
- Image: `robmoraes/quick-quiz-dev:v0.9.1`, published for `linux/amd64` and
  `linux/arm64`.
- Image index digest:
  `sha256:efb437fb344e6ca57c2e8a17e5b2d581d4123e996a3e38038b6f83fff560d386`.
- Deployed `amd64` image ID:
  `sha256:826c27629857691564adcfc0642e4c9ce58ee230ffca4f24becc71d55e4d476d`.

## Deployment and verification

Only `QUICKQUIZ_IMAGE_DEV` changed, from `v0.9.0` to `v0.9.1`. The deployment
used the existing Compose files and secrets overlay, recreating only `spa-dev`
with `--no-deps --wait`. The other eight containers retained their IDs, start
times and restart counts. All nine containers were running; all eight configured
health checks passed. Public health endpoints and API `/readyz` returned 200.

Local validation passed ESLint, the production build/type checks and all 29
automated tests. Synthetic layout checks covered desktop, tablet and mobile
sizes and long theme names. See the committed
[desktop](../spa/screenshots/theme-caption-layout-desktop.png) and
[mobile](../spa/screenshots/theme-caption-layout-mobile.png) screenshots.
Production browser checks completed at `2026-10-11T19:56:49Z`, verifying version
`0.9.1` and the SQL → Joins level screen at `1854×1024` and `390×844`:
the caption remained above the aligned, full-width panel, with no horizontal
overflow. These checks used only read
requests and did not start quiz runs.

API `0.9.0`, Manager `0.14.0` and the PostgreSQL runtime remain as recorded in
the [PostgreSQL deployment and recovery record](postgresql-quiz-runtime-release.md).
This patch required no database, content, secret or permanent infrastructure
changes. Final container and rollback-image verification passed at
`2026-10-11T19:57:40Z`.

## Protected operational evidence

```text
/opt/quickquiz/releases/spa-dev-v0.9.1-20261011
/opt/quickquiz/backups/spa-dev-v0.9.1-20261011-slz3wjzp
```

The server-only directories use mode `0700` and protected files use `0600`.
They contain the before/after container and image identities, configuration
backups and deployment/browser verification reports. Local sanitized evidence
is ignored under `_temp/releases/spa-dev-v0.9.1-20261011/`.
Secrets, resolved Compose configuration and real quiz content are not in Git.

Temporary SSH ingress rule `sgr-072dcff7fb3f610ee` was revoked after verification.
A security-group readback confirmed that only the original SSH rule,
`sgr-05af3ef14c51673f4`, remained.

## SPA-only rollback

The previous SPA image is retained on the node as
`quickquiz-rollback-dev:pre-v0.9.1-20261011`, with image ID
`sha256:479b8271452fcd1f3aa993a26198ccf7b08f382697f721380c92af37834bf569`.
Verify that `docker image inspect` prints that ID before changing `.env`.
To revert this visual patch, change only the SPA image and recreate only its
container, using the retained local image:

```sh
cd /opt/quickquiz/compose
docker image inspect quickquiz-rollback-dev:pre-v0.9.1-20261011 --format '{{.Id}}'
sed -i 's|^QUICKQUIZ_IMAGE_DEV=.*|QUICKQUIZ_IMAGE_DEV=quickquiz-rollback-dev:pre-v0.9.1-20261011|' .env
docker compose --env-file .env -f docker-compose.yml -f compose.secrets.yml config --quiet
docker compose --env-file .env -f docker-compose.yml -f compose.secrets.yml up -d --no-deps --pull never --wait --wait-timeout 90 spa-dev
curl --fail https://dev.quickquiz.com.br/healthz
curl --fail https://api.quickquiz.com.br/readyz
```

Keep the current API, Manager, PostgreSQL and Redis configuration and data.
Do not restore an entire older `.env`, Compose directory or database backup to
undo this SPA patch. Backend recovery follows the separate PostgreSQL runbook.
