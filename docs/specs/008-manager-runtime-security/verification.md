# Verification: Manager Runtime Security

Verified locally on 2026-10-26. Application changes are prepared on
`fix/manager-php-security`; no registry publication, GitHub workflow execution or
production change is claimed. The [sanitized evidence](evidence/local-validation.json)
records local OCI platform manifests, not deployable registry release references.

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
| Security failure-policy, unavailable-feed/Composer/database and OCI coverage checks | 13 tests; passed |
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
candidate images were built; source revision plus `source_dirty` in the evidence
must not be represented as an immutable published release provenance record.

Pending normal release work: commit/PR, manually publish the base using the
[base runbook](../../runbooks/manager-php-base-multiarch-release.md), resolve its
registry digests and preferably pin current consumer references, then publish
Manager 0.15.1 through the app-scoped tag workflow after merge. The base must be
available before the implementation PR's candidate-image checks.

Production inventory, worker replacement, restricted ingress, no direct bypass
and application recovery acceptance remain with the infrastructure owner. See
the [security handoff](../../runbooks/manager-runtime-security.md). Tasks 5, 7, 8
and final closure remain open; this does not declare the full production security
change complete.
