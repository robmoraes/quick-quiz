# Feature: IndexPage refactor boundaries

## Intent

Problem: `frontend/src/pages/IndexPage.vue` has grown to more than 1600 lines
and now mixes screen orchestration, API flow, UI rendering, dialogs, result
review, settings, rules, sounds, and session events in one file. This makes
maintenance harder and weakens the project as a fullstack portfolio example.

Users or stakeholders:

- frontend maintainers;
- reviewers evaluating the codebase as a portfolio;
- future contributors adding game flows, themes, or monetization screens.

Desired outcome: define small, low-risk refactor steps that split the SPA into
clear UI components and composables while preserving the current behavior.

Non-goals:

- redesign the UI;
- change backend APIs;
- change game rules;
- introduce a global state library;
- rewrite the SPA from scratch.

## Scope

In scope:

- identify stable component boundaries;
- define prop and event style for extracted components;
- keep `IndexPage.vue` as the initial screen orchestrator;
- keep behavior, labels, i18n keys, sounds, and session events unchanged.

Out of scope:

- visual redesign;
- new routes;
- API schema changes;
- backend changes;
- test framework migration.

Assumptions:

- Quasar and Vue Composition API remain the frontend foundation;
- `IndexPage.vue` currently owns the complete quiz screen flow;
- refactors should be delivered in small PRs with lint and build validation.

Dependencies:

- existing frontend API client in `src/services/api.ts`;
- existing i18n messages;
- existing sound and session event services;
- current Quasar component library.

## Behavior

1. The refactor must preserve all current user-visible behavior.
2. The refactor must preserve existing API requests and response handling.
3. The refactor must preserve current i18n keys and labels unless a later spec
   explicitly changes copy.
4. `IndexPage.vue` must remain the route page while refactor steps are in
   progress.
5. Extracted components must receive data through props and report intent
   through events.
6. Extracted components must not import the API client directly unless they are
   explicitly defined as orchestration components in a later spec.
7. Extracted components must not own cross-screen game state.
8. Shared frontend types should be moved out of `IndexPage.vue` only when doing
   so reduces duplication or clarifies component contracts.
9. Each implementation step must be independently buildable and shippable.
10. The final structure should make it obvious where to change start flow,
    difficulty selection, question rendering, result rendering, dialogs, and
    orchestration logic.

## Component Boundary Plan

Initial target components:

- `StartPanel.vue`: topic selection, topic description, rules action, next/end
  session actions.
- `DifficultyPanel.vue`: selected topic summary, difficulty options, difficulty
  explanation, start/end session actions.
- `QuestionPanel.vue`: run progress, multiline prompt, answer options, end
  session action.
- `ResultPanel.vue`: run/session summary, answer review, next-run/session
  actions.
- `RunAdPanel.vue`: result advertising interstitial.
- `FatalLossPanel.vue`: hardcore fatal-loss state.
- `SettingsDialog.vue`: locale and audio settings.
- `RulesModal.vue`: RFC/rules document overlay.

Initial target composables:

- `useQuizRun.ts`: run creation, answer submission, result loading, fatal loss
  handling, and screen transitions.
- `useQuizCatalog.ts`: catalog/session topic loading, topic filtering, selected
  topic and difficulty metadata.
- `useQuizPreferences.ts`: locale/audio preference state.

The component extraction may happen before composables exist. Until then,
`IndexPage.vue` remains the owner of state and passes props/events.

## Acceptance Examples

### Scenario: refactor plan is actionable

Given a maintainer wants to reduce `IndexPage.vue`

When they read the SPA refactor specs

Then they can implement one numbered spec at a time

And each spec has clear scope, acceptance examples, and verification checks.

### Scenario: behavior is preserved

Given the SPA has been partially refactored

When a player starts a run, answers questions, finishes a run, changes
preferences, and opens the rules document

Then the behavior matches the pre-refactor SPA.

### Scenario: component does not own orchestration

Given `QuestionPanel.vue` renders the active question

When a player clicks an answer option

Then the component emits an answer intent

And the page or composable performs the API call.

## Data and Contracts

Inputs:

- current SPA state from `IndexPage.vue` or later composables;
- existing API models from `src/services/api.ts`;
- existing i18n translation function output.

Outputs:

- same screens and actions currently available in the SPA;
- smaller components with explicit prop and event contracts.

API, schema, event, or CLI changes:

- none.

Persistence changes:

- none.

Machine-readable contract:

- not required.

## Quality Attributes

Security:

- no new third-party code or script loading.

Privacy:

- no new player identifiers or persistence.

Accessibility:

- extracted components must preserve existing labels, accessible names, focus
  behavior, and keyboard access.

Performance:

- no additional API calls caused by component extraction.

Reliability:

- a partial refactor must remain releasable after every step.

Observability:

- existing frontend session events must remain unchanged.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- none.

Rollback:

- standard deploy rollback or revert of the small refactor PR.

Monitoring:

- manual run-through of the main SPA flow after each step.

## Verification

Planned checks:

- `cd frontend && npm run lint`;
- `cd frontend && npm run build`;
- manual smoke test of start, difficulty, question, result, settings, and rules
  screens when practical.

Evidence to record:

- command output;
- short PR notes listing which component boundary changed.

