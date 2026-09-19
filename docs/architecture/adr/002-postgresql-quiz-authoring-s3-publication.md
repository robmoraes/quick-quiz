# ADR 002: Use PostgreSQL for quiz authoring and S3 for publication

- **Status:** accepted
- **Date:** 2026-09-18
- **Decision owners:** QuickQuiz maintainers
- **Scope:** Manager quiz persistence and Quiz API publication boundary
- **Supersedes:** none
- **Superseded by:** none

## Context

The Symfony Manager currently uses the published quiz-pack JSON objects as its
authoring database. Listing a catalog requires repeated S3 prefix listings and
object downloads to calculate counts and validate locale parity. Production
measurements showed approximately 11 seconds for a theme with 128 canonical
questions, 20 seconds for a theme with 281 canonical questions, and 24 seconds
for the complete administrative catalog.

The Manager needs efficient queries, referential integrity, and atomic
multi-locale edits. The Go Quiz API has a different workload: it serves a
relatively small, read-heavy dataset efficiently from memory and should remain
independent from the Manager database at runtime.

PostgreSQL already runs for the Manager on the current node. S3 already provides
durable, versioned publication objects and is the source loaded by the Quiz API
at startup.

## Decision Drivers

- Remove S3 object scans from interactive Manager navigation.
- Preserve atomic updates across locales, questions, and ordered answers.
- Keep the Manager application containers stateless.
- Keep the Quiz API read path fast and independent from PostgreSQL availability.
- Reuse the PostgreSQL and S3 infrastructure already operated by QuickQuiz.
- Preserve the current JSON contract and rollback path during migration.

## Considered Options

### Option A: Keep S3 as the authoring store and optimize reads

List each theme once per request, calculate counts from object keys, and add
request-local or Redis caching.

**Advantages**

- Smallest code and migration change.
- Preserves one content representation.
- Can reduce the immediate navigation latency.

**Disadvantages**

- S3 remains responsible for query and transactional workloads it does not
  model well.
- Multi-object mutations still require application-level compensation.
- Search, aggregation, consistency checks, and concurrent editing remain
  increasingly complex.
- Shared caching introduces invalidation and stale-read behavior.

### Option B: Use PostgreSQL for authoring and S3 for publication

Store normalized authoring data in PostgreSQL. Render the existing JSON layout
from a consistent database snapshot and publish it to S3. Continue loading that
published projection into Quiz API memory.

**Advantages**

- Normal Manager reads and counts become indexed SQL queries.
- Multi-row authoring changes gain database transactions and foreign keys.
- The Manager containers remain stateless.
- The Quiz API keeps its current low-latency, database-independent runtime.
- S3 versioning and existing JSON consumers remain useful.

**Disadvantages**

- PostgreSQL and S3 hold two representations with an explicit publication
  boundary.
- Publication status, retry, comparison, and recovery must be implemented.
- PostgreSQL backup becomes part of quiz-content recovery.
- The current single-node PostgreSQL deployment remains a shared failure domain
  until infrastructure is changed later.

### Option C: Make PostgreSQL the runtime store for Manager and Quiz API

Store content once and make the Go API query PostgreSQL for quiz operations.

**Advantages**

- Eliminates the publication projection.
- Content changes can become visible immediately.
- Relational queries are available to both services.

**Disadvantages**

- Adds database latency and availability to the player request path.
- Couples Quiz API scaling and releases to the Manager database schema.
- Expands database credentials and network access to another service.
- Discards the simple in-memory read model that fits the current dataset.

## Decision

Adopt Option B.

PostgreSQL is the authoritative authoring store for themes, topics, localized
questions, and ordered answers. S3 is a derived publication store. Only the
Manager publisher and migration tooling write quiz content to S3 after cutover.

The Manager UI and protected administration API query and mutate PostgreSQL.
Successful publication preserves the current JSON paths and payloads. The Quiz
API continues loading those objects into memory and does not receive PostgreSQL
credentials.

The migration is feature-flagged so legacy JSON reads remain available during
import, comparison, rollout, and application rollback.

## Consequences

### Positive

- Manager navigation no longer scales with the number of S3 object downloads.
- Database constraints and transactions protect catalog relationships and
  localized question sets.
- Existing Quiz API behavior and public player contracts remain stable.
- Manager and Quiz API application replicas retain stateless deployment
  characteristics.

### Negative

- Publication consistency becomes an explicit application responsibility.
- Operators must distinguish authored, published, and API-loaded revisions.
- The initial implementation must include importer, renderer, comparison,
  migration, and publication-status tooling.
- PostgreSQL storage and backups now contain business content rather than only
  Manager support data.

### Operational

- Versioned database migrations must run before enabling PostgreSQL quiz reads.
- The source JSON projection must be retained until import and cutover are
  accepted.
- Production rollout requires database and S3 backups, dry-run import,
  aggregate comparison, publication verification, and a Quiz API restart.
- Publication failures must be observable and retryable without claiming that
  an unpublished revision is live.
- Moving PostgreSQL to a managed or separate node remains an infrastructure
  decision outside this ADR.

## Validation and Review

```text
Validation signals:
- Normal Manager catalog and question-list reads make zero S3 calls.
- Current production-sized catalog reads complete within 500 ms of server time.
- Import reports equal source and database counts for every entity category.
- A database round trip publishes logically equivalent JSON files.
- The Quiz API loads and serves the PostgreSQL-generated projection.

Review trigger:
- The published dataset no longer fits comfortably in Quiz API memory.
- Product requirements demand immediate publication without API reload.
- Multi-region writes, multiple publication channels, or independent authoring
  services require a different consistency model.
- The single-node PostgreSQL failure domain no longer meets recovery targets.

Rollback or migration path:
- Select legacy JSON persistence and deploy the previous Manager image.
- Keep imported PostgreSQL tables unused; do not destructively reverse schema.
- Restore a prior S3 object version if a published projection must be reverted.
```

## References

- [PostgreSQL-backed Quiz Authoring spec](../../specs/003-manager-quiz-postgresql/spec.md)
- [ADR 001: Keep quiz administration in the Manager boundary](001-manager-owned-quiz-admin-api.md)
- [Manager administration API](../../manager/admin-api.md)
