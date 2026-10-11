# Plan: PostgreSQL Quiz Runtime

Status: Approved, implemented, and deployed on 2026-10-11 for [spec.md](spec.md).
The separately authorized [production rollout](../../runbooks/postgresql-quiz-runtime-release.md)
passed functional validation with the current bank. Full-stack 100k capacity
acceptance remains separate.

## Architecture and Ownership

```text
Manager Web / protected administration API
  -> content validation + one PostgreSQL transaction
     -> normalized quiz tables + committed publication revision
        -> quiz_runtime_v1 read views -> Go Quiz API
                                         -> selected-run snapshots -> Redis

Explicit operator export -> streamed legacy JSON -> local storage / S3
```

Manager remains the only quiz writer and migration owner. The Go API receives
SELECT on the versioned runtime views, not the Manager's database credential.
SPAs keep their existing contracts and receive no database access. Redis keeps
session state and explanation caching. Ads API remains independent.

The accepted decision is [ADR 004](../../architecture/adr/004-postgresql-quiz-runtime.md).
It changes only the runtime/publication portions of ADRs 002 and 003, retaining
the Manager boundary from ADR 001 and existing audience/tag semantics.

## Database Contract and Transactions

1. Add the `quiz_runtime_v1` views described in
   [runtime-catalog.yaml](contracts/runtime-catalog.yaml), with active-parent
   filters and existing deterministic metadata ordering. Separate lightweight
   question identities from prompt/answer hydration.
2. Extend catalog state with publication-backend/contract metadata. Default it
   to the existing `content_storage` backend. Use an explicit migration command
   (`manager:quiz:runtime-backend` in the runtime contract) to enable `postgres`
   only after reconciliation and comparison succeed.
   Declare canonical/supported locales there and verify both services' locale
   settings against that metadata; the declaration is populated by explicit
   reconciliation rather than inferred through a startup bank scan.
3. Use existing compound keys/indexes where adequate. Verify query plans for
   theme-tag filtering, topic/difficulty counts, locale joins, candidate IDs,
   and batched package reads. Add indexes based on these concrete queries.
4. Provision a login role with connection/schema usage and SELECT only on the
   runtime views, including the limited `question_solution` prompt view. Deny
   writes, DDL, base-table reads, and Manager user/session access. API startup
   never provisions roles or changes the schema. Verify actual privileges with
   that role, not merely a client-side read-only flag.
5. Multi-query read operations use short, read-only repeatable-read
   transactions. Capture revision and content from that same transaction. One
   request must not join metadata from one commit with answers from another.
   Different requests may legitimately observe successive publications.
6. Both services pin the compatible runtime contract version. An unsupported
   version/backend or unresolved legacy revision blocks PostgreSQL readiness;
   it does not trigger a silent provider fallback.

### Automatic Publication

Refactor `QuizContentWriter` and `QuizPublicationService` so database-mode
publication metadata is written before the owning mutation transaction commits.
Do not wrap the current commit-then-export code and call that atomic. A failure
at any write, locale validation, or publication-state step rolls back all of it.
Serialize revision changes through the locked catalog-state row and verify the
configured backend inside that transaction, not only at process startup.

Validate affected question sets and relational constraints without rendering
the bank. Ordinary creation/replacement touches its scoped rows. Activation of
existing parents checks eligible descendants through SQL integrity checks, not
full prompt/answer loading into PHP. Recursive operations may affect their
descendants; their cost must not include unrelated themes.

Store a successful publication record and advance current/published revisions
together. S3-oriented checksum/object count are null for database publication;
explain that meaning in Manager Web and the administration contract. Keep
logical deletion counters and their existing response field names compatible.
Topic-tags-only edits still bypass the playable revision.

Retain the existing storage publisher behind
`MANAGER_QUIZ_PUBLICATION_PROVIDER=content_storage`. Select atomic behavior with
`postgres`. Reject configuration that disagrees with stored backend metadata.
Do not allow old and new publication writers to operate concurrently at cutover.

