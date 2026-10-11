# Tasks: PostgreSQL Quiz Runtime

Status: Approved, implemented, and deployed on 2026-10-11 after separate user
authorization; remaining capacity/fallback gates are identified below.
References are to [spec.md](spec.md). See [verification.md](verification.md),
the [deployment record](../../runbooks/postgresql-quiz-runtime-release.md),
and the [capacity report](evidence/local-100k-200k/README.md) for measured limits.

## 1. Review and Contract Gate

- [x] Accept R1–R7, A1–A16, the proposed capacity budgets, and ADR 004. Confirm
      automatic transactional publication, inactive skeletons, and the legacy
      Redis compatibility stage.
- [x] Accept the runtime data/configuration and HTTP draft contracts. Check
      intentional `apiReloadRequired: false` semantics with administration
      clients and preserve the other public request/response contracts.

## 2. Additive PostgreSQL Contract — R1–R3, R7 / A1–A6, A14–A16

- [x] Add backend/version metadata, active-filtered runtime views, and required
      query indexes. Verify existing content/IDs/tags are unchanged and old
      images still work with their existing publication configuration.
- [x] Add explicit reader-role provisioning and bootstrap/configuration support.
      Test real SELECT privileges and denied writes, DDL, base-table reads, and
      Manager user/session access with the reader credential.
- [x] Add read-only consistent scoped queries and compatibility checks. Verify
      metadata/counts never hydrate full question payloads or unrelated themes.

## 3. Redis Compatibility and Run Snapshots — R4–R6 / A7–A13

- [x] Version the internal run record and capture bounded selected packages,
      translations, fingerprints, provider-specific provenance, and served option
      identities. Test revision-null local/S3 behavior without a database, restart,
      grading, deletion/edit isolation, hardcore behavior, and no public leak.
- [x] Add snapshot-backed explanation generation and content/prompt/model/locale
      cache identities with a fake generator. Test changed answers under the
      same ID and explicit unavailability for unprovable legacy history.
- [x] Add per-session/theme indexing, atomic updates, aggregate consumption,
      expiry pruning, and a dry-run/apply legacy backfill. Test interruption,
      retries, completion blocked by pre-index writers, unchanged TTL, reset,
      locale sharing, and zero global scans in
      player paths.
- [x] Keep version-1 run compatibility with the legacy provider and expose the
      count still requiring it. Verify current questions/results survive and
      final cutover is blocked until that count is zero; do not flush sessions.

## 4. PostgreSQL Player Runtime — R1–R2, R4–R7 / A1–A3, A7–A15

- [x] Replace whole-list repository usage with discovery, aggregate counts,
      bounded selection/hydration, and direct identity operations. Exercise
      local/S3 adapters as well as PostgreSQL using identical synthetic data.
- [x] Wire the bounded database pool, deadlines, secret-file precedence, and
      provider validation. Verify startup without S3 and no full-bank preload.
- [x] Separate new-content validation from captured-run/session validation.
      Test continuation after theme/topic retirement and empty availability for
      the retained session without allowing new runs or exposing drafts.
- [x] Add `/readyz`, preserve `/healthz`, and distinguish unavailable storage
      from not found/exhaustion. Verify PG failure leaves captured Redis runs
      usable and Compose routing does not discard all traffic for that outage.
- [x] Decouple prompt storage and implement the limited PostgreSQL prompt view
      with a shared neutral default. Test missing customization versus outage,
      edited/retired-theme prompts, legacy providers, and unrelated subject areas.
      Confirm instructions are resolved at explanation time, not during run creation.

## 5. Atomic Manager Publication — R3, R7 / A4–A6, A15–A16

- [x] Refactor mutation/publication transaction ownership so content, revision,
      and publication status commit together. Test failures at each stage,
      locale parity, active-parent checks, and topic-tags-only isolation.
- [x] Wire unselected storage adapters lazily/conditionally. Test Manager boot,
      login, quiz CRUD/import/restore, and prompt saves without S3 or legacy
      question mounts; keep Ads/export dependencies isolated.
- [x] Preserve legacy storage publication behind its provider. Add backend
      compatibility guards and reject implicit promotion of a pending legacy
      revision. Verify all database-mode CRUD performs zero storage I/O and
      zero full-bank render/hash operations.
