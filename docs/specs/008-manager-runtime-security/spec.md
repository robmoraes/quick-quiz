# Feature: Manager Runtime Security

Status: Approved on 2026-10-26. Application implementation verified and the PHP
base published/audited; Manager release and infrastructure acceptance remain pending.
See [verification.md](verification.md).

## Intent

Maintain a patched PHP runtime for the Manager and detect vulnerable dependencies
between application releases. Reduce exposure of this administrative service
before requests reach PHP, while retaining browser access and trusted quiz
administration clients.

The source and cached base image inspected on 2026-10-26 use PHP 8.3.33.
The PHP project released 8.3.36 on 2026-10-21 to fix CVE-2026-109618 and
CVE-2026-109619. Its advisory includes PHP-FPM. Authentication inside the
application does not remove the request-processing surface described by the
advisory. Exploitability of this particular deployment has not been demonstrated.
The effective production version and configuration still require verification
by the infrastructure owner. See the [official advisory](https://www.php.net/archive/2026.php#2026-10-21-5).

## Scope

In scope:

- patched, versioned PHP base and Manager images for `linux/amd64` and
  `linux/arm64`, including consistent local development defaults;
- startup configuration that disables session upload progress tracking;
- dependency and runtime-image auditing at release time and on a daily schedule;
- preserved Manager authentication, CSRF, Redis sessions and administrative API;
- a deployment handoff defining upstream access restrictions, runtime checks and
  recovery responsibilities.

Out of scope:

- changing the PHP major/minor line, framework, UI, quiz model or database schema;
- redesigning login, adding MFA or replacing the administrative bearer token;
- changing player services, their authentication or their public exposure;
- selecting or provisioning a VPN/access gateway, changing production routing,
  networks, secrets or infrastructure from this public repository;
- automatically building/publishing the PHP base or deploying from CI;
- penetration testing, executing exploit payloads or separate human QA artifacts.

## Behavior

### R1 — Patched and attributable runtime

1. The replacement PHP base uses an official, supported PHP 8.3 patch release
   containing both fixes: 8.3.36 is the minimum for this incident. Recheck official
   advisories and image availability at implementation time.
2. Pin the upstream image by exact tag and multi-platform digest. Publish a new
   immutable base tag; retain the manual base publication process.
3. All active local/build defaults select the corrected base. Historical records
   retain their original versions. Manager releases rebuild their runtime from
   this base; restarting an existing container is not an upgrade.
4. Record source revision, upstream/base references, runtime versions, release
   image digests and both platform manifests as sanitized release evidence.

### R2 — Configuration applied before request processing

1. Disable `session.upload_progress.enabled` in startup configuration for local
   PHP and production FPM. It must be effective before request parsing, without
   relying on application `ini_set()` or a diagnostic HTTP endpoint.
2. Preserve session storage in Redis, normal file import and bounded request and
   memory limits. Do not replace patching with unlimited PHP memory or claim the
   upload-progress setting alone fixes both vulnerabilities.
3. Preserve production debug-off behavior, secure session-cookie behavior behind
   the trusted proxy, CSRF checks, password verification, session renewal on login
   and bearer-token validation.

The upload-progress setting follows the PHP project's
[2026-10-23 advisory](https://www.php.net/archive/2026.php#2026-10-23-1).
That additional issue has configuration/application prerequisites; its
exploitability in the Manager is not assumed.

### R3 — Auditing and actionable failures

1. Audit locked production Composer dependencies and the actual runtime images:
   PHP base, Manager FPM and Manager Nginx. Cover each released architecture;
   a clean AMD64 result must not imply ARM64 coverage.
2. Runtime HIGH/CRITICAL image findings and production Composer advisories block
   release approval pending a fix or an explicit, time-bound triage exception.
   Exceptions identify the advisory/package, affected artifact/platform, reason,
   compensating control, owner and expiry. The two fixed RCEs motivating this
   spec cannot be excepted to retain PHP 8.3.33.
3. Do not silently ignore unfixed findings, missing scan databases, failed image
   pulls, absent architectures, scanner errors or incomplete results. Distinguish
   an inconclusive/error check from a completed scan with no findings.
4. Run relevant checks for Manager PRs/releases, daily on the default branch and
   by manual dispatch. Scheduled checks identify the resolved image digests;
   scanning source/current release defaults does not attest to older production
   deployments. The infrastructure owner tracks deployed digests separately.
5. Checks use Docker and read-only, minimum-permission automation without cloud
   credentials, production secrets, production connectivity or registry write
   credentials. Reports include timestamps and tool/database versions where
   available, with a failing check and readable summary for actionable results.

### R4 — Access restricted before PHP

1. The production deployment must restrict Manager access upstream of PHP for
   browser users and machine clients. The infrastructure owner selects the
   mechanism and environment-specific allow rules; application code contains
   neither production IPs nor identity-provider configuration.
2. Rejected requests must not reach FPM, including `/login` and
   `/api/admin/quiz/*`. An allowed network/identity still needs the existing
   application login or bearer token. Neither an unprotected API exception nor
   forwarding-header spoofing may bypass the restriction.
3. FPM must have no public host port. Direct access to Manager Nginx must not
   bypass the upstream policy. Proxy trust and internal service reachability are
   validated in the infrastructure workspace.
4. The PHP patch can ship before access-control provisioning and scheduled
   automation. Report patch delivery and broader hardening separately; the
   entire spec is complete only after both application and infrastructure
   acceptance evidence exists.

## Acceptance Examples

These examples guide automated verification; they are not human test scripts.

| ID | Scenario | Expected result |
| --- | --- | --- |
| A1 | Build both architectures with the corrected base | Each reports a patched PHP 8.3 version, expected extensions and recorded image digests |
| A2 | Inspect local PHP and effective FPM configuration | Upload progress is off before request handling; Redis sessions and configured limits remain enabled |
| A3 | Log in, replace the FPM container and continue | The Redis-backed session survives; logout invalidates it; invalid login remains rejected |
| A4 | Submit a browser mutation with invalid CSRF or call admin CRUD without a valid token | Existing rejection behavior; no content mutation |
| A5 | Run image policy against a HIGH/CRITICAL finding, expired exception or missing ARM64 report | Release approval fails and identifies the affected artifact/check |
| A6 | Composer advisory service or image vulnerability database cannot be reached | Check is unsuccessful/inconclusive, never reported clean |
| A7 | A new advisory appears after the last release | Daily audit evaluates the locked dependencies and selected image digests and surfaces a failing check |
| A8 | A source outside the production access policy requests login or admin CRUD | Denied before FPM, including with forged forwarded headers |
| A9 | An allowed browser or machine client uses the restricted ingress | Login/CSRF and bearer-token contracts continue to apply; ordinary imports and CRUD work |
| A10 | Recovery requires reverting Manager application code | Use an image built on a patched base, or keep access restricted while producing one; do not reopen an old vulnerable image |

## Data and Contracts

No HTTP routes, request/response schemas, database migrations or persisted quiz
formats change. Existing `__FILE` secret handling remains intact. No new
application secret is required; any external access-control credentials belong
to the infrastructure workspace.

Security reports contain package names, advisory identifiers and image metadata,
not question banks, request bodies, tokens, environment dumps or user sessions.
Existing local build commands remain usable without host package installation.

## Rollout and Operations

Deliver in two independently reviewable stages:

1. Patch/configuration, automated application verification, release-time audits,
   manual base publication, rebuilt Manager release and infrastructure handoff.
2. Recurring checks and upstream access restriction with their own evidence.

The infrastructure owner verifies the running FPM version/settings and image
digest, recreates all old workers, applies access policy and records production
acceptance. Preserve PostgreSQL, Redis and player services. No catalog import or
database migration is required. A static Nginx `/healthz` alone does not prove
the PHP runtime was patched.

Recovery must preserve the patched runtime. If a safe application rollback image
does not exist, restrict administrative exposure while rebuilding it. Do not
restore a known vulnerable public runtime as routine rollback. Investigate logs
and rotate affected credentials if evidence indicates compromise; upgrading by
itself is not proof that historical compromise did not occur.

## Review and Deferred Decisions

- Application design is bounded by the [plan](plan.md) and [tasks](tasks.md).
- Access mechanism, allow rules, effective live versions and coordinated deploy
  timing: infrastructure owner, before production hardening is declared complete.
  These decisions do not block preparing the patch.
- Actual Composer/OS findings and any supported Nginx/Alpine patch needed to
  clear the release policy are determined from scans, not presumed from tags.
- No new architecture ADR is required for a PHP patch/configuration change.
  A consequential external access-system choice is recorded by its owner.
