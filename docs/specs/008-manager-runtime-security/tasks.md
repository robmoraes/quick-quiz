# Tasks: Manager Runtime Security

Status: Application implementation and manual PHP base publication verified.
Normal application PR/tag release and infrastructure acceptance are
pending. See [verification.md](verification.md).

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

5. [ ] Prepare and publish the corrected application release (R1; A1, A10).
   - Preparation complete: Manager 0.15.1 metadata/changelog, local multiarch
     candidates and audit/HTTP evidence. Manager PR/merge/tag steps remain pending.
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
     commands pass locally. GitHub execution awaits PR; no scheduled workflow
     execution is claimed from this working tree.

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
