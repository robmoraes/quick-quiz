# ADR-004: Read Playable Quiz Content From PostgreSQL

- **Status:** accepted, implemented and deployed (2026-10-11); operational records
  are maintained separately under the [deployment boundary](../../deployment.md)
- **Date:** 2026-10-11
- **Decision owners:** QuickQuiz maintainers
- **Scope:** Manager publication, Quiz API content reads, and migration operations
- **Supersedes:** the S3 runtime/publication boundary in
  [ADR 002](002-postgresql-quiz-authoring-s3-publication.md) and the startup
  snapshot constraints in [ADR 003](003-tagged-theme-discovery.md), partially
- **Superseded by:** [ADR 005](005-generic-quiz-web.md), partially, for the
  public topic-tag discovery boundary

Accepted follow-up: [ADR 005](005-generic-quiz-web.md) extends topic tags into
public discovery for the new generic Web. That follow-up is implemented and
verified locally, without production deployment; the deployed runtime and
recovery behavior recorded here remain current.

## Context

PostgreSQL already holds the authoritative themes, topics, localized questions,
and ordered answers. Manager owns writes through its web interface and protected
administration API. Today a validated save also attempts synchronous JSON
publication to S3; the Go Quiz API loads that projection into memory at startup.
Database changes can therefore remain durable but unpublished after an S3
failure, and successful publication still requires an API reload.

The catalog is expanding beyond development and IT. The published content
addition contains 32 themes, 439 topics, and 17,560 new canonical questions.
Including retained content, the catalog totals 18,007
canonical questions and 36,014 localized questions. Measured serial S3 startup
took 16 minutes 25 seconds, with observed API memory use around 50 MiB. These
observations establish startup request amplification and a growing reload cost;
they do not establish that the present dataset exhausts memory.

Maintaining one object per localized question makes startup depend on catalog
size and remote object-request latency. Continuing to preload every future
subject also makes startup and process memory grow with content that most
requests never use. PostgreSQL can serve bounded catalog and question reads
without changing the player-facing concepts or adding another running service.

The existing publication behavior is automatic after a validated save. There
is no separate editor approval or scheduled release workflow to preserve.
However, moving the reader directly to mutable authoring tables without defining
publication would silently expose legacy changes whose S3 publication failed.
That transition requires explicit reconciliation.

## Decision Drivers

- Make startup independent of downloading the complete catalog.
- Support catalog growth across unrelated subjects with bounded API memory.
- Preserve Manager ownership, public contracts, identifiers, locale parity,
  difficulty rules, and theme/topic activation semantics.
- Make validated content changes visible to new operations without API reload.
- Preserve consistent question selection and stable answers for existing runs.
- Reuse the existing PostgreSQL, Redis, and single-node Compose environment.
- Keep cutover, database access, export, and rollback explicit and observable.

## Considered Options

### Option A: Keep S3 Publication and Optimize the Startup Snapshot

Fetch objects concurrently or replace individual files with larger bundles,
then continue loading the entire playable catalog into each API process.

**Advantages**

- Preserves database-independent player reads and the existing boundary.
- Can substantially reduce the immediate object-request startup cost.

**Disadvantages**

- Startup and memory still scale with the entire catalog.
- Content freshness still requires publication and snapshot replacement.
- Bundling changes the publication format and retains a second representation.

### Option B: Read the Existing PostgreSQL Catalog Through Versioned Views

Manager validates and commits content with automatic publication in the same
transaction. The Go API reads a stable, versioned view contract with a dedicated
read-only role. Redis retains only the selected questions needed by each run.

**Advantages**

- Removes object downloads and full-catalog loading from API startup.
- Reuses the normalized authoritative records without duplicating live content.
- Makes validated saves atomically visible to subsequent operations.
- Keeps the writer and validation rules in Manager.

**Disadvantages**

- New catalog reads and run creation depend on PostgreSQL availability and
  latency.
- Reader views, indexes, connection budgets, and compatible migrations become
  part of the cross-service contract.
- API deployments need a narrowly scoped database credential.

### Option C: Maintain a Separate Published PostgreSQL Projection

Copy validated authoring data into separate live tables or immutable catalog
revisions, then atomically promote the published version for API readers.

**Advantages**

- Supports independent draft and release lifecycles or retained catalog releases.
- Separates a published schema from mutable authoring storage.

