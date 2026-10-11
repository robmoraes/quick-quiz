# Feature: PostgreSQL Quiz Runtime

Status: Approved on 2026-10-11; implemented and verified locally; release gates pending. Production still
uses the S3 startup snapshot. This approval does not authorize deployment.

## Intent

QuickQuiz will grow beyond development and IT into subjects such as literature
and agronomy. Content volume must not require downloading the entire question
bank before the player API can start or restarting it after each publication.

On 2026-10-10, adding 32 themes and 439 topics brought the catalog to 18,007
canonical questions and 36,014 localized questions. API startup read 36,117
quiz JSON objects from S3 and took approximately 16 minutes 25 seconds. Settled
API memory was about 50 MiB: the observed bottleneck was startup I/O, rather
than demonstrated memory exhaustion. These measurements describe the existing
deployment, not performance promised by this feature.

Editors, players, API clients, and operators need indexed content access,
updates without reload, stable quizzes, and a predictable recovery path.
PostgreSQL already contains the authoritative authored content; this is a
runtime cutover, not another import of the question bank.

## Scope

In scope:

- PostgreSQL reads by the Go Quiz API, with Manager as the sole content writer;
- atomic publication with validated authoring transactions;
- scoped selection/counts and small, persistent snapshots for individual runs;
- compatible public discovery, catalogs, gameplay, administration, and locales;
- independent solution-prompt sourcing, dependency health, and least-privilege
  configuration;
- local Compose validation, staged migration, export, and rollback preparation.

Out of scope:

- a generic SPA, new audience sites, frontend pagination or redesign;
- public topic tags, tag hierarchies, cross-theme runs, or new game rules;
- an editorial approval workflow, scheduled releases, or concurrent-run
  reservation rules;
- RDS, Kubernetes, extra nodes, brokers, replicas, or an infrastructure upgrade;
- Ads API changes, player accounts, and semantic review of question quality;
- production cutover, release tags, or deployment authorization in this spec PR.

Dependencies: Manager, PostgreSQL, Quiz API, Redis, SPA Dev, SPA DSLab.
S3/local exports remain available for transition and portability. OpenAI remains
an optional dependency of explanations, not of playing a quiz.

## Behavior

### R1. Generic content and compatible discovery

1. Themes are domain-neutral. Literature, agronomy, Kubernetes, and PHP follow
   the same identities, validation, localization, and storage rules. Do not
   derive audience, difficulty, locale, or behavior from a theme's name.
2. Preserve existing IDs, numeric difficulties 1–4, option counts 3/5/7/7,
   weights, ordering, timestamps, locale fallback, and public response shapes.
3. `/api/themes` continues to list all active themes or exact normalized matches
   for one tag. SPA Dev remains scoped by its configured `dev` tag; adding
   `literature` tagged `arts` does not put it on the Dev site. Existing fixed
   DSLab requests remain valid. Tags are navigation metadata, not authorization.
4. Topic tags remain Manager-only. This feature does not expose them publicly.

### R2. Scoped reads and fast startup

1. In PostgreSQL mode, discovery, catalog metadata, counts, and question
   selection read the database. No full-bank preload, S3 listing, JSON download,
   or complete in-memory question repository is required to start or serve it.
2. Catalog/availability operations return metadata and aggregate counts without
   fetching every prompt and answer. Compute all topic/difficulty counts in
   bounded batches, rather than one query per question or four per topic.
3. Creating a run fetches at most the configured run limit of selected question
   packages, with their supported translations. It may examine scoped candidate
   IDs in SQL; it must not transfer the entire candidate bank to the API.
4. No process-local catalog cache is required in this increment. A request
   begun after a successful mutation response sees the committed publication.
   Multiple SQL reads within one operation observe one consistent revision.
5. Existing metadata-list endpoints remain unpaginated and can return all
   themes or the selected theme's topics. Their metadata response size may grow;
   it must not carry unrelated question content.

### R3. Transactional publication and inactive content

1. Retain the existing automatic publication behavior of successful Manager
   CRUD. In PostgreSQL runtime mode, validate and commit the affected content,
   all required translations/answers, catalog revision, and publication record
   in **one transaction**. Successful commit makes the change playable.
