# Tasks: Generic Quiz Web and Tagged Discovery

Status: Implemented and verified locally on `feat/generic-quiz-web`.
Verification evidence and limits are recorded in [verification.md](verification.md).
Source of truth: [spec.md](spec.md), [plan.md](plan.md),
[discovery contract](contracts/discovery.openapi.yaml) and
[ADR 005](../../architecture/adr/005-generic-quiz-web.md).

## 1. Public Discovery Boundary

- [x] Add migration `0005` with active-filtered discovery/topic-tag views;
  preserve all existing runtime view signatures and contract version.
- [x] Reconcile the reader whitelist/grants explicitly. Verify actual reader
  connections can read the new views and still cannot read base tables, write,
  create objects, use TEMP or inherit elevated membership.
- [x] Add the optional PostgreSQL discovery repository and shared-pool queries
  for page rows, totals, canonical counts and scoped tag counts.
- [x] Implement `/api/discovery` validation, routing and typed responses; add
  no-store headers and explicit unsupported-provider/dependency errors.
- [x] Incorporate the feature contract in canonical OpenAPI documentation.
- [x] Verify active/empty/inactive parents, duplicate topic IDs, independent
  tag scopes, combined filters, locale fallback, pagination/order, invalid and
  unknown input, counts without translation/answer multiplication and failures.
- [x] Verify a topic-tags-only edit is immediately visible without changing
  gameplay revisions or requiring restart; cover removal of all tags.
- [x] Verify old API compatibility with the additive migration and unchanged
  legacy local/S3 startup, readiness, themes, catalogs and gameplay.
- [x] Run appropriate Go and Manager migration/provisioner tests with synthetic
  PostgreSQL/Redis fixtures; inspect representative SQL query plans and bounds.

## 2. Web Foundation and Discovery UX

- [x] Bootstrap `apps/web` with pinned Nuxt/Vue/TypeScript/Nuxt UI dependencies,
  lock file, lint/type-check/test/build scripts and locale dictionaries.
- [x] Add injected SSR-safe discovery client and request-scoped fetching; verify
  no module-level browser access, player identity or cross-visitor state.
- [x] Implement runtime URL/locale/indexing/deadline configuration and
  `ENV_NAME__FILE` precedence with protected failure behavior.
- [x] Build locale-prefixed home/explore/theme/topic pages with neutral styling,
  real links, visible filters, canonical identity and explicit pagination.
- [x] Add localized tag labels with slug fallback and cross-theme tag navigation.
- [x] Cover loading, empty, no-match, failure/retry and stale response behavior;
  preserve filters/page/scroll on back navigation. Verify result-kind tabs,
  page reset and endpoint-specific query parameters for exact-theme filtering.
- [x] Add SSR metadata, canonical/alternate URLs, indexing policy, dynamic paged
  sitemap, true 404/503 responses and no-store HTML/metadata headers.
- [x] Verify raw initial HTML contains content/links, local mode is nonindexable,
  and publication/tag edits appear without a Web build or restart.

## 3. New Web Gameplay

- [x] Add an isolated client-only session/game composable and typed gameplay
  client, using existing API contracts without importing Quasar SPA modules.
- [x] Implement level selection, hardcore confirmation, question/answer flow,
  run/session results and supported explanations in the new visual identity.
- [x] Bind the session to its theme; preserve exhaustion, automatic closure,
  locale rules, configured limits and immutable run behavior.
- [x] Add per-tab minimal context/summary persistence and authoritative run-state
  reconciliation for reload, uncertain answers and expiry; report limitations.
- [x] Verify pending/double input, original question IDs, delayed/out-of-order
  responses, navigation, reset completion, fatal loss and final-run transitions.
  Cover unknown run creation without an ID, no automatic re-creation and
  confirmed reset/new-session recovery.
- [x] Verify discovery/SSR/prefetch never creates runs, accesses personal results,
  resets sessions or generates explanations; game/results remain noindex.

## 4. Compose, Verification and Handoff

- [x] Add a multi-stage, non-root Web Dockerfile compatible with amd64/arm64 and
  an optional local Compose service/profile using the PostgreSQL overlay.
- [x] Apply additive migration while preserving the existing preview bank. Run
  Web against an isolated local copy with explicitly reconciled reader grants
  and separate Redis; do not migrate the original preview sessions implicitly.
- [x] Configure browser API origin/CORS and separate internal API DNS from public
  browser config. Add liveness/readiness and structured bounded health checks.
- [x] Run Web lint, type checks, focused tests and production build. Complete
  required Go/Manager regressions; do not install application dependencies on host.
- [x] Exercise synthetic browser journeys at desktop/mobile sizes, keyboard,
  320px reflow, 200% text resize and long localized names; capture UI evidence.
- [x] Confirm existing SPA Dev/DSLab journeys and service health still pass.
- [x] Record local memory observations and same-image runtime configuration
  verification, distinguishing functional evidence from production capacity.
- [x] Update service/local-development documentation and deliver a local URL for
  review. Keep deployment, registry publication, tags and DNS outside this phase.

## 5. Image Publication Follow-up — 2026-10-22

- [x] Add independent `web/vMAJOR.MINOR.PATCH` classification and image configuration
  to the existing release workflow, preserving main-reachability and app isolation.
- [x] Validate Web in Node 24 Docker before multiarch build/push, using existing
  Docker Hub credential secrets and `DOCKERHUB_WEB_IMAGE` configuration.
- [x] Include Web in the Makefile image targets and document release/deployment
  boundaries. Validate shell classification, configuration failures, old release
  paths and Makefile commands without publishing images or tags.

## 6. Legibility and Runtime Theme Exclusions — 2026-10-26

- [x] Improve code/punctuation fonts, disable ligatures, preserve bare operators
  literally and validate responsive rendering with synthetic questions.
- [x] Add bounded per-request API exclusions before pagination/counts/facets,
  including filtered detail 404s and unfiltered backward compatibility.
- [x] Add optional Web runtime/`__FILE` configuration and forward it through
  SSR/browser discovery, sitemap and readiness without hardcoded exclusions.
- [x] Reject direct excluded routes and preserve excluded stored sessions without
  API reads or resets; confirm the same image restores visibility with empty config.
- [x] Validate Go race/integration tests, Web tests/build, actual startup validation,
  read-only SSR/sitemap checks, synthetic browser journeys and updated contracts.