- [x] Update publication responses, Manager messages, and canonical admin
      OpenAPI. Verify unchanged paths/bodies/envelopes, nullable export fields,
      idempotent publication calls, and reload false in PostgreSQL mode.

## 6. Exports, Local Integration, and Capacity — A1–A16

- [x] Add explicit streamed legacy export with expected revision, dry-run/apply,
      logical checksums, and independent export status. Verify JSON/tag/prompt
      compatibility, retained inactive packages, exact inventory in an isolated
      target, bounded memory, and no mutation of publication revision.
- [x] Prepare Compose reader credentials/provider settings and a disposable
      integration profile using only Docker PostgreSQL/Redis. Exercise Manager
      edit → committed publication → running API without a restart.
- [x] Run automated acceptance examples with synthetic mixed-domain themes and
      both SPAs' existing API requests. Run relevant Go/Manager/frontend checks
      and merge accepted HTTP additions into canonical OpenAPI.
- [x] Generate/benchmark the 100k profile and doubled unrelated content. Record
      p95, RSS, startup, query/Redis counts, pool waits, fixture sizes, images,
      hardware, and failures against the proposed budgets. Shared-host constraints
      are explicit; full-node release acceptance remains below.

## 7. Recovery and Release Preparation — A10, A14–A16

- [x] Automate reconciliation/comparison and backend activation with expected
      revision. Rehearse write freeze, zero legacy-provider-dependent runs,
      provider cutover, and version-2 session continuity on disposable data.
- [x] Rehearse PostgreSQL-compatible application/provider recovery and fresh
      local-export fallback with a version-2-compatible image. Prove stale
      exports/pending revisions block fallback/cutover; retain schema/data and
      record legacy reload duration. Real S3 release rehearsal remains below.
- [x] Update architecture, environment examples, administration usage, and
      deployment/recovery runbooks. Mark ADR supersession only upon acceptance;
      mark implementation/deployment status only when actually completed.
- [x] Review diff/evidence for secrets, real bank fixtures, unrelated service
      changes, accidental topic-tag exposure, and contract/spec drift. Prepare
      versions/tags/deployment in the separately authorized release step.

## Separately Authorized Release Gates

- [x] Prepare API `0.9.0` and Manager `0.14.0` versions/changelogs for review.
      Public and Manager OpenAPI documents are versioned `0.4.0` and `0.5.0`.
- [x] After merge, publish compatible images from app-scoped tags and retain
      v2-capable rollback images.
- [ ] Verify the proposed 100k full-stack capacity budget on the target
      2-vCPU/2-GiB node;
      local API/PG/Redis caps exclude Manager, Ads, SPAs, proxy and shared-host
      effects. Functional production verification passed with 18,007 canonical
      questions; the proposed 100k full-stack capacity budget was not exercised.
- [ ] If choosing S3 fallback, export to a fresh isolated target, verify its
      entire inventory and measure the actual legacy reload. The completed
      cross-service fallback rehearsal used local storage; no AWS resources
      were created in this implementation.
- [x] Back up production, schedule the write freeze/compatibility window, then
      obtain explicit production release/deployment authorization.
- [x] Perform the authorized coordinated cutover, verify live publication and
      captured-run continuity, remove synthetic content, and record recovery
      artifacts. No legacy-dependent Redis runs remained at the frozen index pass.

## Documentation Verification

Performed on 2026-10-11:

- Two YAML documents parsed without duplicate keys; all 17 local OpenAPI
  references resolved.
- All 40 local Markdown links resolved, including two heading anchors.
- Nine proposal/index/reference documents passed newline/whitespace checks;
  requirement IDs R1–R7 and acceptance IDs A1–A16 are unique and complete.
- HTTP publication profiles passed 18 positive/negative schema examples,
  including required PostgreSQL backend/reload fields and legacy compatibility.
  Equality between revision properties remains an integration-test invariant.
- Independent reviews covered publication/rollback, Redis compatibility, and
  domain-neutral prompts/storage dependencies. Their findings are incorporated.

These initial checks validated the documentation before implementation.
Runtime tests, capacity measurements and disposable migration/recovery rehearsals
are now recorded in [verification.md](verification.md). The separately authorized
production deployment is recorded independently; unchecked gates remain open.
