# Verification: Manager Runtime Security

Verified on 2026-10-26. Application changes are prepared on
`fix/manager-php-security`; the PHP base has been published manually and audited.
Manager images still await the normal PR/merge/tag release; production deployment
has not been performed by this change. The [initial local evidence](evidence/local-validation.json)
records pre-commit OCI candidates; [publication evidence](evidence/php-base-publication.json)
records the approved registry base and subsequent clean-source verification.

## Corrected Artifacts

- PHP 8.3.36 FPM on pinned official Alpine 3.24 upstream, with PDO PostgreSQL,
  PDO SQLite, JSON and OPcache available on both architectures.
- Shared startup INI disables session upload progress. An internal FastCGI
  request to each real test pool confirms `upload_progress=0`, `memory_limit=128M`,
  `post_max_size=2M` and `upload_max_filesize=2M`.
- Refreshed Alpine packages remove the cURL/Brotli HIGH findings observed in
  the initial scan. No exception is used.
- Symfony HttpFoundation/YAML 7.4.21 resolve the production Composer advisories
  for CVE-2026-108082 and CVE-2026-109413. Other locked package versions are unchanged.
- Manager Nginx uses pinned official 1.30.6 with refreshed Alpine packages.
- Manager version/changelog and local defaults are prepared for 0.15.1.

## Executed Checks

| Check | Result |
| --- | --- |
| Full Manager PHPUnit suite against dedicated PostgreSQL, final PHP base and source | 221 tests, 923 assertions; passed |
| Security failure-policy, unavailable-feed/Composer/database, OCI coverage and pinned-base checks | 14 tests; passed |
| Local multiarch base, Manager FPM and Manager Nginx builds | AMD64 and ARM64; passed |
| Trivy runtime image audit with complete OS inventory | All six platform artifacts passed; zero HIGH/CRITICAL findings |
| Locked production Composer audit | No advisories; no install/plugins/scripts executed by the audit |
| Upstream PHP release feed and actual runtime probes | Supported 8.3.36; all PHP/FPM image probes passed |
| Synthetic HTTP acceptance using final audited images | Both platforms passed all checks |
| Actionlint 1.7.12, embedded shell syntax and Markdown/whitespace checks | Passed locally |

HTTP acceptance covers invalid login, session renewal, missing/invalid bearer
tokens, invalid CSRF without mutation, authenticated browser/API writes,
multipart JSON prompt import, Redis session continuity after replacing FPM,
logout and Secure/HttpOnly/SameSite cookies behind the simulated trusted proxy.
Each architecture uses a new self-owned Compose project/database and removes
its containers and volumes afterward. No shared preview or production bank is used.

The internal pool probe is a test-only FastCGI client with an ephemeral PHP file
inside the disposable FPM container. It adds no application HTTP diagnostic route
and is not copied into runtime images.

The final audit uses pinned Trivy 0.76.1 and Composer 2.10.1, records the advisory
database timestamp and source/lock metadata, and retains lower-severity findings
in raw reports. A passed audit is a timestamped policy result, not proof that
unknown vulnerabilities or historical compromise are absent.

## Delivery Boundaries

The workflow builds only Manager candidates; it pulls the manually published PHP
base. A dedicated helper test enforces that `--published-base` does not build the
base. Local rehearsal can build a base before publication. No base build/push
or production connectivity has been added to GitHub Actions.

Manager release automation audits the actual versioned candidates and exercises
them over HTTP before promoting the audited index references to `latest`.
Failures retain an unsuccessful release and its evidence, without that promotion.
Scheduled checks cover source-selected artifacts, not arbitrary deployed versions.

Raw local reports and OCI archives remain under ignored
`_temp/manager-security/`. The working tree was not yet committed when these
initial candidate images were built; their source revision plus `source_dirty`
must not be represented as immutable published release provenance. Subsequent
published-base verification records clean source revision `0380e73`, all six
audits and both-platform HTTP acceptance. The PHP base was built from `95b0395`.

## PHP Base Publication

Published and approved `robmoraes/quick-quiz-php-base:8.3.36-alpine3.24-r1`:

- Index digest: `sha256:7e4cd839953eba6479c23b77706d3a98a31aa7181e84efc9a30ef3f6cac033e3`.
- AMD64 and ARM64 manifests are present. `latest` was promoted to that same
  index only after the published-base audit and downstream HTTP acceptance passed.
- All active default consumers pin the same tag/index digest. The audit uses
  this same immutable reference and supports an explicit custom-base override.
- Rebuilt Manager FPM/Nginx candidates from the published base passed six image
  audits, 221 PHPUnit tests/923 assertions and both-platform HTTP acceptance.

Pending normal release work: complete the PR/merge and publish Manager 0.15.1
through the app-scoped tag workflow. Publishing the shared base does not upgrade
already built or running Manager images. Follow the
[base runbook](../../runbooks/manager-php-base-multiarch-release.md) for future updates.

Production inventory, worker replacement, restricted ingress, no direct bypass
and application recovery acceptance remain with the infrastructure owner. See
the [security handoff](../../runbooks/manager-runtime-security.md). Tasks 5, 7, 8
and final closure remain open; this does not declare the full production security
change complete.
