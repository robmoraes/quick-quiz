# Plan: Generic Quiz Web and Tagged Discovery

Status: Accepted direction consolidated for local implementation; no code or
runtime verification is claimed. See [spec.md](spec.md), [tasks.md](tasks.md),
[ADR 005](../../architecture/adr/005-generic-quiz-web.md) and the
[feature API contract](contracts/discovery.openapi.yaml).

## Boundaries

Add `apps/web/`, a Nuxt application using Vue/TypeScript, Nuxt UI and Tailwind.
Node/Nitro renders public discovery pages. The Go API owns discovery queries,
gameplay and Redis access; Manager remains the sole content writer.
Web has no PostgreSQL, Redis, AWS, OpenAI or administrative credentials.

The existing SPAs remain untouched. Their contracts and visual styles are
regression constraints, not components that the new site must import.
Advertising is deferred for this first local delivery.

## Slice 1: Public Discovery Storage and API — R1, R2, R5

Add a separate optional `DiscoveryRepository` implemented by the PostgreSQL
question store. Do not expand the existing question-store interface or use
`RuntimeQuestionReader` as a PostgreSQL capability check: the memory store also
implements that interface. Local/S3 adapters keep their current behavior and
return `503 discovery_unavailable` only from the new discovery routes.

Add migration `0005_public_quiz_discovery.sql`, using additional named read
views in `quiz_runtime_v1` for public topic-tag associations and any required
discovery metadata. Preserve existing view columns/types and
`state.contract_version = 1`; do not edit checksummed migrations or make the old
API incompatible with the expanded schema. Join tag associations through active
theme/topic views. Keep PUBLIC access revoked.

Extend the explicit whitelist in `QuizRuntimeReaderProvisioner` and reconcile
the existing reader's grants through the operator command after migration.
The reader retains SELECT-only views and no access to base tables, Manager
users/sessions, writes, DDL, TEMP or role memberships. New API readiness checks
validate the added views and grants without reading the question bank.

Reuse the existing pgx pool and read-only repeatable-read helper. Default API
pool size remains four and the query timeout remains two seconds; Web adds no
database connection pool. One discovery operation reads rows, totals and
aggregates from one transaction snapshot. Bound query count by operation, not
by number of returned themes/topics. Close row cursors before issuing another
query through the same transaction.

Aggregate canonical question packages through `question_keys`, counting each
full theme/topic/difficulty/question identity once. Do not multiply counts by
joining localized prompts or answer options. Active empty resources are valid
details with `playable: false`. A topic-tag filter requires a matching active
topic, which may be empty; a difficulty filter requires a matching canonical
question at that level. Exact availability for an individual player remains
the existing session API's responsibility, not a public discovery count.

Implement the new `/api/discovery` family described in the feature contract:
themes list/detail, global topics list/detail and a scoped tag directory.
Locale is explicit, with no session or administrative header requirement.
Canonical themes remain locale-neutral. Topic text uses field-wise
requested/fallback/canonical resolution; do not claim a single metadata source
locale when different fields can fall back independently.

Queries use parameterized SQL. Search is literal, case-insensitive,
accent-sensitive substring matching; escape `%`, `_` and the escape character.
Filters combine with AND and are single-valued. Ordering and pagination are
deterministic; tags use ASCII slug ordering. Directory counts include the
currently selected filters rather than silently implementing self-excluding
facets. Unknown valid filters produce empty results; malformed input produces
the documented validation error. PostgreSQL failures retain
`storage_unavailable` and never become false empty lists or 404s.

Set `Cache-Control: no-store` on all new discovery responses, including errors.
Merge the feature contract into `docs/openapi.yaml` when implementing the API;
keep existing endpoint shapes unchanged. Legacy JSON exports remain unchanged.
No discovery revision or tag publication side effect is introduced.

Verification: generated PostgreSQL fixtures for active/inactive and empty
parents, overlapping tags, identical topic IDs across themes, locale fallback,
counts, filters, pagination, validation, failure and tag-only edit visibility.
Assert response/query bounds and absence of prompt/answer reads. Verify new
reader grants and old API compatibility against the additive migration.

## Slice 2: Nuxt Foundation and Public Pages — R1, R3, R5

Pin maintained compatible versions during implementation and commit the lock
file. Add type checking, lint, focused unit/component tests and a production
build command. Do not install Node, PostgreSQL, Redis or ARM emulation as part
of this feature on the host; use Docker Compose and the existing build setup.

