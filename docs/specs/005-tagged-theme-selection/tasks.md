# Tasks: Tagged Theme Selection

Status: Approved and implemented locally on 2026-10-10. Automated verification complete; not deployed to production.

Requirements and acceptance examples refer to [spec.md](spec.md). Implement
in small trunk-based slices after approval; do not treat this list as a release
authorization. Verification evidence is recorded below and should accompany the implementation PR.

## 1. Review Gate

- [x] Approve the spec, draft contracts, and proposed ADR 003. Confirm the
      runtime tag default, session boundary, import replacement semantics, and
      existing Dev advertising scope before implementing them.

## 2. Authoring and Persistence — R1–R2, A1–A4

- [x] Add the theme/tag association migration; verify it on existing data with
      empty initial associations, shared identities, and deletion isolation.
- [x] Reuse normalization and add atomic writer/repository support; test limits,
      deduplication, omitted/empty/null values, and rollback on failure.
- [x] Extend protected theme API reads/writes and canonical OpenAPI schemas;
      verify older request bodies, unchanged required fields, and auth behavior.
- [x] Add the Manager theme form/list field; cover CSRF, validation feedback,
      blank clearing, and edits that must preserve tags.
- [x] Preserve serialized tags in legacy edits and reject explicit legacy tag
      writes; test both behaviors with old and newly tagged files.

## 3. Published Snapshot — R3, A5–A6, A14

- [x] Render tags on theme entries in the existing snapshot/publication flow;
      verify revision/reload signaling and publication failure recovery.
- [x] Update source validation, import/export, and comparison; test missing tags,
      canonical arrays, round trips, idempotency, and replacement removals shown
      in dry run. Preserve existing topic-tag restoration.
- [x] Assert that topic/question/localization projections are unchanged and that
      topic-tags-only writes still avoid publication and catalog revisions.

## 4. Public Discovery — R3–R4, A7–A8, A14–A15

- [x] Extend the shared local/S3 loader and memory store; test missing/invalid
      tags, inactive themes, and immutable snapshot metadata.
- [x] Implement application filtering and `/api/themes`; verify no required
      session/theme header, exact matching, normalization, ordering, errors,
      and empty results with no request-time storage access.
- [x] Merge the public draft contract into canonical OpenAPI and run Go tests,
      including unchanged catalog/run behavior used by SPA DSLab.

## 5. SPA Dev — R5–R6, A9–A13, A15

- [x] Add validated runtime config, a Dev-only entrypoint, and the Compose
      variable. Check default/blank/invalid/custom input using the same image
      and verify that no DSLab files or startup behavior change.
- [x] Add typed discovery and explicit per-request theme context; test quiz
      headers, header-free discovery, and the independent advertising header.
- [x] Add the accessible theme selector, initial choice, translated states,
      empty/error handling, and topic deep-link behavior; add focused frontend
      automation rather than relying on the current placeholder test command.
- [x] Integrate session locking, end/reset/recovery, and stale-response guards;
      test an open session, same-named topics in different themes, delayed
      responses, and unchanged theme-scoped exhaustion behavior.
- [x] Preserve Dev advertising ownership and clear foreign topic filters;
      verify existing Dev-topic ad requests remain unchanged.
- [x] Run frontend tests, lint, and production build. Capture UI screenshots for
      review, including narrow-screen and empty/error states.

## 6. Integration and Documentation

- [x] Use disposable content to verify Manager tag edit → publication → API
      reload → filtered discovery → selected-theme run. Cover A14's old/new
      snapshots and A15's existing fixed-theme client without deploying DSLab.
- [x] Update content contracts, runtime examples, architecture, and release/
      rollback instructions. Link test evidence to the acceptance examples.
- [x] Review the final diff for drift, accidental topic-tag publication, secrets,
      and unrelated service changes. Keep production content out of fixtures.
- [x] Mark the spec's implementation status only after the corresponding work
      and verification are complete. Plan versions and deployment separately.


## Local Verification — 2026-10-10

Branch: `feat/tagged-theme-selection`. All content and credentials used below
were synthetic. PostgreSQL and Redis were disposable Docker services; no
production access, release tags, registry push, or deployment was performed.

