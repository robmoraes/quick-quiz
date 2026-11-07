# Tasks: Manager Runtime Security

Status: Initial application implementation, manual PHP base publication and
Manager 0.15.1 release verified. The November dependency maintenance prepares
Manager 0.15.2; its release and infrastructure acceptance remain separate.
See [verification.md](verification.md).

## Stage 1 — Corrected Runtime

1. [x] Confirm official fixes and exact upstream artifacts (R1; A1).
   - Select supported patched PHP 8.3/Alpine and verify both platform manifests.
   - Record source versus deployed-version uncertainty; live inventory remains
     infrastructure-owned task 7 rather than an assumption from this source.

2. [x] Update the PHP base and all active consumers (R1, R2; A1, A2).
   - Add shared startup INI disabling upload progress; preserve extensions/limits.
   - Align Makefile, local/production Dockerfiles, Compose defaults and examples.
   - Validation: both architectures build; effective local PHP/FPM versions and
     settings match the target; historical records retain their original values.

3. [x] Audit locked dependencies and final runtime artifacts (R3; A5, A6).
   - Cover PHP base, Manager FPM and Manager Nginx on both architectures.
   - Correct relevant dependency/OS findings and record bounded triage decisions.
   - Validation: complete reports identify digests, platforms and audit errors;
     incident CVEs are fixed rather than suppressed.

4. [x] Verify application compatibility locally (R2; A3, A4, A9).
   - Run Manager PHPUnit/PostgreSQL checks with synthetic data.
   - Verify login, Redis session across FPM replacement, logout, CSRF, bearer
     rejection/authenticated CRUD and file import on corrected images.
   - Validation: AMD64 full suite and both-platform runtime/HTTP smoke checks;
     no production connectivity or secrets in reports.

5. [x] Prepare and publish the corrected application release (R1; A1, A10).
   - [PR 54](https://github.com/robmoraes/quick-quiz/pull/54) merged; Manager
     0.15.1 published through the app-scoped tag. The
     [release workflow](https://github.com/robmoraes/quick-quiz/actions/runs/40422827256)
     passed audits and HTTP acceptance before promoting the tested images.
   - Base `8.3.36-alpine3.24-r1` published manually and pinned by index digest
     across active consumers. Rebuilding candidates from that published base,
     auditing all six platform artifacts and HTTP acceptance passed. CI reuses
     the base without building or publishing it automatically.
   - Update PHP base/security release instructions, Manager changelog/version
     where applicable, then follow the normal commit/PR/release sequence.
   - Publish the base manually, rebuild Manager and record immutable image refs
     and release-time audit evidence; no automatic base build/deploy is added.
   - Validation: registry manifests match tested artifacts on both platforms;
     generic handoff explains runtime checks and recovery on a corrected base.

## Stage 2 — Prevent Recurrence

6. [x] Automate Composer and multi-platform image audits (R3; A5–A7).
   - Add relevant PR, daily and manual checks with minimal read-only permissions.
   - Cover exact source/base and published release refs; identify incomplete
     coverage and integrate results into Manager release approval.
   - Validation: synthetic reports cover findings, errors, missing platforms,
     valid scoped exceptions and expiry; real checks emit sanitized evidence.
   - Workflow definitions pass actionlint; matching published-base build/audit/HTTP
     commands pass locally. PR 54 and the release checks passed; scheduled
     execution detected new dependency advisories after release, as required by A7.

## Infrastructure-Owned Acceptance

7. [ ] Verify and deploy patched Manager images (R1, R2; A1–A4).
   - Infrastructure owner records old/new live digests and FPM settings,
     replaces all workers and verifies browser/API behavior and Redis continuity.
   - Validation: runtime evidence comes from the deployed FPM image; static
     `/healthz` or a published tag alone cannot close this task.

8. [ ] Restrict administrative ingress before PHP (R4; A8, A9).
   - Infrastructure owner selects/records human and machine access policy.
   - Validation: denied sources and forged forwarding headers never reach FPM;
     allowed clients retain login/token checks; no direct port/network bypass.
   - Keep node-specific settings, credentials and acceptance evidence private.

9. [ ] Close application and production outcomes separately (R1–R4; A10).
   - Link automated application evidence and the sanitized infrastructure outcome.
   - Confirm safe rollback guidance and any outstanding finding's owner/expiry.
   - Full completion requires tasks 1–8; a patch release may proceed after
     stage 1 without claiming that stage 2 or ingress hardening is complete.

## November Dependency Maintenance — Manager 0.15.2

10. [x] Triage the recurring audit failure (R3; A5, A7).
    - The first failing scheduled run on 2026-11-03 identified libexpat in
      Manager Nginx. By 2026-11-07 the audit also reported affected Symfony
      production packages. Published PHP base/FPM OS scans remained approved.

11. [x] Update the affected application/image dependencies (R2, R3; A3–A7).
    - Resolve the lock with PHP 8.3.36, upgrading the eight affected Symfony
      packages plus required Console/DependencyInjection dependencies to 7.4.23.
    - Require libexpat >=2.10.0-r0 in Manager Nginx. Prepare 0.15.2 metadata
      and retain the already approved shared PHP base.

12. [x] Complete local acceptance (R2, R3; A3–A7, A10).
    - PHPUnit/PostgreSQL: 221 tests, 923 assertions, no failures or skips.
      Six image audits and both-platform HTTP acceptance passed without
      blocking findings or exceptions. See the
      [sanitized evidence](evidence/2026-11-07-dependency-maintenance.json).

13. [ ] Publish the patch through the normal commit/PR/tag release (R3; A10).
    - Hand approved release digests to the infrastructure owner after publication.
      Local candidate evidence alone does not approve production deployment.