Create a plain TypeScript API client with injected fetch, base URL and locale.
Public discovery has no session accessor. Gameplay adds an explicit client-only
session accessor later. Do not import the current SPA API module into SSR: it
reads `window`, localStorage and session state at module scope.

Use request-scoped Nuxt data fetching, with keys containing locale, canonical
identity, filters and page. Deduplicate within a request/hydration only. Do not
use SWR, ISR, cross-request caches, build-time catalog prerendering or mutable
module-level player state. Fetch only data needed for the visible page, not
every theme's catalog. Reject stale responses after query/locale changes.

| Route | Purpose | Initial index policy |
| --- | --- | --- |
| `/` | Temporary redirect to configured default locale | Redirect |
| `/<locale>/` | Discovery-first home with search, areas and themes | Index when playable content exists |
| `/<locale>/explore` | Search, tag/theme/difficulty filters and paginated results | `noindex,follow` |
| `/<locale>/themes/<theme>` | Theme context and paginated topics | Index nonempty unfiltered detail |
| `/<locale>/themes/<theme>/topics/<topic>` | Topic context, levels and tag links | Index nonempty detail |
| `/<locale>/play` | Client-controlled game, retaining public theme/topic context | `noindex,nofollow` |
| `/<locale>/results` | Client-only confirmed session result | `noindex,nofollow` |

Explore renders one result kind at a time through Themes/Topics tabs, recorded
as `kind=themes|topics` in the Web URL. Default to Themes; topic-tag links select
Topics. Use one page parameter for the active kind and reset it on tab/filter
changes. An exact theme filter selects Topics; switching to Themes clears that
filter while preserving applicable search/tag/difficulty filters. Construct
endpoint-specific query objects: never forward Web-only `kind` or unsupported
`theme` to the theme-list API. Tag-directory scope follows the visible result
kind. Detail pages paginate their own topic list, without a competing theme list.

Filtered/paginated detail variants are noindex and excluded from the sitemap;
their canonical identifies the unfiltered resource. The unfiltered theme page
contains crawlable pagination links to its further topics; those pages remain
accessible. Stable topic detail links form the durable SEO surface. No session
or run ID appears in a public discovery URL or SSR payload.

Use supported BCP 47 locale prefixes, initially pt-BR and en-US. Root redirection
is deterministic and configurable, not negotiated by crawler/user headers.
Use Nuxt/Vue i18n for UI and tag-label dictionaries, falling back to the canonical
slug for unknown tags. Canonical theme descriptions are displayed as authored.
Localized topic metadata comes from the API's existing fallback rules.

Render semantic headings, descriptive title/meta description, canonical URLs
and real links in the initial HTML. Alternate-language links target actual
supported equivalent routes. Generate sitemap data at request time through
paged metadata queries, respecting sitemap URL/file limits with an index and
chunks if needed; do not load the bank or fan out one catalog call per theme.
Use configurable origin and indexing; local mode emits noindex and no
indexable sitemap entries. Handle missing resources as 404 and upstream
unavailability as 503, with useful recovery messages.

The initial API bounds allow traversing at most 100,000 resources per endpoint
with pageSize 100. Detect larger totals while generating the sitemap and report
an explicit generation limit rather than silently publishing a supposedly
complete sitemap. Expanding that discovery bound/cursor is a future contract
change, not an unvalidated capacity claim for this increment.

Apply the UX brief in the spec: neutral light design with one accent, responsive
cards, clear parent context, visible/clearable filters and pagination. Preserve
search/page/scroll on back navigation. Keep loading, empty, no-match and failed
states distinct. Validate keyboard focus, control names, reduced motion,
320px reflow, text resizing and long localized text; a component library alone
does not establish accessibility conformance.

## Slice 3: Gameplay in the New Visual Identity — R4

Implement a Web-owned gameplay state machine/composable over the existing
public contracts. Reuse domain types and accepted behavior, not Quasar-coupled
`useQuizRun` or its dialogs, notifications, audio and advertising dependencies.
No extraction/refactor of the current SPAs is required in this slice.

Initialize player identity only in the browser on an explicit game action.
Nuxt SSR, link prefetch and discovery must not create runs, query personal
availability, reset sessions or generate explanations. Client session/run
context stays bound to a theme and uses existing API headers. Theme changes
during an open session require the existing closure/reset path.

Keep numeric levels, option counts, configured run limit, locale-independent
exhaustion and immutable captured runs. Preserve hardcore confirmation/loss,
run results, supported explanations, stale-response protection and automatic
session-result transition on exhaustion, including the accepted final-run
result behavior.

