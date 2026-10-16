# Verification: Generic Quiz Web

Date: 2026-10-11 (America/Sao_Paulo). Branch: `feat/generic-quiz-web`.
Initial verification scope: local synthetic content only. A later read-only
content refresh is recorded below. No production deployment, registry push,
release tags or DNS changes.

## Delivered behavior

Nuxt renders locale-prefixed public discovery pages on the server. Visitors can
search themes/topics, combine independent tag filters, browse paginated results
and play through the existing API. Topic-tag edits become visible on the next
uncached read without a gameplay revision, build or restart. Existing SPAs and
gameplay response contracts remain unchanged.

## Automated verification

| Area | Evidence and result |
| --- | --- |
| API | `go test ./...` and `go test -race ./...` passed with isolated PostgreSQL integration enabled. Synthetic tests cover strict query validation, active/empty/inactive resources, canonical counts, independent tag scopes, locale fallback, AND filters, pagination, failures and tag-only freshness. |
| Manager | Full suite passed: 221 tests, 923 assertions. Migration/provisioner tests verify active topic-tag views, unchanged old view signatures, reader SELECT grants and denial of base-table reads, writes, DDL and TEMP. |
| Web | ESLint, Nuxt typecheck, 48 Vitest tests and Nitro production build passed using Node 24 in Docker. Tests include configuration/locale/query/SEO/sitemap utilities and 27 gameplay-state tests. |
| File configuration | Node's test runner passed both configuration tests. Actual containers verified `__FILE` priority and safe startup failure for an invalid selected file. |
| API contract | Canonical OpenAPI has 223 resolved local references and 19 unique operations. Existing operations/components are semantically unchanged; discovery is additive. |
| Legacy S3 startup | Actual API image started with the real AWS SDK against a synthetic local S3 HTTP mock: 1 list and 22 object reads. Readiness/themes/catalog and a complete two-answer run passed; all five discovery routes returned `503 discovery_unavailable` with `no-store`. Containers used only shared isolated loopback, fictitious credentials and memory stores; this does not verify an AWS account/bucket. |
| Discovery SQL | Scoped-reader plans used 10 themes, 100 topics and 100,000 synthetic canonical question keys, rolled back after each case. Responses stayed bounded; no prompt/answer relations or temporary blocks were read. See [plan summary](evidence/discovery-plan-summary.json). |

Query execution in that local metadata fixture was approximately 99 ms for
themes, 78 ms for topics and 73 ms for topic tags. This is not a complete bank,
load test or production capacity estimate. Literal substring search and exact
counts may need reassessment as the catalog grows.

The actual local reader has no role memberships. The provisioner's existing
elevated-membership rejection was reviewed; no new fixture attempted membership
escalation. Public API removal of all topic tags was also exercised and the
original synthetic tags restored, with gameplay revision unchanged.

## Browser and HTTP verification

- Chromium journeys passed: discovery, cross-theme topic-tag navigation,
  prepare/start/answer, known-run reload, final exhaustion and result reload.
  Discovery made no personal API calls and created no game storage.
- Additional fault scenarios passed: committed create response lost (no silent
  retry; confirmed reset/new identity), committed answer response lost (GET
  reconciliation), restore HTTP 503 (explicit read-only retry), hardcore fatal
  loss, and theme/locale binding while a run is active.
- Persisted tab context contains bounded summaries and totals, not question
  prompts/options/explanations. Known runs reconcile with authoritative state.
  A lost create response without a run ID still requires explicit reset; this
  feature cannot discover the unknown run or reconstruct complete server-side
  session history.
- Raw initial HTML contains metadata, real navigation links, canonical and
  alternate URLs. Local mode is nonindexable. Same-image runtime configuration
  enabled indexing with a different origin and default locale, without rebuild.
  Filtered and personal pages remained nonindexable.
- Sitemap index/chunks return valid XML. Invalid chunk/scope returns true 404;
  unavailable API yields 503. HTML, metadata and JSON errors retain `no-store`.
  Liveness succeeds independently; readiness fails when the API is unavailable.
- Responsive checks passed at 320 px, including all computed text sizes doubled
  on home/explore/topic/game welcome/levels. Long synthetic localized names did
  not cause horizontal overflow. Keyboard controls retained visible focus.
  No browser runtime/hydration errors were observed in the covered journeys.
- SPA Dev and DSLab lint/build passed. Dev metadata/topics/levels were checked
  against the original preview API with read-only calls. Both SPA gameplay
  journeys passed with intercepted synthetic responses; the original fixture
  has no DSLab theme, so no real-bank DSLab gameplay claim is made.

Screenshots: [desktop discovery](screenshots/discovery-desktop.png),
[mobile topic](screenshots/topic-mobile.png), [game](screenshots/game.png),
[320 px / doubled text](screenshots/discovery-mobile-text-200.png).
These contain local synthetic fixture content only.

## Local runtime and preserved preview

- New Web: `http://localhost:18085/pt-BR/` (also `/en-US/`).
- Isolated Web API: `http://localhost:18086`.
- Manager for this isolated content copy: `http://localhost:18087`.
- Existing preview SPA Dev/API/Manager remain on ports 18082/18080/18081.

The original preview database was backed up before additive migrations. Its
content was not replaced or reimported. Existing API remains on its legacy
local provider; new discovery correctly returns `discovery_unavailable` there.

Automatic approval review rejected migrating the existing preview's Redis
session index as outside this Web authorization. That migration was not
performed. Instead, a separate Compose project restored the synthetic backup
into a new database, explicitly reconciled reader grants and activated the
PostgreSQL runtime with a separate empty Redis. Synthetic tags/levels were
added only to this copy. No original sessions were migrated or flushed.

Operator wrappers, protected environment/backup files and raw browser reports
remain ignored under `_temp/`; they are not release artifacts. Reproducible
setup and configuration are in the [local runbook](../../runbooks/generic-quiz-web-local.md).

### Subsequent local content refresh — 2026-10-12

At the user's request, the Web preview's demonstration bank was replaced with
a consistent read-only snapshot of remote PostgreSQL revision 2270: 34 themes,
473 topics and 18,007 canonical questions, including translations, answers,
theme/topic tag associations and AI instructions. Eleven content/state tables
were restored atomically after a full local backup and disposable rehearsal;
per-table row counts and SHA-256 fingerprints matched before commit.

Local administrators, credentials, schema, reader grants and Redis were retained.
No remote users, secrets or sessions were copied, and no remote content changed.
Temporary SSH ingress was restricted to the current IP and removed afterward.
The source has no topic-tag associations; its publication states were preserved.
Private dumps/manifests and new UI evidence stay ignored under
`_temp/web-preview/sync-20261012/` and `_temp/web-validation/`. Screenshots and
synthetic test evidence above predate this refresh. This is a snapshot, not
continuous synchronization or a channel for publishing local edits remotely.

Post-refresh read-only browser verification passed 22 page/states in both
locales: initial SSR without JavaScript, discovery, actual theme tags, empty
topic-tag directory, large theme/topic details, client navigation and 320 px
reflow. No HTTP, console, hydration or overflow errors were observed. Reports
remain ignored under `_temp/web-validation/remote-content/`.

Web runs non-root with a read-only filesystem, no persistent volume or direct
database/admin credentials. amd64 and arm64 images were built and locally
started; only API/public metadata configuration differs by runtime environment.

Observed Web memory after synthetic browser requests was about 42 MiB, with a
384 MiB container limit and 256 MiB heap ceiling. This small fixture does not
establish headroom for the production 2 GiB node. Production capacity, routing,
image-release integration and rollout remain a separate decision.
