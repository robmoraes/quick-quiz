# Tagged theme selection production release

Historical record. For current quiz storage, versions, backups, and eligible
rollback images, use the [2026-10-11 PostgreSQL runtime release](postgresql-quiz-runtime-release.md).
The API `0.8.0` rollback below is not eligible for the PostgreSQL runtime.
Any deliberate legacy-provider fallback must follow the current runbook.

## Deployment on 2026-10-10

Deployed to `quickquiz-beta-ec2` (`i-043c1e8324ef79e4c`, `34.207.253.17`),
using the existing single-node Docker Compose stack. Source: PR #46, merge
commit `4d1c42e313c941ad0098f32e8ba265f9f2187085`.

| Service | Previous version | Deployed version | Release workflow |
| --- | --- | --- | --- |
| Quiz API | `v0.7.0` | `v0.8.0` | [API workflow](https://github.com/robmoraes/quick-quiz/actions/runs/38052527881) |
| Manager FPM and Web | `v0.12.0` | `v0.13.0` | [Manager workflow](https://github.com/robmoraes/quick-quiz/actions/runs/38052690822) |
| SPA Dev | `v0.8.0` | `v0.9.0` | [SPA workflow](https://github.com/robmoraes/quick-quiz/actions/runs/38052822263) |

Tags were pushed separately, waiting for each workflow to succeed. The images
are multiarch; this instance runs their amd64 variants. Published index digests
were recorded after pulling and the corresponding image IDs verified against
the running containers:

```text
robmoraes/quick-quiz-api:v0.8.0
sha256:b79aecf5a20f2e15d9e24f221ea8814218d96a1e1285032bd243874338f2a315
robmoraes/quick-quiz-manager-fpm:v0.13.0
sha256:19dad36d30a36b3688775d79f33bd6caeab3da43de52e0f1d46ec0d62ea50d2c
robmoraes/quick-quiz-manager-web:v0.13.0
sha256:ade0718cbae1ed25ab414825bb2510214e48caa668bd171af002476f910fdd31
robmoraes/quick-quiz-dev:v0.9.0
sha256:598fb4db3fa59abf5592e07774a863b2e7575bf7aadb9da0bf910533622383e8
```

## Changes applied

1. Backed up PostgreSQL, all published S3 objects, Compose configuration, and
   running service identities. Both PostgreSQL dumps passed `pg_restore --list`.
2. Applied the additive migration `0003_theme_tags.sql` using the new Manager
   image in a one-off container, before replacing the running Manager.
3. Verified Manager page rendering, then activated FPM and Web `v0.13.0`.
4. Added `dev` to the existing `dev` theme through the protected administration
   API, retaining all other metadata. Publication advanced from revision 22 to
   **23**, with current and published revisions equal. Only
   `questions/themes.json` changed among **903** S3 objects.
5. Activated API `v0.8.0`, verified discovery and session continuity, then
   activated SPA Dev `v0.9.0` with audience `dev`.
6. Adjusted the existing API startup timeout as described below and revalidated
   the final API container and session continuity.

The migration SHA-256 is:

```text
57a8e1de652b5018afb87c08f41ac0a77af0e368be64063e8dab2afc3971f3c1
```

Current audience associations:

- `dev`: active, tags `[dev]`.
- `dslab`: active, tags `[]`; its existing fixed-theme SPA continues to work.

No additional theme was created. Until another active theme receives the `dev`
tag, SPA Dev displays the selected theme label instead of a multi-option selector.

All production Compose commands use the existing secret overlay:

```sh
cd /opt/quickquiz/compose
docker compose --env-file .env -f docker-compose.yml -f compose.secrets.yml
```

Only Manager FPM/Web, API, and SPA Dev were recreated, using `up -d --no-deps
--wait`. Ads API, SPA DSLab, PostgreSQL, Redis, and Traefik kept their original
container IDs, start timestamps, and restart counts.

## Runtime configuration and startup observation

The production environment now contains:

```dotenv
MANAGER_VERSION=0.13.0
SPA_DEV_THEME_TAG=dev
API_STORAGE_STARTUP_TIMEOUT=120s
```

The Compose Dev environment maps `SPA_DEV_THEME_TAG` to `SPA_THEME_TAG`. No
secrets are exposed to the browser. Existing secret files and mounts are retained.

The first API startup exceeded its former 30-second budget while loading the
S3 question snapshot; Docker retried and the second attempt succeeded. The API
already supports `STORAGE_STARTUP_TIMEOUT`, so the production override was
increased to 120 seconds through `API_STORAGE_STARTUP_TIMEOUT`, with no image
or code change. After recreating only API, its final container became healthy
with **zero restarts**. Two independent disposable sessions verified continuity
across the initial rollout and this configuration adjustment, then were reset.

The SSH allowlist initially contained a previous workstation IP. A temporary
port-22 rule for the current workstation's `/32` enabled deployment access and
was removed after verification; the prior ingress rules were retained.

## Verification

- All nine containers are running; all eight configured health checks passed.
  Public HTTPS health checks for API, Ads API, Manager, Dev, and DSLab returned 200.
- Manager login shows 0.13.0 without the AI selector. Authenticated theme list,
  theme edit, and catalog rendering passed; the theme tag field is available.
  Anonymous administrative API access returned 401.
- `/api/themes` returns `dev` and `dslab`; `?tag=dev` returns only `dev`.
  Unknown tags return an empty list and empty tags return `invalid_tag` (400).
  Existing fixed-theme catalog requests remain valid for both themes.
- A quiz created before the API update retained the identical run state after
  restart. Answer, finish, result, and session reset operations passed.
- Headless Chrome verified the production SPA version 0.9.0, the Dev audience,
  its 12 published topics, and rejection of URL theme/tag overrides. No browser
  errors occurred. Session exhaustion behavior was covered by the release's
  local frontend tests and browser verification.
- Database content hashes are unchanged for topics, translations, questions,
  answers, and topic tags. Theme metadata is unchanged apart from the new tag
  association and its update timestamp. Counts remain 2 themes, 34 topics,
  447 canonical questions, 894 question translations, and 9,292 answers.
- Final updated containers have zero restarts and no application error patterns
  in their logs. The initial failed API startup log was retained separately.

After verification the node had approximately 1,114 MiB available RAM and
2.6 GiB free disk. These are idle observations, not capacity-test results.

## Backups and operational evidence

Protected server backup directory:

```text
/opt/quickquiz/backups/tagged-themes-20261010-bg5zmmus
```

It contains `compose/`, `compose-after/`, `published-content/`, S3 metadata,
service identities, and the PostgreSQL dumps and restore manifests. Dump SHA-256:

```text
manager-before.dump
e42e8e113331c6cc182b41cffc956243c98a3bdaff426f88345e054f3ab58bbf
manager-after.dump
46b26f8113fe6f1810981e410cb900630bb69d8845ed7abd4ab5a4bce1ab8b9f
```

Scripts and verification reports are stored at:

```text
/opt/quickquiz/releases/tagged-themes-20261010
```

Relevant evidence includes `migration.txt`, `images.json`,
`activation-result.json`, `api-startup-timeout.json`, `verification-result.json`,
`browser-verification.json`, `log-verification.json`, database hashes, and S3
metadata before/after. Secret-bearing configuration and real question content
remain on the server; they are not versioned in this repository. Local browser
evidence is ignored under `_temp/releases/tagged-themes-20261010/`.

## Rollback, recovery, and later theme changes

For application rollback, use the previous image versions in the table above
and the protected pre-deploy Compose backup. Roll back SPA Dev before an API
version without `/api/themes`; recreate only the selected services with the
secret overlay. Keep the 120-second API startup timeout. Retain migration 0003
and its associations. Pause publication if rolling Manager back to a version
that omits theme tags; keep a compatible publisher or restore the matching
projection before enabling the tagged SPA again. A database rollback is not
required for an application rollback and does not update the API snapshot.

For another node, follow the [infrastructure recovery runbook](production-stateless-migration.md)
and [PostgreSQL recovery guidance](manager-postgresql-migration.md#recovery-and-future-deployments).
Restore PostgreSQL and the corresponding publication, restore secret mounts,
apply pending migrations, and use the versions and runtime settings above.
Use a current backup if authoring has continued since this deployment. Redis
was preserved during this rollout; rebuilding its ephemeral store does not
restore active sessions.

To expose a new theme on Dev, create or edit it in Manager, activate it and add
tag `dev`, confirm publication, reload API, and check `/api/themes?tag=dev`.
No further SPA build is needed. See the [spec rollout plan](../specs/005-tagged-theme-selection/plan.md#rollout-compatibility-and-rollback).