## Go Query Boundaries

The current `QuestionRepository` lists all content in a topic/level. Replace its
application-facing use with intent-specific operations, retaining adapters for
local/S3 compatibility:

| Operation | Data fetched |
| --- | --- |
| Discover themes | Active theme metadata and canonical tags; optional exact tag filter |
| Read catalog | Selected-theme topics/localizations and grouped counts |
| Session availability | Scoped aggregate counts; intersect this session's consumed IDs in SQL |
| Select run packages | Scoped candidate IDs followed by at most the configured number of complete packages |
| Read a package | Direct compound identity lookup; no topic-wide payload scan |

Selection can use randomized scoped IDs in SQL initially, provided the skewed
benchmark passes. Do not fetch all prompts/answers or use `ORDER BY random()` on
wide joined answer payloads. A more specialized random-selection index is a
follow-up if measurements require it. Correctness includes exclusion of consumed
canonical IDs and uniform eligibility; no hardcoded subject classifications.

Implement one bounded PostgreSQL pool per API process, parameterized SQL, context
deadlines, cancellation, and explicit not-found/unavailable error mapping. Keep
domain rules in application/domain packages and SQL in the store adapter. Do
not add a global question cache or a full-catalog revision polling/reload loop.

## Redis Run Snapshots and Indexes

New runs write a version-2 internal record containing selected packages, all
configured translations, content fingerprints, catalog provenance, and the
currently served options. PostgreSQL records the committed publication revision.
Local/S3 adapters record provider plus a deterministic digest of the dataset
actually loaded, with revision null; compute that digest once during legacy
loading, never by rehashing the bank per run. Legacy development needs no
PostgreSQL credential or fictitious revision. Queue size is bounded by
`RUN_QUESTION_LIMIT`; public run/result payloads retain existing shapes. Reuse stored served options after
restart, rather than generating new IDs/order for the current question.

Advance through captured packages and persist answered question content. AI
explanations use that content, with a cache key derived from snapshot digest,
effective prompt digest, model configuration, locale, and existing scope.
Version new cache identities; do not reuse unversioned cache entries by ID alone.
Include the effective generator configuration fingerprint, not just the model
name, when a configured provider/model change affects generation semantics.

Only presentation marks consumption. A pending package is not a reservation and
does not consume availability after quitting. Preserve the current sequential
session behavior; do not introduce a new overlapping-run reservation policy.

Add a secondary session/theme index to existing run keys. Retain per-run expiry
information so an expired run stops contributing exactly as before; simply
extending one permanent consumed-ID set would change TTL semantics. Batch lookup
and aggregate once for the requested session/theme, never the global keyspace.
Use atomic Redis updates for records/index entries and verify crash/retry,
expiry, reset, and hardcore deletion consistency.

Explicit index migration may SCAN legacy keys in bounded batches. Its dry-run
reports scope/counts; apply builds the index without rewriting or extending run
TTLs. A completion/version marker gates new indexed paths. Missing/incomplete
indexes fail explicitly instead of reporting an empty session. Stop all pre-index
API writers before the final backfill/completion marker; only compatible writers
that atomically maintain the index may run alongside backfill. Otherwise an old
writer could create an unindexed run after a successful scan. Gate dependent
indexed operations until completion; state/answer on existing runs remain
available through the compatible writer. Global active session administration
is not the player availability path and is not expanded
into a new monitoring feature here.

### Redis Compatibility Stage

Ship a compatibility API while the runtime still uses its verified S3/local
snapshot. It reads version-1 runs and creates version-2 runs for new requests.
Keep existing key prefixes, TTL renewal behavior, current question, results,
solution limits, and theme/session matching. Do not flush sessions.

Version-1 runs finish through the compatibility path using the old provider.
State, grading of their current question, results, and reset remain supported.
Legacy answers lack the full old correct/wrong content; if a matching captured
source cannot be proven, explanation generation returns the existing
`solution_unavailable` response rather than substituting current database text.
This is an explicit historical-data limitation, not a claim of retroactive
snapshot completeness.