2. Theme/topic `active: false` remains the way to keep a skeleton unpublished.
   Questions under an inactive parent do not enter discovery, counts, or new
   runs. Activation publishes already validated descendants atomically.
3. Failed validation or transaction commit changes neither content nor the live
   revision. An incomplete localized question set must never become playable.
4. In this mode, `currentRevision` and `publishedRevision` advance together.
   Publication responses retain their envelopes, report `published`, identify
   the publication backend, and set `apiReloadRequired: false`. The existing
   nullable checksum/object count may be null; do not scan/hash the full catalog
   merely to populate S3-oriented fields.
5. `POST /api/admin/quiz/publication` remains idempotent for an already published
   database revision. It cannot silently promote a pending legacy S3 revision.
   Resolve such a revision through the explicit migration/recovery procedure.
6. Ordinary CRUD must not render, copy, export, or hash the whole catalog, and
   must not call S3. Export failures cannot turn a committed database
   publication into a failed one. Topic-tags-only edits retain their current
   no-playable-revision behavior.

### R4. Stable runs and explanations

1. A newly created run captures at most
   `min(RUN_QUESTION_LIMIT, available question count)` packages from one
   publication, and persists that small snapshot in Redis before responding.
   PostgreSQL snapshots record its real committed revision. Local/S3 snapshots
   record the loaded dataset fingerprint with a nullable revision, without
   requiring a database or inventing a publication number.
   Preserve the configured limit; ten questions in a bank does not imply ten
   questions in a run. Current production uses five; the code default is ten.
2. Keep prompt, correct/wrong alternatives, supported translations, canonical
   identities, and content fingerprints for the selected questions. Preserve
   the actual option IDs/order already presented to the player. Correct-answer
   data and internal snapshot fields never enter public responses or logs.
3. Subsequent edits, deletions, or deactivation do not change the questions,
   grading, result, or explanation's question/answer source of that run. New runs
   use the latest
   publication. Do not pin or copy an entire theme for a session.
4. Explanation generation uses the answered question's captured content and
   requested supported translation. Its cache identity includes the question
   content fingerprint and effective prompt/model/locale inputs; an explanation
   for an older answer must not be reused for changed content with the same ID.
   AI instructions are resolved when an explanation is requested, rather than
   captured at run creation. Prompt edits can affect future generation, with a
   new cache identity; they cannot replace the captured question or answer.
   Retiring a theme alone must not hide a retained custom instruction.
