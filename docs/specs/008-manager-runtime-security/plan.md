# Plan: Manager Runtime Security

Status: Application implementation and the published PHP base verified against the approved
[specification](spec.md). Manager release and infrastructure acceptance remain
pending; see [verification.md](verification.md).

## Ownership and Sequence

This repository owns PHP/Manager packaging, reusable local configuration,
dependency checks and sanitized release instructions. The private infrastructure
workspace owns live inventory, ingress policy, networks, credentials, deployment
and production evidence. Follow [deployment responsibilities](../../deployment.md).

Patch first. Release-time audits and regression checks accompany the corrected
images; recurring CI and external access provisioning can follow separately.
Do not make emergency patch delivery wait for a new access platform.

## Stage 1 — Patched Images and Configuration (R1, R2)

1. Recheck PHP advisories and select an exact official PHP 8.3 FPM Alpine tag
   containing the required fixes. Resolve and verify its multi-platform index
   and availability of both supported architectures. Recheck the related
   [stale-state advisory](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp).
2. Update `deploy/docker/php-base/Dockerfile`, `PHP_BASE_TAG` in
   `deploy/Makefile`, production `deploy/docker/manager/Dockerfile`, local
   `apps/manager/Dockerfile`, `apps/manager/compose.yaml` and `.env-example`.
   Update current deployment guides/runbook examples; preserve historical
   versions in completed specs and records.
3. Set `session.upload_progress.enabled=0` in a small shared PHP startup INI
   file and copy it into the PHP base. Both local and production images inherit
   it. Verify effective FPM configuration after all production INI/pool settings
   are loaded, not only the base's CLI settings.
4. Retain PDO PostgreSQL/SQLite, OPcache and other extensions actually required
   by Manager. Keep the Redis session handler, import support and existing
   resource limits. Do not add a public `phpinfo()`/version endpoint.
5. Build and exercise both platforms using Docker/Buildx and the existing host
   builder/emulation. Publish the base manually only after verification, using
   a fresh versioned tag. Resolve the resulting index digest for downstream
   provenance; prefer an immutable digest in default Manager base references.
6. Rebuild Manager FPM/Nginx and audit the final images, including Nginx/Alpine
   packages. If scans require related patches, update within supported image
   lines with compatibility verification; record any broader change explicitly.
   Upgrade Composer packages only to address actual findings, retaining the lock.

The base remains excluded from `build-images` and automatic publication.
Existing configurable registry repositories remain supported; do not introduce
environment-specific registry or production settings into application code.

## Stage 1 — Verification and Release Audit (R1–R3)

Use containerized Composer's locked production-dependency audit and a maintained
image vulnerability scanner supporting OS and PHP packages. Pin the scanner
distribution/action to a reviewed immutable reference during implementation.
Scan the exact runtime artifacts on both architectures and save sanitized JSON
reports alongside a readable summary. A scan of the builder stage or just an
index manifest is not sufficient.

Release-time checks may initially run locally with the manual base procedure.
They must precede release approval/infra handoff and identify the exact artifacts
approved. Normal publication is not a statement that an image is safe to deploy.
The later CI work automates this gate without requiring a separate image rebuild
for deployment.

Use the existing Manager PHPUnit suite, PostgreSQL integration tests and a
disposable Compose deployment with synthetic data. Exercise authenticated
browser CRUD, CSRF rejection, token-protected CRUD, JSON prompt import and login
session continuity after replacing only FPM. Use a local cookie jar, inspect only
non-secret results and delete ephemeral credentials afterward.

Check PHP version/extensions and effective FPM settings on AMD64 and ARM64.
Run the full application suite on AMD64 and startup/HTTP/session smoke checks on
ARM64; emulation is an operability check, not a production performance estimate.
Retain current request/memory limits and verify file import fits those limits.
No exploit payloads or live production probes are needed for local acceptance.

## Stage 2 — Recurring Audits (R3)

Add a read-only security workflow with relevant PR, daily schedule and manual
dispatch triggers. Audit `composer.lock` without installation/plugins/scripts;
separate production findings from development-only packages. For image audits,
resolve the base reference in the approved source and explicit published Manager
release references. Report source/runtime mismatches and absent artifacts as
incomplete coverage rather than guessing a version or silently using `latest`.
PRs pull the manually published corrected base and build only Manager candidates.
The local rehearsal helper can build a base before publication, but GitHub Actions
must never build or publish that base. A missing manual base publication is an
explicit prerequisite failure, not a reason to add an automatic build fallback.

Reuse the existing registry repository configuration. The workflow needs no
Docker Hub write token or production credentials. PR checks run against trusted,
reviewed scanner tooling without privileged `pull_request_target` execution.
Integrate the same release policy into Manager publication verification; a
failed audit must mark the release unsuccessful and prevent an approved handoff.

Default blocking policy: any production Composer advisory or runtime
HIGH/CRITICAL image finding. Lower-severity findings remain visible for triage.
An exception must be narrowly scoped, justified and expire within 30 days;
expired/malformed exceptions fail the check. Unfixed findings need explicit
triage too. Keep exception data sanitized, reviewed and linked to the report.
Do not add broad package/advisory ignore lists or blanket success on errors.
The two incident CVEs are non-exemptible on an unpatched runtime.

Verify policy behavior using small synthetic scanner/audit reports: clean,
finding, unavailable database, missing platform, valid scoped exception and
expired exception. These checks validate failure handling without depending on
an old vulnerable image or the changing contents of a live advisory database.
Scheduled checks detect drift; they do not automatically publish, deploy or
claim coverage of older images still running in an environment.

## Infrastructure Handoff and Recovery (R4)

Extend the generic PHP base runbook/application release guidance with:

- image versions/index and platform digests, source SHA and audit evidence;
- expected PHP version and upload-progress setting, with a non-public runtime
  verification procedure for the actual FPM process;
- worker replacement and authenticated browser/API acceptance requirements;
- upstream denial for unauthorized sources on both browser/API paths, no direct
  FPM or Nginx bypass, and rejection of spoofed forwarding headers;
- preserving bearer authentication for machine clients through the chosen access
  mechanism, without exposing a public bypass route;
- preservation of data services and recovery using corrected-base images.

Record the handoff here without node names, domains, IP allowlists, production
Compose, VPN credentials or cloud secrets. Deployment-specific records remain
with the infrastructure owner. Application packaging completion and confirmed
production hardening are separate statuses in the task list.

If compromise is suspected, follow the infrastructure incident procedure before
declaring remediation complete. Routine rollback retains patched PHP; rebuild a
previous Manager source revision on the safe base when necessary.