After the compatibility release, monitor records still requiring the legacy
question provider. Gate final PostgreSQL cutover on none remaining; completed
legacy results may remain readable until expiry. Measure the actual count: a
fixed sleep of `SESSION_TTL` is insufficient if an active legacy run renews it.
No forced reset is part of deployment. Test the transition using retained
version-1 fixtures and live version-2 sessions across the provider switch.

## Retired Themes and Dependency Health

For captured-run operations, load Redis state and match the requested theme;
do not call current-theme validation first. Keep current-theme validation for
catalogs and new runs. Reset and empty availability for an existing retired
session use persisted session identity, without exposing inactive question data.

Add `/readyz` with bounded PostgreSQL contract/state and Redis checks in database
mode; legacy modes check only their configured runtime dependencies. Keep
`/healthz` independent of transient datastore failure. In current Compose,
retain liveness for the existing all-route Docker/Traefik health filter and use
readiness explicitly for deployment gates/monitoring. Wiring PG readiness into
all-route exclusion would also block Redis-only active runs during a PG outage;
do not do that while promising their continued availability.

Keep error envelopes stable; add public 503 `storage_unavailable` for dependency
failures and preserve explanation-specific failures. Log safe failure category,
duration, backend, and contract version; do not log DSNs, answer text, raw
prompts, session IDs, or unbounded identifiers as metric labels.

## Prompts, Configuration, and Local Compose

Separate `SOLUTION_PROMPT_STORAGE_PROVIDER` from question storage. An absent
value follows the selected question provider, preserving existing local/S3
behavior and choosing PostgreSQL in database mode. PostgreSQL reads only the
existing `question_solution` customization. Share a domain-neutral default
between Manager and API; missing customization uses that default, while SQL
failure returns an explanation failure. Resolve the effective instruction at
explanation time, not run creation; content stays captured, while instruction
changes deliberately produce a different cache identity. The limited prompt view
includes retained rows for inactive/deleted themes, filtered by the exact
`question_solution` key, so retirement alone does not remove customization.
Explicitly deleting a customization uses the neutral default; this is not a
retroactive change to the question or its correct answer. Prompt edits do not
require S3 export; the explicit legacy export includes any prompt files needed by the
old runtime.

Use the exact variables/defaults in the runtime contract. New secret-bearing
`QUIZ_DATABASE_URL` supports `__FILE` precedence. Expose Compose settings without
embedding credentials in images or SPAs, and validate provider combinations,
positive pool limits, and timeouts. PostgreSQL mode must work with S3 unset.

Wire factories/publication services conditionally or lazily so unselected local/S3
adapters are not constructed. Verify Manager boot/login, quiz lists/CRUD,
imports/restores, and prompt saves with S3 unset and no legacy content mount.
Ads routes and explicit exports may require their own selected storage; they
must not make the PostgreSQL quiz path depend on it.

Default legacy providers remain compatible. The local integration profile
selects PostgreSQL questions/publication/prompts plus Redis, provisions the
reader role through operator/development tooling, and uses only Docker services.
No host packages or new managed infrastructure are required by this plan.

## Verification and Capacity

Map automated tests to A1–A16 in [spec.md](spec.md), including both existing
provider adapters and the reader's real PostgreSQL privileges. Use synthetic
content only; fake the AI generator. Cover transaction failures, concurrent
read/write consistency, inactive parents, locale parity/fallback, scoped SQL and
Redis command counts, snapshot retention, and provider outages.

Generate the documented 100,000-question profile and its skewed scope. Record
fixture sizes/text lengths, exact images/hardware/resource limits, database
size/indexes, warm-up, at least 1,000 measured operations, ten-client concurrency,
p50/p95/max latency, RSS/peak memory, SQL counts/plans, pool waits, Redis calls,
and failures. Repeat with doubled unrelated content for memory/cost isolation.
Budgets are approval targets, not evidence already obtained. Record a separate
larger-capacity experiment without making a node upgrade or HA claim implicit.