5. Legacy Redis records remain readable. Their current displayed question and
   recorded results must survive deployment. They lack complete historical
   answer content: never reconstruct an old correct answer from a newly edited
   database row. The compatibility stage and its limitations are explicit in
   [plan.md](plan.md#redis-compatibility-stage).

### R5. Session exhaustion and efficient Redis access

1. Preserve exhaustion identity: session, theme, topic, difficulty, and canonical
   question ID; locale does not reset consumption. Same-named topics in other
   themes remain isolated.
2. Capturing a question for a run does not consume it. Mark consumption when it
   is presented, as today; quitting does not consume unseen queued questions.
   Preserve hardcore reset, finish reasons, TTL, and existing reset semantics.
3. Player availability/selection/reset paths must not scan all Redis runs or
   unrelated sessions. Maintain an index of runs/consumption for the requested
   session and theme, with expiry behavior equivalent to retained run records.
   Aggregate once per operation instead of rescanning for each topic/level.
4. Index/backfill failures must not be interpreted as a new session, zero
   consumption, or exhaustion. A one-off explicit migration may scan legacy
   keys; ordinary player requests may not. Finish backfill only after stopping
   all pre-index API writers; compatible writers update records/indexes
   atomically throughout migration. An incomplete migration gates indexed
   operations rather than serving incorrect availability.

### R6. Retirement and dependency failures

1. Validate new discovery/catalog/run requests against current active content.
   For an existing run, validate its persisted identity rather than requiring
   its theme/topic still to exist. State, answer, finish, result, and reset
   remain available after retirement, within normal Redis retention.
2. An existing session whose theme has been retired receives empty playable
   availability so the existing closure/result flow can complete. This does
   not authorize starting another run in that theme or reveal inactive content.
3. A captured run can continue using Redis while PostgreSQL is temporarily
   unavailable. New content reads return HTTP 503 `storage_unavailable` in the
   existing public error envelope. Distinguish an actual missing resource from
   a dependency outage; never turn SQL/Redis errors into false 404s or empty
   successful catalogs. Keep existing explanation-specific failures where apt.
4. Preserve `/healthz` as liveness; add `/readyz` for database contract and Redis
   readiness in PostgreSQL mode; legacy modes check their configured runtime
   dependencies without requiring PostgreSQL. Neither response exposes
   credentials or connection details.
   Readiness failure does not delete sessions or restart a healthy process.

### R7. Configuration and content ownership

1. Select PostgreSQL through environment configuration, with bounded connection
   pooling and query deadlines. Give the API a dedicated reader role on a
   versioned runtime contract; it cannot write content, run migrations, read
   Manager users/sessions, or use the Manager administrator credential.
2. Preserve `ENV_NAME__FILE` priority over `ENV_NAME`, including the new database
   credential. Missing/unreadable selected secret files fail explicitly, without
   logging their contents or a credential-bearing connection URL.
3. Decouple solution-prompt storage from question storage. PostgreSQL mode can
   obtain the existing `question_solution` customization from PostgreSQL and
   use the shared domain-neutral default when no customization exists. Database
   failure is not absence of a customization. No S3 configuration is required
   for PostgreSQL-backed quizzes and explanations. Nonselected storage adapters
   must not be instantiated eagerly: Manager boot, quiz CRUD, and prompt editing
   work without an S3 bucket or mounted legacy question files. Ads and explicit
   exports retain their independently selected storage requirements.
   Manager and API locale settings must match declared runtime metadata;
   incompatibility fails readiness without scanning every question at startup.
4. Local development uses Compose PostgreSQL and Redis; nothing is installed on
   the host. Keep local/S3 providers for rehearsal and a verified transition.
   Default legacy behavior remains compatible until explicitly switched.

## Acceptance Examples

Examples guide automated tests and benchmarks, not separate manual QA artifacts.
Use generated content, disposable databases, and fake explanation providers.

| ID / requirements | Given / When | Expected outcome |
| --- | --- | --- |
| A1 / R1, R3 | Create active literature tagged `arts`, active agronomy tagged `agro`, and inactive anatomy | Unfiltered discovery returns the active themes; `tag=dev` excludes them; fixed DSLab still works |
| A2 / R2 | Start PostgreSQL API with 100,000 canonical questions and S3 unreachable/unconfigured | Readiness succeeds within the stated budget without reading question rows or objects into a startup bank |
| A3 / R2 | Request a theme with 100 topics, then create a five-question run from a 10,000-question level | Metadata/count queries are batched; only up to five selected packages are hydrated |
| A4 / R3 | Commit valid edits or activate a completed inactive topic | The next request sees the new revision without API restart; the mutation reports reload false |
| A5 / R3 | Fail locale validation, interrupt publication metadata update, or block S3 | SQL failures roll back the entire mutation; S3 being unavailable does not affect a database commit |
| A6 / R3 | Change only topic tags; call the publication endpoint twice | Tags remain private and create no playable revision; repeated publication is a no-op |
| A7 / R4 | Start a run with a custom solution instruction, then replace/delete its question or deactivate its parents | Question content/grading stay captured; retirement alone retains instruction resolution; new runs use current visibility |
| A8 / R4–R5 | Bank has ten items, run limit is five; quit after the first question | Run total is five; only the presented item is consumed; unseen captured items remain available |
| A9 / R4 | Edit an answer with its ID unchanged, then edit the AI instruction; request explanations | Each run uses its captured answer and current instruction; content/prompt fingerprints prevent stale cache reuse |
| A10 / R4–R5 | Resume a legacy run, restart the API, change locale, or use the same topic key in another theme | Legacy state/results survive; locale shares consumption; themes remain isolated; historical content is never invented |
| A11 / R5 | Populate many unrelated sessions; query availability/reset for one session | No global Redis scan or unrelated run fetch occurs; expiry/reset preserve the existing identity rules |
| A12 / R6 | Retire a theme while a player is completing its final run | Run completes, retired-theme availability is empty, consolidated result/reset work, and a new run is rejected |
| A13 / R6 | Interrupt PostgreSQL, then Redis | PG-dependent requests fail 503, captured runs continue with Redis; Redis failures are explicit and do not become 404/exhaustion |
| A14 / R6–R7 | Probe liveness/readiness with incompatible schema or an outage; use API credentials for INSERT or user-table SELECT | Readiness fails safely, liveness remains independent, and prohibited database operations fail |
| A15 / R7 | Supply database env plus `__FILE`; boot Manager/API and edit quizzes/prompts with S3 and legacy question mounts absent | File secret wins; invalid selected file fails; quiz CRUD and PostgreSQL prompt/default resolution work without constructing S3 adapters |
| A16 / rollout | Authored/published legacy revisions differ or S3 is older than PostgreSQL on rollback | Cutover/legacy fallback is blocked until explicit reconciliation/export and comparison; no silent data regression |

## Data and Contracts

- [Runtime data/configuration contract](contracts/runtime-catalog.yaml).
- [HTTP additions and publication metadata](contracts/http.openapi.yaml).
- Preserve [public OpenAPI](../../openapi.yaml) and
  [Manager OpenAPI](../../openapi-manager-admin.yaml); merge the draft additions
  during implementation rather than claiming them as shipped now.
- Reuse normalized PostgreSQL content. Add compatible views/indexes and backend
  metadata; do not replace the bank, regenerate IDs, or duplicate all content
  into a second live catalog. Redis snapshots are internal, versioned records.

## Quality Attributes

Proposed release profile: 2 vCPU / 2 GiB, PostgreSQL and Redis on the same Compose
node, run limits 5 and 10, two locales, ten concurrent player clients. Generate
at least 100,000 canonical questions across 200 themes and 2,000 topics, with
one topic/difficulty containing at least 10,000 questions. These are acceptance
fixtures, not a product limit or an assertion of current measured capacity.

- Healthy dependencies: ready within 10 seconds, independent of full-bank load.
- API steady-state RSS at most 128 MiB under the documented profile.
- Server-time p95: discovery/catalog/availability at most 250 ms; creation at
  most 500 ms; captured-run state/answer at most 100 ms, excluding AI/network
  transit. Measure at least 1,000 operations after warm-up; report errors,
  SQL counts, connection waits, and Redis commands alongside latency.
- Doubling unrelated question volume adds at most 16 MiB of API steady-state
  RSS; content payload fetched for a run stays bounded by its configured limit.
- Record a separate one-million-question capacity experiment when resources
  permit. Do not promise that workload on the current production node or make
  a hardware upgrade implicit in this feature.
- Parameterized SQL, bounded deadlines, redacted logs, and metrics for startup,
  publication backend/revision, pool saturation, errors, and legacy run count.

## Rollout and Operations

Ship additive database contracts and Redis compatibility first. Reconcile and
compare the already authored PostgreSQL bank with the last verified publication;
do not reimport S3 by default. Freeze writes for the final backend switch,
verify both configurations against the same baseline, retain verified backups,
and then enable atomic PostgreSQL publication and reads together.

Prefer rollback to a compatible PostgreSQL-capable application image. Returning
to the S3 runtime requires an explicitly generated, validated projection of the
latest committed database revision while writes are frozen; an old S3 snapshot
is not a safe automatic fallback. The S3 reader must also support version-2 run
snapshots and the session index; a pre-feature image does not meet that
continuity contract. Exports preserve inactive content as well as active content
without exposing inactive packages to players. Retain all durable data on
application rollback. Detailed stages are in
[plan.md](plan.md#rollout-and-rollback).

## Decisions and Review

[ADR 004](../../architecture/adr/004-postgresql-quiz-runtime.md) proposes replacing
the S3/runtime-snapshot parts of ADRs 002 and 003 while preserving content
ownership and audience/tag behavior. It remains proposed until accepted.

Resolved assumptions: automatic validated publication is retained; inactive
parents provide unpublished skeletons; new runs pin small question snapshots;
stronger concurrent-run reservations, generic frontend work, and infrastructure
expansion are deferred. Benchmark budgets are proposed approval criteria and
must be revised explicitly if evidence requires a different capacity profile.
