# Plan: Tagged Theme Selection

Status: Approved and implemented locally on 2026-10-10; verification complete. Deployment is separate. See [spec.md](spec.md).

## Baseline Before Implementation and Ownership

Manager owns quiz authoring in PostgreSQL and publishes JSON to local storage
or S3. The Go API loads that projection into memory at startup and uses Redis
for sessions. At the start of this work, SPA Dev selected the fixed `dev` theme through
`src/services/theme-config.ts`; its HTTP client also supplies that theme header
to Ads API requests. SPA DSLab is a separate application.

Follow [ADR 003](../../architecture/adr/003-tagged-theme-discovery.md), accepted. Preserve the ownership boundaries established by ADRs 001 and 002:
no PostgreSQL credentials or authoring operations enter the Go API or SPA.

## Slice 1: Theme Authoring and Publication — R1–R3

Add migration `0003_theme_tags.sql` with `quiz_theme_tags(theme_id, tag_slug)`:

- composite primary key on both columns;
- theme foreign key with deletion cascade;
- foreign key to existing `quiz_tags(slug)`;
- index beginning with `tag_slug` for reverse lookup;
- no backfill that assumes a theme ID is an audience tag.

Reuse the normalization rules in `TopicTags.php`, extracting a neutral shared
helper if useful. Keep existing topic behavior and validation unchanged.
Store theme metadata and associations in one writer transaction under the
existing catalog lock. Upsert tag identities and replace associations only
when the input explicitly supplies tags. Do not prune unused identities.

Extend `QuizContentRepository` to load tags in bounded queries for theme lists,
theme detail, and the administrative catalog. Update `QuizContentWriter`,
`PostgresQuizAuthoringService`, and administration input/output mapping. Existing
theme writes already use the revision/publication flow; keep that flow for
tag changes rather than copying the topic-tags-only bypass.

Add the theme Tags form field and list display. Convert comma-separated form
input at the web boundary, preserving absent-versus-empty semantics and existing
CSRF handling. Protected API input remains an array. Legacy explicit writes
return `theme_tags_unavailable`; its metadata edits preserve existing serialized
tags. Do not make legacy mode a second tag-authoring implementation.

Extend `QuizProjectionRenderer` within its consistent database snapshot to
emit canonical theme tags. Do not change topic indexes, translations, question
files, or the topic-tags-only publication shortcut. Keep publication status and
`apiReloadRequired` semantics consistent with other published theme changes.

Extend `QuizSourceCatalog`, importer, comparator, and round-trip fixtures.
Normalize absence to `[]` before comparisons so an old untagged catalog does
not create false drift against newly emitted empty arrays. Validate supplied
canonical tag arrays. Import theme tags as published source data; on explicit
replacement, show tag additions/removals in dry-run comparison and restore the
source values transactionally. Continue restoring authoring-only topic tags
for surviving theme/topic identities.

Verification: PHPUnit tests for normalization, authenticated CRUD, omission,
atomic failure, publication/reload envelopes, and legacy preservation. Use the
existing PostgreSQL integration setup for migration, foreign keys, tag reuse,
deletion isolation, and import replacement. Compare all non-theme projections
before and after a theme-tags-only edit; assert they retain their content.

## Slice 2: Read-Only Public Discovery — R3–R4

Extend the Go theme domain and shared local/S3 loader with optional `tags`.
Missing fields become empty arrays; supplied fields must be canonical sets
under the published schema. Preserve the existing startup failure policy for
invalid content and catalogs with no questions.

Add a theme-list method to the store interface and `MemoryQuestionStore`.
Return copies of metadata/slices so callers cannot mutate the loaded snapshot.
The application service handles active filtering, normalized single-tag
matching, and weight/ID ordering. The HTTP handler handles query multiplicity,
error translation, and serialization using the existing error envelope.

Register `/api/themes` without mandatory theme/session headers. Ensure router
middleware does not impose those requirements globally on the new route.
Preserve existing CORS behavior; no new authentication mode is introduced.
Do not add a storage fetch, cache service, or database query to discovery.

Merge the feature endpoint into `docs/openapi.yaml` during implementation.
Update `docs/openapi-manager-admin.yaml` with the additive Manager field and
legacy conflict error. The files under this spec retain the feature contract;
canonical documents contain the complete API contracts.

Verification: `go test ./...` with table-driven loader, application, and handler
tests. Cover all-active and filtered reads, inactive/untagged themes, stable
ordering, invalid/repeated queries, header-free access, and empty results.
Use a storage fake to prove requests do not reload objects. Retain existing
catalog/run regressions with the fixed theme header used by SPA DSLab.

## Slice 3: Runtime Scope and SPA Dev Selection — R5–R6