**Disadvantages**

- Duplicates live content and adds synchronization, retention, and recovery work.
- Full-catalog copies or rendering would make ordinary saves grow with total
  catalog size unless a further incremental versioning design is introduced.
- Introduces a release boundary that the current automatic-save publication
  workflow does not require.

## Decision

Propose Option B. This ADR remains proposed until the associated specification
and decision are approved; it does not authorize implementation or cutover.

### Ownership and Publication

Manager remains the only application that writes quiz content. The existing
normalized PostgreSQL tables become both the authoritative and live source.
Versioned read views expose the player-relevant schema to the Go API without
granting access to arbitrary Manager tables. A view contract is a maintained
interface and does not require another set of live catalog tables.

In PostgreSQL runtime mode, a validated CRUD operation commits its content and
the existing catalog/published revision bookkeeping in one transaction.
Automatic publication succeeds with that commit; validation or transaction
failure exposes none of the proposed change. It does not require synchronous S3
I/O, a full-catalog render, a full-catalog copy, or a full-catalog content hash.
Existing metadata-only changes that do not create a playable revision retain
that behavior.

Themes or topics with `active: false` remain unpublished to players. Activating
content uses the same validation and atomic commit boundary. This decision adds
no question approval queue, separate draft workflow, or release scheduler.

Theme tags retain their discovery and audience-selection meaning. Topic tags
remain Manager-only metadata. SPA Dev's environment-owned audience tag remains
a navigation policy rather than authorization. Existing fixed-theme clients,
SPA DSLab, locale rules, difficulty rules, and public resource identifiers remain
compatible. No subject-specific assumption is added to the catalog contract.

### Read Consistency and Run Stability

Each content operation reads one consistent PostgreSQL revision. Related
catalog metadata, availability counts, and question selection within that
operation must agree, including while Manager commits changes. Subsequent
operations may observe newer commits; a sequence of separate HTTP requests is
not a permanently pinned catalog snapshot. New runs use the latest committed
revision visible to their read transaction.

Reads are bounded by the requested catalog scope or configured run size.
The API does not preload the entire catalog, maintain an unbounded question
cache, or require a global cache invalidation system. Selection must not
transfer every eligible question and answer into Go merely to choose a run.

Before exposing a run to the player, persist a small Redis snapshot of its
selected N canonical questions, the supported localized variants, ordered
answers and correctness, and the content context required for feedback and AI
explanations. N remains bounded by the configured run limit and availability.
Later answers, results, locale changes, and explanations use that run's content,
rather than rereading edited live questions. AI instructions are resolved at
explanation time and can change independently, with prompt-aware cache identities;
retirement alone does not hide a retained customization. Subsequent edits, deactivation, or
deletion cannot change the meaning or correctness of an already selected run.
Existing Redis session/run lifetime and exhaustion rules remain authoritative.

### Access and Operations

The Go API receives a dedicated read-only PostgreSQL role restricted to the
versioned runtime views. Manager retains migration and write ownership. The
reader contract evolves additively during mixed-version deployment; underlying
table changes cannot silently break an active view version.

Database configuration remains environment-owned and supports the established
`ENV_NAME__FILE` priority over `ENV_NAME`. Connection pools, acquisition waits,
and query deadlines are bounded, with a documented combined connection budget
for Manager and API on the current node. Logs and diagnostics must not disclose
credentials. Dependency checks, query failures, pool saturation, latency, and
the revision used by content operations must be observable.

S3 becomes an optional destination for explicit streamed exports, backups, and
rollback artifacts. Export reads a consistent database revision and records its
provenance and verification outcome. Partial exports are not rollback-ready.
Export reads Manager-owned authoring rows, including inactive packages, rather
than only the active-filtered runtime views. Keep topic tags PostgreSQL-only and
use an isolated exact-inventory target to avoid retaining deleted legacy files.
Export failure cannot undo or delay a valid live CRUD commit. PostgreSQL backups
remain necessary for authoritative content and Manager-only metadata; a playable
JSON export is not a substitute for a database backup.

### Migration and Rollback

Retain the local/S3 content providers during migration as explicitly selected
alternatives. PostgreSQL failure must not silently switch a running API to an
older S3 catalog.

