# Tasks: Generic Quiz Web and Tagged Discovery

Status: Planning consolidated after acceptance of the product/technology
recommendations. All implementation and runtime checks below remain pending.
Source of truth: [spec.md](spec.md), [plan.md](plan.md),
[discovery contract](contracts/discovery.openapi.yaml) and
[ADR 005](../../architecture/adr/005-generic-quiz-web.md).

## 1. Public Discovery Boundary

- [ ] Add migration `0005` with active-filtered discovery/topic-tag views;
  preserve all existing runtime view signatures and contract version.
- [ ] Reconcile the reader whitelist/grants explicitly. Verify actual reader
  connections can read the new views and still cannot read base tables, write,
  create objects, use TEMP or inherit elevated membership.
- [ ] Add the optional PostgreSQL discovery repository and shared-pool queries
  for page rows, totals, canonical counts and scoped tag counts.
- [ ] Implement `/api/discovery` validation, routing and typed responses; add
  no-store headers and explicit unsupported-provider/dependency errors.
- [ ] Incorporate the feature contract in canonical OpenAPI documentation.
- [ ] Verify active/empty/inactive parents, duplicate topic IDs, independent
  tag scopes, combined filters, locale fallback, pagination/order, invalid and
  unknown input, counts without translation/answer multiplication and failures.
- [ ] Verify a topic-tags-only edit is immediately visible without changing
  gameplay revisions or requiring restart; cover removal of all tags.
- [ ] Verify old API compatibility with the additive migration and unchanged
  legacy local/S3 startup, readiness, themes, catalogs and gameplay.
- [ ] Run appropriate Go and Manager migration/provisioner tests with synthetic
  PostgreSQL/Redis fixtures; inspect representative SQL query plans and bounds.

## 2. Web Foundation and Discovery UX

- [ ] Bootstrap `apps/web` with pinned Nuxt/Vue/TypeScript/Nuxt UI dependencies,
  lock file, lint/type-check/test/build scripts and locale dictionaries.
- [ ] Add injected SSR-safe discovery client and request-scoped fetching; verify
  no module-level browser access, player identity or cross-visitor state.
- [ ] Implement runtime URL/locale/indexing/deadline configuration and
  `ENV_NAME__FILE` precedence with protected failure behavior.
- [ ] Build locale-prefixed home/explore/theme/topic pages with neutral styling,
  real links, visible filters, canonical identity and explicit pagination.
- [ ] Add localized tag labels with slug fallback and cross-theme tag navigation.
- [ ] Cover loading, empty, no-match, failure/retry and stale response behavior;
  preserve filters/page/scroll on back navigation. Verify result-kind tabs,
  page reset and endpoint-specific query parameters for exact-theme filtering.
- [ ] Add SSR metadata, canonical/alternate URLs, indexing policy, dynamic paged
  sitemap, true 404/503 responses and no-store HTML/metadata headers.
- [ ] Verify raw initial HTML contains content/links, local mode is nonindexable,
  and publication/tag edits appear without a Web build or restart.

## 3. New Web Gameplay

- [ ] Add an isolated client-only session/game composable and typed gameplay
  client, using existing API contracts without importing Quasar SPA modules.
- [ ] Implement level selection, hardcore confirmation, question/answer flow,
  run/session results and supported explanations in the new visual identity.
- [ ] Bind the session to its theme; preserve exhaustion, automatic closure,
  locale rules, configured limits and immutable run behavior.
- [ ] Add per-tab minimal context/summary persistence and authoritative run-state
  reconciliation for reload, uncertain answers and expiry; report limitations.
- [ ] Verify pending/double input, original question IDs, delayed/out-of-order
  responses, navigation, reset completion, fatal loss and final-run transitions.
  Cover unknown run creation without an ID, no automatic re-creation and
  confirmed reset/new-session recovery.
- [ ] Verify discovery/SSR/prefetch never creates runs, accesses personal results,
  resets sessions or generates explanations; game/results remain noindex.

## 4. Compose, Verification and Handoff

- [ ] Add a multi-stage, non-root Web Dockerfile compatible with amd64/arm64 and
  an optional local Compose service/profile using the PostgreSQL overlay.
- [ ] Apply additive migration and reader grants to the protected existing local
  environment, preserving content; update local API and start Web.
- [ ] Configure browser API origin/CORS and separate internal API DNS from public
  browser config. Add liveness/readiness and structured bounded health checks.
- [ ] Run Web lint, type checks, focused tests and production build. Complete
  required Go/Manager regressions; do not install application dependencies on host.
- [ ] Exercise synthetic browser journeys at desktop/mobile sizes, keyboard,
  320px reflow, 200% text resize and long localized names; capture UI evidence.
- [ ] Confirm existing SPA Dev/DSLab journeys and service health still pass.
- [ ] Record local memory observations and same-image runtime configuration
  verification, distinguishing functional evidence from production capacity.
- [ ] Update service/local-development documentation and deliver a local URL for
  review. Keep deployment, registry publication, tags and DNS outside this phase.