Add `themeTag` to SPA Dev's runtime config type and local default. Map
`SPA_DEV_THEME_TAG` in Compose to container variable `SPA_THEME_TAG`, defaulting
only when absent. Validate explicit blank/invalid values rather than silently
using `dev` or requesting all themes. Normalize accepted input consistently
with the API and encode it when building the request URL.

The two SPA images currently share `deploy/docker/spa-entrypoint.sh`. Add a
Dev-specific entrypoint under `deploy/docker/spa-dev/` and reference it only
from the Dev Dockerfile. Keep DSLab's shared script, config shape, Dockerfile,
and image unchanged. Preserve Dev's existing API/Ads URL settings and safe JSON
generation. Update the Dev runtime example and Compose documentation.

Changing runtime configuration must work with the same compiled image. The
tag is public configuration, not a secret; do not introduce a new secret or
administrative token into the browser.

Add typed `getThemes(tag)` support and a theme-selection state boundary before
`useQuizCatalog`. Keep content theme, audience tag, and advertising theme as
separate concepts. The API client must take an explicit request theme for quiz
operations instead of reading one mutable global at response time. Discovery
does not need a session or theme header; ads retain the existing `dev` header.

In `IndexPage.vue`, add selection before topics while retaining the current
visual language. Select `dev` when eligible, otherwise the first ordered theme.
Honor the existing topic deep link only within that initial choice. Reload
topic availability and reset dependent state on a permitted theme change.

Integrate with `useQuizRun`'s existing end-session, reset, new-session, and expiry
recovery flows. Lock selection for the entire open session, including the
between-run screen. Bind run operations and solutions to the originating
theme/session; preserve the existing server reset and Redis exhaustion rules.
Do not rotate a session ID merely on an idle theme selection. When the final
run exhausts the session, use the same result aggregation and backend reset as
manual closure, then show the session result directly. Keep the existing
new-session action and wait for reset before enabling it.

Use an operation/context generation or cancellation to reject stale results
after theme, locale, or session changes. Apply the same protection to pending
catalog loads, availability, polling, and solutions. Do not display an old
result under a new theme.

Clear Ads API's topic filter when playing a theme other than `dev`. Preserve
existing slots, providers, campaign ownership, and Ads API code. Verify that
the shared HTTP wrapper cannot route quiz theme changes into ad headers.

Verification: add focused automated tests for selection defaults, fixed runtime
scope, session lock, request headers, and delayed responses. The current
`npm test` is a placeholder and is not verification evidence. Introduce a
small frontend test runner for these behaviors, using mocked HTTP and synthetic
catalogs. Run it plus `npm run lint` and `npm run build`. Check runtime output
for absent, valid, and invalid environment input. Capture UI screenshots for
the implementation PR; no separate manual QA scripts are required.

## Rollout, Compatibility, and Rollback

This is a future implementation/release sequence, not authorization to deploy:

1. Back up PostgreSQL and the currently published projection. Apply the additive
   migration before starting Manager code that expects the new relation.
2. Deploy compatible Manager support. Assign `dev` explicitly to the existing
   Dev theme and selected additional themes; confirm successful publication.
   No production theme creation or automatic audience assignment is part of the
   schema migration.
3. Deploy/reload the Quiz API with discovery support against the published
   projection. Verify the filtered list and existing fixed-theme requests.
   API and Manager releases can be independently staged while the old SPA runs.
4. Deploy SPA Dev with `SPA_THEME_TAG=dev` only after discovery returns the
   intended themes. Do not release or reconfigure SPA DSLab for this feature.
5. For later content changes, publish and reload API using the existing runbook;
   verify `/api/themes?tag=dev`. No further frontend release is needed to expose
   a newly tagged active theme.

Rollback the Dev UI first if the API must return to a version without the new
endpoint. Old APIs ignore the additional JSON field; old SPAs keep their fixed
theme. Retain the additive database schema and associations on application
rollback. An older Manager can republish a projection without tags, so pause
publication if rolling it back; resume only with a compatible publisher or an
explicitly restored projection. Never imply database rollback alone restores
the API's in-memory snapshot.

Use existing HTTP status logs and publication revision/status diagnostics.
The acceptance signal is a successful filtered discovery request plus a run
under its selected theme, while fixed-theme clients continue to operate.

## Alternatives and Deferred Work

- A hardcoded theme list requires frontend releases for content changes.
- URL-controlled audience tags allow players to change the site's scope.
- PostgreSQL discovery in the Go API introduces a second content source and
  different freshness from its questions; keep the published snapshot boundary.
- Publishing topic tags or combining multiple tags adds separate discovery
  behavior. Neither is needed for selecting Dev themes.
- Sharing new runtime startup behavior with DSLab would expand the release
  scope; the Dev entrypoint intentionally leaves that image alone.

Implementation updated `docs/quiz-pack-contract.md` and relevant architecture
documentation to describe PostgreSQL authoring, JSON publication, and optional
theme tags. Historical specs and accepted ADRs remain intact; ADR 003 records
the new decision.
