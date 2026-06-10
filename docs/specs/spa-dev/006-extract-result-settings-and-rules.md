# Feature: extract result, settings, and rules UI

## Intent

Problem: result review, settings, and rules UI add substantial markup and state
inside `IndexPage.vue`, even though they are mostly self-contained display and
form surfaces.

Users or stakeholders:

- frontend maintainers;
- players reviewing run/session results;
- reviewers evaluating maintainability of the SPA.

Desired outcome: extract result display, settings dialog, and rules modal into
focused components while preserving current data ownership and behavior.

Non-goals:

- redesign result tables;
- change locale or audio preference behavior;
- change the RFC/rules document source;
- add new result metrics.

## Scope

In scope:

- create `ResultPanel.vue`;
- create `SettingsDialog.vue`;
- create `RulesModal.vue`;
- preserve run result and session result behavior;
- preserve settings controls and rules document rendering.

Out of scope:

- moving result loading API calls;
- adding a new preferences store;
- changing result schemas;
- adding persistent player profiles.

Assumptions:

- `IndexPage.vue` still owns result data, settings state, rules document text,
  and dialog open/closed state during this step;
- existing i18n keys remain valid.

Dependencies:

- existing `RunResult` shape;
- existing local settings state;
- existing rules assets and i18n messages;
- existing result table CSS classes.

## Behavior

1. `ResultPanel.vue` must render the same run result and session result content
   as today.
2. Result actions such as new run, end session, reset session, and start over
   must be emitted to the parent.
3. The answer review table must preserve its current columns, icons, sticky
   header behavior, and line wrapping.
4. `SettingsDialog.vue` must render the same locale and audio controls as
   today.
5. Settings changes must be emitted to the parent unless the parent passes
   v-model bindings for those fields.
6. `RulesModal.vue` must render the same rules/RFC document content as today.
7. Closing settings or rules must preserve the current behavior and focus
   expectations.
8. Extracted components must not call result or session APIs directly.

## Acceptance Examples

### Scenario: run result remains unchanged

Given the player completes a run

When the result panel appears

Then the summary values, finish reason, answer review, and available actions
match the current SPA.

### Scenario: session result remains unchanged

Given the player ends a session

When the result panel appears for session context

Then accumulated values and actions match the current SPA.

### Scenario: settings update remains parent-owned

Given the settings dialog is open

When the player changes locale or audio preferences

Then the parent state is updated through events or v-model

And existing preference behavior is preserved.

### Scenario: rules modal displays the same document

Given the player opens the rules action

When the rules modal is visible

Then the same localized document text is displayed

And closing returns the player to the same screen.

## Data and Contracts

Inputs:

- run or session result data;
- result context labels;
- settings values and option lists;
- rules document title and body;
- busy/error state where applicable.

Outputs:

- emitted result action events;
- emitted settings updates;
- emitted close events.

API, schema, event, or CLI changes:

- none.

Persistence changes:

- none.

Machine-readable contract:

- not required.

## Quality Attributes

Security:

- rules rendering must remain plain text or safe framework-rendered content.

Privacy:

- settings extraction must not add new persistence.

Accessibility:

- dialogs must preserve accessible labels, close controls, and keyboard
  behavior.

Performance:

- large rules text should remain local and should not trigger extra network
  requests.

Reliability:

- result actions remain centralized in the parent to avoid inconsistent session
  transitions.

Observability:

- existing frontend session events remain parent-owned.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- none.

Rollback:

- revert this extraction PR.

Monitoring:

- manual check of run result, session result, settings, and rules.

## Verification

Planned checks:

- `cd frontend && npm run lint`;
- `cd frontend && npm run build`;
- manual smoke test for result actions, settings changes, and rules modal.

Evidence to record:

- command output;
- PR summary listing extracted UI surfaces.