Persist minimal session/run context and bounded completed-run summary data in
per-tab sessionStorage for refresh recovery. Reconcile the persisted run with
`getRunState` before resuming or retrying an uncertain answer. Keep the original
question ID when reconciling; do not blindly submit an old option against a new
question. Expiry clears invalid context and offers the supported new-session
flow. Browser storage failure is explicit; do not claim server-side restoration
of unavailable historical session summaries.

Run-state reconciliation requires a known run ID. If `POST /api/runs` has an
unknown outcome before its response supplies that ID, do not automatically
repeat creation or query operational session listings. Keep the old session ID
and show explicit uncertainty. Offer confirmed reset of that session, then a
new client session identity before another start; an untracked old run can
expire normally. No global player lookup or creation-idempotency API is added.

Keep result and game rendering client-only for personalized data and mark all
HTML `no-store`. A result is shown as confirmed only after the relevant API
response/state is reconciled. Prevent accidental double submission while
pending and use existing server validation; no new idempotency contract is
silently invented.

Verification: mocked delayed/out-of-order responses, failures and reloads;
lost creation responses with unknown IDs and confirmed reset recovery;
theme locking, question-ID reconciliation, exhaustion/result flow, hardcore,
locale changes and expiry. Exercise the whole journey with synthetic local
content through a browser; capture screenshots without committing a real bank.

## Slice 4: Local Runtime and Operations — R5

Provide a Web Dockerfile with a maintained Node LTS base available for amd64
and arm64, multi-stage dependency/build/runtime separation, non-root runtime
and a Nitro Node-server output. Build scripts must not assume an amd64 binary.
No registry push, release tag or production workflow is part of this phase.

Add an opt-in `web` service/profile to local Compose and document invocation
with the existing `compose.postgres.yml` overlay. Reuse current database/Redis
services and protected local volumes. Do not replace or reimport the local
question bank. Migrate and reconcile reader grants explicitly before updating
the local API, then start Web. Existing SPA local URLs remain available.

Runtime environment mappings:

| Application variable | Initial Compose default / purpose |
| --- | --- |
| `NUXT_API_BASE_URL` | `http://api:8080`, private server-to-API URL |
| `NUXT_PUBLIC_API_BASE_URL` | Browser-reachable published local API URL |
| `NUXT_PUBLIC_SITE_URL` | Local Web origin, initially `http://localhost:18085` |
| `NUXT_PUBLIC_DEFAULT_LOCALE` | `pt-BR` |
| `NUXT_PUBLIC_SUPPORTED_LOCALES` | `en-US,pt-BR`, validated against the API |
| `NUXT_PUBLIC_INDEXABLE` | `false` locally |
| `NUXT_API_TIMEOUT_MS` | `5000`, bounded upstream deadline |
| `PORT` / `WEB_HTTP_PORT` | Container listener / published local port |

Resolve server configuration once at startup with `ENV_NAME__FILE` precedence;
selected missing, unreadable or empty files fail without disclosing contents.
Only public fields enter Nuxt public config. No build argument carries URLs,
secrets or content. Browser gameplay calls Go directly: permit the configured
local Web origin in API CORS without adding cookies or wildcard credentials.
Future domain/canonical settings are environment changes, not an image rebuild.

Provide `/healthz` for process liveness and `/readyz` for bounded API readiness
and a one-entry discovery capability check. Perform upstream checks within a
shared overall deadline; never return credentials or catalog payload in health
responses. Use structured logs, timings and request correlation, without run
IDs, session IDs, answer content or secrets in public-rendering logs.

Verification: Compose configuration, local PostgreSQL reader grants, all
existing service health checks, SSR HTML/status/headers, browser gameplay CORS,
same-image runtime configuration and secret-file failure behavior. Measure
Web idle and representative request memory locally; do not infer production
capacity from a passing functional smoke check.

## Delivery and Rollback

Implement through short-lived branches in the task order, keeping public API
and existing SPA compatibility covered. Merge specs/contracts before, or with,
their corresponding implementation. Tests and browser evidence are automated
where practical; no separate human QA case suite is required.

For local rollback, stop the optional Web service and return to the prior API
image. Keep additive views and grants unless an explicit cleanup is needed;
do not reverse schema, restore the bank or reset Redis merely to remove Web.
Production resource assessment, domain routing, image publication and rollout
remain a later authorized change.
