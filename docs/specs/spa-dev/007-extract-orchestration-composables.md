# Feature: extract SPA orchestration composables

## Intent

Problem: after UI panels are extracted, `IndexPage.vue` can still remain large
because it owns catalog loading, run lifecycle, result loading, preferences,
derived labels, screen transitions, sounds, and session events in one script.

Users or stakeholders:

- frontend maintainers;
- reviewers evaluating frontend architecture;
- future contributors adding game modes, themes, or settings.

Desired outcome: move cohesive orchestration logic into composables so
`IndexPage.vue` becomes a thin page that wires composables to components.

Non-goals:

- introduce Pinia/Vuex/global state;
- change the URL routing model;
- change backend APIs;
- change game behavior.

## Scope

In scope:

- create catalog/session metadata composable;
- create run lifecycle composable;
- create preferences/settings composable if it reduces page complexity;
- keep side effects explicit and testable;
- keep `IndexPage.vue` as the route component.

Out of scope:

- introducing persistent accounts;
- introducing a frontend test framework unless separately specified;
- changing Quasar boot configuration;
- moving unrelated layout code.

Assumptions:

- UI panel components already exist from earlier specs;
- composables may import services such as API, sounds, i18n helpers, and session
  events when those side effects are part of their explicit responsibility;
- `IndexPage.vue` remains responsible for composing the final screen.

Dependencies:

- extracted UI components;
- existing frontend services;
- existing API types;
- existing i18n setup.

## Behavior

1. `useQuizCatalog.ts` must own catalog/session topic loading, topic filtering,
   selected topic metadata, difficulty option derivation, and difficulty
   availability state.
2. `useQuizRun.ts` must own run creation, answer submission, result loading,
   fatal loss handling, ad-result transition decisions, and run/session screen
   transitions.
3. `useQuizPreferences.ts` may own locale, audio settings, and related option
   lists when that reduces `IndexPage.vue` complexity.
4. Composables must expose clear reactive state and command functions.
5. Composables must avoid hidden work in watchers when an explicit command
   function would be clearer.
6. Composables must keep API error mapping explicit.
7. Existing sounds and session event emissions must remain behaviorally
   unchanged.
8. `IndexPage.vue` should contain page-level wiring, screen selection, and
   component composition after this step.
9. The final refactor should reduce `IndexPage.vue` substantially enough that
   maintainers can understand the page flow without scrolling through every UI
   detail.

## Acceptance Examples

### Scenario: page wires components to composables

Given the refactor is complete

When a maintainer opens `IndexPage.vue`

Then they can identify screen selection and component wiring quickly

And detailed run/catalog/preference logic lives in named composables.

### Scenario: run lifecycle remains unchanged

Given the player starts and completes a normal run

When the composable-based flow is used

Then API calls, sounds, events, result behavior, and available actions match the
pre-refactor SPA.

### Scenario: fatal hardcore remains enforced in UI flow

Given the player loses a hardcore run

When the answer response indicates fatal loss

Then the SPA still skips the normal result flow

And local session recovery behavior remains unchanged.

### Scenario: catalog errors remain visible

Given the catalog or session metadata request fails

When the start screen loads

Then the same user-visible error behavior appears.

## Data and Contracts

Inputs:

- existing API services;
- current locale and theme configuration;
- current session ID behavior;
- player actions emitted by panel components.

Outputs:

- reactive state for page components;
- command functions for page-level actions;
- unchanged user-visible screens.

API, schema, event, or CLI changes:

- none.

Persistence changes:

- none.

Machine-readable contract:

- not required.

## Quality Attributes

Security:

- no new trust boundary; backend remains authoritative for game state.

Privacy:

- no new tracking or persisted profile data.

Accessibility:

- orchestration extraction must not remove labels or focus behavior from UI
  components.

Performance:

- composables must not duplicate catalog, result, or session requests.

Reliability:

- side effects must remain centralized enough to avoid double answer
  submissions, duplicate sounds, or duplicate session events.

Observability:

- existing session events must keep the same names and payload semantics.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- none.

Rollback:

- revert this extraction PR or the composable-specific commits.

Monitoring:

- manual full-flow check after refactor completion.

## Verification

Planned checks:

- `cd frontend && npm run lint`;
- `cd frontend && npm run build`;
- manual smoke test for catalog loading, start run, answer, result, fatal loss,
  session end, settings, and rules.

Evidence to record:

- command output;
- PR summary with final `IndexPage.vue` line count and extracted files.

