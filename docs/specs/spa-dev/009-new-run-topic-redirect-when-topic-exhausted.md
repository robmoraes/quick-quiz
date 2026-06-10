# Feature: redirect new run to topic selection when topic is exhausted

## Intent

Problem: after a player completes a run and clicks "New run", the SPA always
opens the difficulty selection screen for the current topic. When the current
topic has no available questions left, every difficulty is disabled and the
player must manually go back to topic selection before they can continue.

Users or stakeholders:

- players continuing a quiz session after a run result;
- frontend maintainers;
- project maintainer.

Desired outcome: when the current topic is exhausted, the "New run" action
takes the player directly to topic selection so they can choose another
available topic without landing on a dead-end difficulty screen.

Non-goals:

- change backend question exhaustion rules;
- change the session result flow;
- auto-select a different topic;
- reset the current game session;
- change difficulty labels or visual design.

## Scope

In scope:

- update the SPA "New run" transition from the run result screen;
- use existing session difficulty and topic availability APIs;
- preserve current behavior when the selected topic still has available
  difficulties;
- preserve existing session state, sounds, and event semantics.

Out of scope:

- backend API changes;
- new persistence;
- new routing;
- redesigning the difficulty or topic screens;
- changing fatal hardcore loss behavior.

Assumptions:

- the backend remains authoritative for question availability;
- topic and difficulty availability can change after each completed run;
- `GET /session/difficulties` for the selected topic is enough to determine
  whether the selected topic can start another run;
- `GET /session/topics` is already used by the topic selection screen to mark
  exhausted topics as unavailable.

Dependencies:

- existing run result screen;
- existing catalog/session availability composable behavior;
- existing frontend API client methods for session topics and difficulties.

## Behavior

1. When the player clicks "New run" from a run result, the SPA must refresh
   difficulty availability for the currently selected topic before deciding the
   next screen.
2. If at least one difficulty for the current topic is available, the SPA must
   keep the current behavior and open the difficulty selection screen.
3. If no difficulty for the current topic is available, the SPA must open the
   topic selection screen directly.
4. When redirecting to topic selection, the SPA must refresh topic availability
   so exhausted topics are disabled and available topics remain selectable.
5. Redirecting to topic selection must not reset the game session.
6. Redirecting to topic selection must not request or show the session result.
7. Redirecting to topic selection must not auto-select a different topic.
8. Redirecting to topic selection must not emit a topic-selected session event
   until the player explicitly selects and advances with a topic.
9. If all topics are exhausted, the topic selection screen should represent
   that state using the existing disabled topic and session-end behavior.
10. API errors while checking availability must keep the existing error display
    behavior and must not strand the player in an inconsistent active-run
    state.

## Acceptance Examples

### Scenario: current topic still has questions

Given the player has completed a run

And the selected topic still has at least one available difficulty

When the player clicks "New run"

Then the SPA opens the difficulty selection screen

And the available difficulties match the refreshed session availability.

### Scenario: current topic is exhausted

Given the player has completed a run

And the selected topic has no available difficulties

And another topic still has available questions

When the player clicks "New run"

Then the SPA opens the topic selection screen

And the exhausted current topic is disabled

And the player can select another available topic.

### Scenario: entire session is exhausted

Given the player has completed a run

And every topic in the current session is exhausted

When the player clicks "New run"

Then the SPA opens the topic selection screen

And all topics are unavailable

And the existing session-end/result action remains the way to finish the
session.

### Scenario: player explicitly chooses another topic

Given the SPA redirected from "New run" to topic selection because the previous
topic was exhausted

When the player selects an available topic and advances

Then the SPA opens difficulty selection for the newly selected topic

And the topic-selected session event is emitted only for that explicit action.

## Data and Contracts

Inputs:

- current selected topic;
- current session ID;
- existing session difficulty availability response;
- existing session topic availability response;
- player action from the run result screen.

Outputs:

- difficulty selection screen when the current topic can continue;
- topic selection screen when the current topic is exhausted;
- unchanged session result behavior.

API, schema, event, or CLI changes:

- none.

Persistence changes:

- none.

Machine-readable contract:

- not required.

## Quality Attributes

Security:

- no frontend-only decision may override backend-authoritative exhaustion
  rules.

Privacy:

- no new personal data or tracking.

Accessibility:

- the redirected topic screen must keep existing labels, disabled states, and
  keyboard behavior.

Performance:

- the change should avoid unnecessary duplicate availability requests beyond
  the difficulty check and, when needed, the topic refresh.

Reliability:

- the transition must not leave stale run IDs, stale result state, or an active
  question after "New run";
- the transition must not duplicate topic-selected events.

Observability:

- existing availability refresh events should remain sufficient;
- no new event name is required unless implementation shows a clear debugging
  gap.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- none.

Rollback:

- revert the SPA change that updates the "New run" transition.

Monitoring:

- manual full-flow checks around topic exhaustion.

## Verification

Planned checks:

- `cd frontend && npm run lint`;
- `cd frontend && npm run build`;
- manual run-result "New run" check when the current topic still has questions;
- manual run-result "New run" check when the current topic is exhausted;
- manual check when the full session is exhausted, where practical.

Evidence to record:

- command output;
- brief summary of the transition decision and any manual checks that could not
  be run.
