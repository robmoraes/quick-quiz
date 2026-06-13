# Feature: extract question and interstitial panels

## Intent

Problem: the run-time question UI and interstitial states are separate screens
inside `IndexPage.vue`, but they are mixed with API submission logic and result
flow orchestration.

Users or stakeholders:

- frontend maintainers;
- players using the active quiz flow;
- future contributors changing question rendering, ads, or fatal loss UX.

Desired outcome: extract the active question screen and special interstitial
screens into components that render current state and emit user intent.

Non-goals:

- change answer submission behavior;
- change the advertising frequency rule;
- change fatal hardcore game rules;
- redesign the question screen.

## Scope

In scope:

- create `QuestionPanel.vue`;
- create `RunAdPanel.vue`;
- create `FatalLossPanel.vue`;
- preserve multiline prompt and option rendering;
- keep answer submission and screen transitions outside these components.

Out of scope:

- extracting result screens;
- extracting settings or rules;
- moving API calls into components;
- adding new ad providers.

Assumptions:

- `IndexPage.vue` still owns `currentQuestion`, `busy`, `feedback`, run ID,
  finish state, and interstitial state;
- the active question model remains `PublicQuestion` from `src/services/api.ts`.

Dependencies:

- existing question and option API models;
- existing ad interstitial behavior;
- existing fatal-loss behavior;
- existing styling classes.

## Behavior

1. `QuestionPanel.vue` must render topic and difficulty chips, progress bar,
   question progress text, prompt, answer options, and end-session action as
   today.
2. `QuestionPanel.vue` must preserve question prompt line breaks and option
   text line breaks.
3. `QuestionPanel.vue` must emit an answer event with the selected option ID.
4. `QuestionPanel.vue` must emit an end-session event instead of directly
   changing session state.
5. Disabled and locked answer states must match the current SPA.
6. `RunAdPanel.vue` must render the current advertising interstitial UI and
   emit a close/continue event.
7. `FatalLossPanel.vue` must render the current fatal-loss UI and emit the same
   recovery/navigation events currently handled by `IndexPage.vue`.
8. Extracted components must not import the API client.
9. Extracted components must not play sounds or emit analytics/session events;
   orchestration code remains responsible for side effects.

## Acceptance Examples

### Scenario: multiline question remains readable

Given the backend serves a prompt containing newline characters

When the question panel renders the prompt

Then the line breaks are visible in the SPA.

### Scenario: answer event is emitted

Given a question has visible options

When the player clicks an option

Then `QuestionPanel.vue` emits the selected option ID

And the parent performs the answer API call.

### Scenario: advertising interstitial continues result flow

Given the run advertising interstitial is visible

When the player closes it

Then `RunAdPanel.vue` emits a continue event

And the parent opens the result flow as before.

### Scenario: fatal loss remains special

Given a hardcore wrong answer triggers fatal loss

When the fatal-loss state appears

Then the normal run result screen is not shown

And recovery/navigation behavior remains parent-owned.

## Data and Contracts

Inputs:

- current question;
- selected topic label;
- selected difficulty label;
- busy and feedback state;
- action labels;
- interstitial and fatal-loss display state.

Outputs:

- emitted answer, end-session, close, continue, or recovery events.

API, schema, event, or CLI changes:

- none.

Persistence changes:

- none.

Machine-readable contract:

- not required.

## Quality Attributes

Security:

- no new script loading in ad placeholder components.

Privacy:

- no new tracking or identifiers.

Accessibility:

- option buttons, close controls, and fatal-loss actions must preserve
  accessible names and disabled states.

Performance:

- components must not add API requests.

Reliability:

- answer submission remains centralized to avoid duplicate requests.

Observability:

- existing session events remain emitted by the parent flow.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- none.

Rollback:

- revert this extraction PR.

Monitoring:

- manual check of question answering, interstitial close, and fatal loss.

## Verification

Planned checks:

- `cd frontend && npm run lint`;
- `cd frontend && npm run build`;
- manual smoke test with a multiline question such as
  `backend/.local/dev/pt-BR/php/3/php-3-007.json`.

Evidence to record:

- command output;
- noted manual result for multiline prompt rendering when practical.

