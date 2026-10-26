# Manager runtime security

Application packaging and verification for [spec 008](../specs/008-manager-runtime-security/spec.md).
Production ingress, live inventory and deployment belong to the infrastructure
owner under the [deployment responsibility boundary](../deployment.md).

## Local acceptance

Requires Docker, Buildx, Python 3 and the existing ARM emulation setup. Install
no application packages or scanners on the host. Use synthetic, disposable data.

```sh
python3 -m unittest discover -s deploy/security -p 'test_*.py'
sh deploy/security/build-candidates.sh /tmp/quickquiz-manager-candidates
python3 deploy/security/audit.py --output /tmp/quickquiz-manager-audit \
  --archive php-base=/tmp/quickquiz-manager-candidates/php-base.oci.tar \
  --archive manager-fpm=/tmp/quickquiz-manager-candidates/manager-fpm.oci.tar \
  --archive manager-web=/tmp/quickquiz-manager-candidates/manager-web.oci.tar
python3 deploy/security/smoke.py /tmp/quickquiz-manager-audit/summary.json
```

Candidate builds use a named OCI build context for the base, so a PR can be
rehearsed locally before that base is published. CI instead calls the helper
with `--published-base` and pulls the manually published base: no PHP base build
or push is introduced in GitHub Actions. Publish the corrected base before opening
the implementation PR or running its candidate-image checks. Audits use pinned Composer/Trivy Docker
images, not host installations. Each OCI platform is selected explicitly and
scanned; PHP/FPM startup settings are checked on both architectures.
The HTTP acceptance creates its own randomly named Compose project with
PostgreSQL, Redis and the audited Manager images. It binds an ephemeral localhost
port, creates synthetic credentials/content, tests login/CSRF/token/import and
session survival after FPM replacement, then removes its containers and volumes.
It does not connect to an existing Manager, database or question bank.

PHP in the official image is compiled outside the Alpine package manager.
Therefore OS scanning alone cannot establish its patch status. The audit also
checks actual PHP versions against both the incident minimum (8.3.36) and the
current supported 8.3 release returned by PHP.net. An unavailable release feed
is incomplete verification, not a clean result. Keep reviewing upstream advisories.

The full PHPUnit/PostgreSQL suite remains part of the existing Manager PR/release
checks. Run it locally using the corrected PHP image and a dedicated synthetic
test database; never point integration-test URLs at a shared environment.

## Recurring checks and release approval

`Manager security audit` runs for relevant PRs, daily at 07:23 UTC and by manual
dispatch. PRs build only Manager candidate images without registry writes and
audit the manually published base. Scheduled/manual
checks resolve the source's base tag and latest released Manager version from
its changelog, using the existing `DOCKERHUB_MANAGER_*_IMAGE` repository variables
or reusable Makefile defaults. They do not attest to older deployed releases.

To audit explicit public release references without registry write credentials:

```sh
python3 deploy/security/audit.py --output /tmp/quickquiz-manager-release-audit \
  --image php-base=example/php-base:8.3.36-alpine3.24-r1 \
  --image manager-fpm=example/manager-fpm:vX.Y.Z \
  --image manager-web=example/manager-web:vX.Y.Z
```

Image references must resolve to both supported platforms. Reports retain the
exact platform digests, PHP settings, Composer lock hash, audit timestamp and
scanner/database versions. Do not publish question banks, cookies, tokens,
resolved production Compose, environment dumps or raw PHP environment output.

The policy fails on production Composer advisories and runtime HIGH/CRITICAL
findings, including unfixed ones. Missing platforms, unsupported reports, stale
databases (over 72 hours), download/probe/scan errors and unavailable PHP release
information produce an unsuccessful `inconclusive` result. Lower-severity image
findings remain in raw reports. A clean scan means no blockers under this policy
at that timestamp, not absence of all possible vulnerabilities.

Exceptions in `deploy/security/exceptions.json` require review and exact fields:
`artifact`, `platform`, `id`, `package`, `installed`, `reason`, `control`, `owner`,
`created`, `expires`. Scope each entry to an exact installed version/platform;
use ISO dates and at most 30 days. Composer findings use artifact `composer` and
platform `all`. Expired/malformed exceptions fail the audit. The incident PHP
CVEs cannot be excepted. The default list is empty.

The Manager release workflow publishes versioned candidates with
`TAG_LATEST=false`, audits the actual published digests, and promotes `latest`
only after both the audit and HTTP acceptance on those images succeed.
Failed/inconclusive releases may leave versioned candidates
in the registry: do not deploy them. The audit workflow itself receives no cloud,
production or registry-write credentials. Security reports are retained as
workflow artifacts; the release summary is the approval result.

## Base publication and infrastructure handoff

Follow the [manual multiarch base runbook](manager-php-base-multiarch-release.md).
Publish a fresh versioned base, verify both platform digests against local
acceptance, update current consumer references (prefer tag plus index digest),
then release rebuilt Manager FPM/Nginx images through the normal PR/tag workflow.
The base remains excluded from automatic image publication and `build-images`.
No database migration or catalog import is needed for this update.

Give the infrastructure owner the source SHA, approved base/Manager references,
index/platform digests and completed audit/HTTP acceptance evidence. Require:

1. Inventory the running FPM version, image digest and effective startup settings
   inside the actual container; do not expose a diagnostic HTTP endpoint.
2. Recreate all Manager workers on corrected images. Verify actual PHP is at
   least the approved patched version and upload progress is off in FPM, with
   Redis sessions and ordinary upload limits preserved. Static `/healthz` only
   attests to Nginx.
3. Preserve data services and verify allowed browser login/import/CSRF and
   administrative bearer clients through the normal HTTPS ingress.
4. Restrict both browser and API access before PHP using the environment's
   selected IP/VPN/identity policy. Verify denied sources do not reach FPM,
   forwarded headers cannot forge eligibility, and direct Nginx/FPM access cannot
   bypass it. Keep login/bearer checks for allowed clients; do not add an open API
   exception to accommodate automation.
5. Record patch deployment separately from ingress hardening. Apply neither
   production access policy nor an infrastructure-specific allowlist here.

For recovery, use a previous compatible application revision rebuilt on the
corrected base, or restrict administrative exposure while producing it. Do not
restore a known vulnerable runtime to public access. If compromise is suspected,
follow the infrastructure incident procedure, preserve evidence and rotate
affected secrets; a successful upgrade is not evidence that no intrusion occurred.