Before cutover, reconcile any difference between the legacy authored revision
and the last successfully published S3 revision. An operator must explicitly
resolve and record which content becomes the initial live PostgreSQL revision.
Migration must not automatically promote pending legacy edits or overwrite the
authoritative database by blindly reimporting S3. Compare logical content,
identities, locale parity, activation, and aggregate counts before switching
readers.

Use additive reader views and privileges, compatible application images,
comparison checks, and environment-controlled provider selection. Preserve the
existing Redis sessions and runs; the implementation plan must cover legacy
run records as well as newly created snapshots. Keep the old providers until
the new path and recovery route have been verified.

Prefer application rollback to an image that supports the PostgreSQL runtime
contract. Switching back to an S3 reader is safe only after an explicit,
consistent export of the latest live revision under a write freeze, verification
of that export, and a coordinated provider switch. An older S3 projection may be
stale after live PostgreSQL writes and is not an automatic rollback target.
The S3-capable image must already support version-2 snapshots, retired-run access,
and the session index; a pre-feature image is not a continuity-preserving target.
Retain additive schema/view changes during application rollback.

## Consequences

### Positive

- API startup no longer performs one object request per localized question.
- Memory and run snapshots are bounded by requested work rather than catalog
  preload.
- Validated content and theme discovery become visible without API restart.
- Manager continues owning content rules, writes, and privileged administration.
- Existing runs keep their questions and answers despite subsequent edits.

### Negative

- PostgreSQL joins the public API's content-read availability path.
- Versioned reader views introduce an explicit shared database contract.
- Queries and connection pools compete with Manager on the current node.
- Returning to an S3 runtime requires a fresh verified export and write freeze.
- Complete immutable release history is not supplied by this live-table model.

### Operational

- Keep the current single-node Compose topology; this decision adds no RDS,
  Kubernetes, broker, or high-availability promise.
- Document pool limits, schema compatibility, database backups, restoration,
  runtime provider selection, and the export/rollback procedure.
- Measure startup, API RSS, content-read latency, query work, pool pressure,
  failures, and committed/read revisions.
- Validate concurrent edits and reads, interrupted transactions, unavailable
  dependencies, export failures, mixed-version rollout, and run continuity.

## Validation and Review

The initial acceptance dataset contains at least 100,000 canonical questions,
two locales, 200 themes, and 2,000 topics, including at least 10,000 eligible
canonical questions in one theme/topic/difficulty scope. Use synthetic content
and document the 2 GiB single-node profile, CPU and architecture, storage,
service versions, co-located services, indexes, and measurement conditions.

Proposed acceptance targets on that profile are:

- API startup to readiness within 10 seconds with healthy dependencies.
- API RSS no greater than 128 MiB for the defined workload.
- At 10 concurrent clients, server-side p95 catalog and availability operations
  no greater than 250 ms, and new-run creation no greater than 500 ms.
- No full-catalog preload or synchronous S3 dependency in ordinary CRUD or
  PostgreSQL content reads.
- Consistent revision reads and unchanged grading/question context for selected
  runs and their explanations during concurrent content edits and cutover.

These are acceptance targets, not measured results or demonstrated capacity.
A future one-million-canonical-question checkpoint requires a capacity report
covering latency, memory, query plans, storage, connections, and operating cost;
it is not a promise that the same 2 GiB node meets those targets at that scale.

Revisit this decision if measured read contention, connection pressure, or
availability requirements exceed the single-node design, or if editorial
approval, independent release channels, or immutable catalog releases become
product requirements. Those needs may justify a separate published projection,
additional capacity, or a different deployment boundary.

## References

- [PostgreSQL runtime specification](../../specs/006-postgresql-quiz-runtime/spec.md)
- [Implementation and migration plan](../../specs/006-postgresql-quiz-runtime/plan.md)
- [Manager-owned administration](001-manager-owned-quiz-admin-api.md)
- [PostgreSQL authoring and S3 publication](002-postgresql-quiz-authoring-s3-publication.md)
- [Tagged theme discovery](003-tagged-theme-discovery.md)
- [PostgreSQL authoring specification](../../specs/003-manager-quiz-postgresql/spec.md)
- [Tagged theme selection specification](../../specs/005-tagged-theme-selection/spec.md)
- [Repository spec-driven standard](../../specs/standard.md)
