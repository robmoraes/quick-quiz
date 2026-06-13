# Feature: final SPA refactor acceptance

## Intent

Problem: component extraction can reduce file size but still leave unclear
ownership, behavior drift, or inconsistent contracts unless the final target is
explicit.

Users or stakeholders:

- project maintainer;
- frontend maintainers;
- portfolio reviewers.

Desired outcome: define the final acceptance criteria for the SPA refactor so
the work is considered done only when maintainability improves without behavior
regression.

Non-goals:

- require a specific exact line count;
- require a new testing framework;
- require visual redesign.

## Scope

In scope:

- final structure expectations;
- behavior parity expectations;
- validation and review evidence;
- maintainability acceptance criteria.

Out of scope:

- backend refactors;
- feature additions;
- design system redesign;
- new analytics.

Assumptions:

- specs `003` through `007` have been implemented or consciously adjusted by
  maintainers;
- lint and build remain the minimum automated frontend checks.

Dependencies:

- extracted components;
- extracted composables where useful;
- existing frontend services and API contracts.

## Behavior

1. `IndexPage.vue` must be primarily responsible for page composition and
   high-level screen wiring.
2. UI-heavy screen sections must live in named components.
3. Run, catalog, and preference orchestration should live in named composables
   when that makes ownership clearer.
4. The refactor must preserve current behavior for start, difficulty, question,
   answer, result, session end, reset, rules, settings, mobile ad interstitial,
   and fatal hardcore loss flows.
5. The refactor must preserve current API contracts.
6. The refactor must preserve existing i18n behavior and locale selection.
7. The refactor must preserve multiline question and option rendering.
8. The refactor must preserve current CSS behavior unless a specific visual
   adjustment is documented.
9. The final code structure must make future changes easier to place without
   reopening a 1600-line route component.
10. The final PR or series must include validation output and a short summary of
    extracted files and responsibilities.

## Acceptance Examples

### Scenario: maintainer locates question UI

Given a maintainer needs to change how active questions render

When they inspect the frontend structure

Then they can find a dedicated question panel component

And do not need to parse unrelated settings, result, or catalog code first.

### Scenario: maintainer locates run behavior

Given a maintainer needs to change answer submission flow

When they inspect the frontend structure

Then they can find run lifecycle logic in the page or a named run composable

And answer side effects are not duplicated across UI components.

### Scenario: full flow still works

Given the refactor is complete

When a player completes the main quiz flow

Then all screens and actions behave as before.

### Scenario: portfolio review

Given a reviewer opens the SPA code

When they inspect the page, components, services, and composables

Then the separation of concerns is clear and consistent with a maintainable
Vue/Quasar application.

## Data and Contracts

Inputs:

- existing frontend API models;
- player actions from UI components;
- existing locale, session, sound, and theme configuration.

Outputs:

- same user-visible SPA behavior;
- clearer frontend file organization.

API, schema, event, or CLI changes:

- none.

Persistence changes:

- none.

Machine-readable contract:

- not required.

## Quality Attributes

Security:

- no new trust in frontend-only state for backend-authoritative game rules.

Privacy:

- no new personal data or tracking.

Accessibility:

- no regression in accessible labels, keyboard behavior, focus handling, or text
  readability.

Performance:

- no duplicated API requests or large new dependencies.

Reliability:

- no duplicate answer submissions, duplicate result transitions, or stale
  session state after refactor.

Observability:

- existing session event names and semantics remain unchanged.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- none.

Rollback:

- revert the specific refactor PR if behavior regresses.

Monitoring:

- manual full-flow checks and frontend build validation.

## Verification

Planned checks:

- `cd frontend && npm run lint`;
- `cd frontend && npm run build`;
- manual full-flow smoke test;
- manual mobile viewport check for result interstitial where practical;
- manual check of multiline prompt rendering.

Evidence to record:

- command output;
- final `IndexPage.vue` line count;
- summary of component and composable responsibilities.