Run Go tests, Manager PHPUnit with PostgreSQL integration, contract checks, and
Compose configuration validation. Run frontend regression tests/builds only
where dependency/contract changes affect them; no UI redesign or manual QA
artifact suite is required. Update runbooks and canonical OpenAPI alongside the
implementation, after the draft contracts are accepted.

## Rollout and Rollback

1. Back up PostgreSQL, Redis persistence as configured, current exported
   content, and runtime/secrets configuration. Validate archives and record
   revision-aware recovery instructions. Retain production banks outside Git.
2. Deploy additive migrations/views and provision the reader credential. Deploy
   compatible API/Manager images with current publication providers unchanged.
3. Stop all pre-index API writers, build/verify the Redis secondary index with
   compatible atomic-index writers only, and run the compatibility stage.
   Compare PostgreSQL and the last verified exported baseline with deterministic
   logical hashes/counts. Do not reimport over authoritative PostgreSQL data.
4. Reconcile any authored/published mismatch explicitly. Freeze authoring and
   wait for legacy runs requiring the old provider to finish/expire naturally.
   Verify no previous Manager worker/CLI writer remains active.
5. With the expected revision supplied, activate database publication metadata
   and configure Manager publication, API questions, and prompts for PostgreSQL.
   Start/verify the candidate against `/readyz`, discovery, scoped catalogs,
   synthetic run operations, and a preserved version-2 session before routing
   traffic. Unfreeze authoring after both services use the agreed backend.
6. Verify an edit becomes playable without restart, new non-IT themes stay in
   their configured audience, and retired-run continuation works. Capture
   startup, memory, latency, errors, and revision evidence before acceptance.
7. Keep legacy providers/export commands and schema compatibility for one
   accepted release window. Removal is a separate change after recovery review.

For application rollback, first use a known compatible PostgreSQL-capable image
and retain durable data. A return to the S3 provider requires freezing writes,
streaming a complete consistent legacy export of the latest committed revision,
verifying it (including theme tags and solution prompts), and allowing an
S3-capable **version-2-compatible API image** to load that projection before
traffic switch. Reuse the compatibility release, which preserves snapshot queues,
retired-run access, and the session index. A pre-feature image is not an approved
rollback target while captured runs require those behaviors. Explicitly switch
publication metadata/providers back together. Never automatically select stale
S3 after a SQL error. The legacy reload can still take minutes; record that
recovery cost rather than promising instant rollback.

The export command uses keyset/batch iteration in a consistent read transaction,
streamed serialization/checksums, and explicit target/apply flags. It must not
reuse a full-bank in-memory renderer for the growth path. Large exports and
long read snapshots are operator jobs with measured impact, not HTTP CRUD work.
Export from the Manager-owned authoring tables, preserving inactive parents and
their packages, canonical IDs, theme tags, and required solution-prompt files;
active-filtered runtime views are not a complete portability source. Topic tags
remain PostgreSQL-only. Use a fresh isolated target directory or S3 prefix with
an exact verified inventory, so deleted content cannot linger from an older
export. Freeze all authoring, including prompt/tag edits, for a rollback export.
The revision-aware verification report records its target, counts, logical
checksums, locale parity, and completion; a partial export cannot pass backend
activation checks. Do not delete Ads or unrelated objects in a shared bucket.

## Alternatives and Deferred Work

- Parallelizing S3 GETs or creating one bundle could shorten boot, but keeps
  export/reload freshness and full-bank startup work.
- Loading all PostgreSQL rows into memory would remove object round trips while
  retaining the reload requirement and bank-sized memory growth.
- A second versioned published catalog in PostgreSQL would support independent
  editorial promotion/history. Existing CRUD automatically publishes; one
  validated transaction plus small run snapshots meets that behavior without
  copying the catalog. Reconsider if explicit release channels are requested.
- Global caching, brokers, read replicas, generic SPA pagination, and a new
  cross-run reservation model require separate measured requirements.