| Acceptance | Verification and result |
| --- | --- |
| A1–A6 | Manager PHPUnit: **206 tests, 865 assertions passed**, including PostgreSQL integration. Covered migration, shared dictionary/cascades, normalization, authenticated API and CSRF forms, omitted/empty/invalid input, transactional rollback, publication failure/retry, import replacement, legacy preservation, and unchanged topic-tag publication behavior. |
| A7–A8, A14–A15 | `cd apps/api && go test ./...` passed. Loader and HTTP coverage verifies canonical/absent/invalid tags, immutable metadata, normalized exact filters, ordering, empty results, header-free discovery, and existing catalog/run behavior. |
| A9–A13 | `cd apps/spa-dev && npm test`: **22 tests passed**. Includes audience configuration, selection/lock, explicit quiz and advertising headers, shared topic IDs, stale catalog/locale/availability responses, and loading recovery. `npm run lint` and `npm run build` passed. |
| A9–A13 | Headless Chrome against disposable services verified multi-theme selection, URL override rejection, existing topic deep links, a complete Kubernetes run, session locking, reset/new-session theme change, independent Dev ads, empty/error/retry/no-topic states, and the Manager tag form/list. Desktop and 390px mobile rendering were inspected; no horizontal overflow or browser errors occurred. |
| A10–A12 | Final Docker image `quickquiz-spec005-spa:local` built successfully. Read-only startup and runtime configuration were checked, including default, normalized custom, empty, invalid, non-ASCII, and overlong values. The same final image served `cloud` and rejected an explicit empty tag. Chrome completed a single-theme Kubernetes run through that image and discarded a delayed solution after a new session. |
| A14–A15 | Protected Manager edit → publication → running API still serving its old snapshot → API reload → updated discovery passed. A Redis-backed run remained accessible after restart. An active untagged `dslab` theme remained accessible through the existing fixed-theme catalog header. |
| Contracts and scope | YAML parsing, local OpenAPI references, feature/canonical contract equality, documented examples, actual discovery response, published JSON Schema, and `git diff --check` passed. SPA DSLab, its shared entrypoint, and Ads API have no changes. |

Selected UI captures are versioned for PR review: [SPA theme selection](screenshots/spa-kubernetes.png),
[mobile layout](screenshots/spa-mobile.png), and [Manager theme tags](screenshots/manager-theme-tags.png).
They use synthetic content and were captured before the version-label update.
Additional captures remain in the ignored local directory `_temp/spec-005/screenshots/`:
desktop, locked session, empty/error/no-topic states, and Manager theme list.
These are review captures, not a manual QA deliverable. No real question bank was added.

Release versions are prepared below. Migration of production data and production
deployment remain separate follow-up steps. Before releasing SPA Dev, explicitly tag the
intended production themes and publish/reload the compatible API as described
in [plan.md](plan.md#rollout-compatibility-and-rollback).


## Approved Follow-up: Automatic Session Closure — A16 / R6

The user clarified that exhaustion must close the session automatically and
open its consolidated result. The existing new-session action remains; no new
navigation actions were introduced.

- [x] Reuse result aggregation and backend reset for automatic/manual closure;
      bypass intermediate run/topic screens when exhaustion is confirmed.
- [x] Preserve all completed runs and answers, stop redundant state recovery
      while a loaded result is being finalized, and block a new session until
      reset finishes.
- [x] Add seven composable regressions: direct closure, multiple-run aggregation,
      completion found by polling, availability/reset failures, pending reset,
      and early manual closure. Frontend suite now has **29 passing tests**;
      lint and production build also passed.
- [x] Rebuild and update only SPA Dev in the local Compose preview. Headless
      Chrome completed a two-question Kubernetes session with one wrong answer,
      observed only the consolidated session result (2 answers, 50% accuracy),
      one theme-scoped reset, no closure dialog, and a working new-session action.
      Capture: `_temp/local-preview/automatic-session-result.png` (ignored).

The local preview remains available at `http://localhost:18082`; all seven
preview services were healthy after the update. Production was not changed.


## Prepared Release Versions

Confirmed the latest tags on `origin` before advancing these additive changes:

| Service | Previous published tag | Prepared version | Versioned files |
| --- | --- | --- | --- |
| Quiz API | `api/v0.7.0` | `0.8.0` | Upcoming changelog; the API release version is determined by its Git tag, with no separate runtime version field. |
| Manager | `manager/v0.12.0` | `0.13.0` | Default service version, empty-value fallback and its existing test, environment examples, Compose defaults, and changelog. |
| SPA Dev | `spa-dev/v0.8.0` | `0.9.0` | `package.json`, root package-lock metadata, and changelog; corrects the older `0.6.0` package label. |

OpenAPI document versions remain independently versioned: public API `0.3.0`
and Manager administration `0.4.0`, already incremented for this feature.
Historical release records, SPA DSLab, Ads API, and dependency versions were
not changed. No release tag was created or pushed during version preparation.
