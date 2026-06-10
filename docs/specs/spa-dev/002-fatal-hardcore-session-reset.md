# Feature: fatal hardcore session reset

## Intent

Problem:

Hardcore difficulty ends the current run on the first wrong answer, but the
session remains alive. Because served questions are exhausted at session level,
a player can repeatedly fail hardcore runs and shrink the available question
pool until a later run requires very few correct answers.

Users or stakeholders:

- Players choosing the fatal/hardcore challenge.
- Frontend maintainers.
- Backend API maintainers.
- Project maintainers responsible for game balance.

Desired outcome:

Make hardcore mode truly fatal. If the player answers any hardcore question
wrong, the whole current game session for the selected theme is reset. The
player loses all progress immediately and does not get a normal run result
screen for that failed run.

Non-goals:

- Do not change non-hardcore difficulty behavior.
- Do not add accounts, lives, retries, score history, or persistent player
profiles.
- Do not preserve a post-mortem result for the failed hardcore run.
- Do not redesign the result screen.
- Do not change the API `difficulty` concept or stored value.

## Scope

In scope:

- Backend-enforced session reset on wrong hardcore answer.
- SPA handling for fatal loss without relying on `/result`.
- UX copy that makes the risk clear before starting hardcore.
- Tests covering backend enforcement and SPA behavior where practical.

Out of scope:

- Storing death history.
- Showing a detailed answer review after fatal loss.
- Resetting content or question packages.
- Changing session behavior for easy, normal, or hard.

Assumptions:

- Hardcore difficulty is `difficulty = 4`.
- Theme is selected through `X-QuickQuiz-Theme`.
- The current backend run store already has a theme-scoped
  `DeleteBySession(sessionID, theme)` operation.
- Losing the result is intentional, not an error state.
- The SPA already has local session state that can be cleared or rotated after
  a fatal loss.

Dependencies:

- API theme backend foundation.
- Existing run answer flow.
- Existing session reset behavior.
- Existing SPA difficulty/start-run flow.

## Behavior

1. When a player answers a hardcore question correctly, the current behavior
   remains unchanged.
2. When a player answers a hardcore question incorrectly, the backend must treat
   it as a fatal session loss.
3. Fatal session loss must reset the current game session for the selected
   theme on the backend.
4. The backend must enforce the reset. The SPA must not be the authority for
   clearing fatal progress.
5. The backend may delete the failed run as part of the session reset.
6. If the SPA attempts to load `/api/runs/{runId}/result` after fatal loss and
   receives `run_not_found`, it must treat that as an expected fatal-loss
   outcome.
7. The SPA must not show the normal run result screen after fatal loss.
8. The SPA must clear or rotate local session state after fatal loss so future
   runs start from a fresh session.
9. The SPA must return the player to an appropriate start/selection screen
   after fatal loss.
10. The SPA must show clear copy before starting hardcore that a wrong answer
    resets the whole session.
11. Session reset must be scoped to the selected theme. A fatal loss in `dev`
    must not reset another theme's backend progress.
12. Non-hardcore wrong answers must continue to behave as they do today.

## API Contract

The answer endpoint remains:

```text
POST /api/runs/{runId}/answers
X-QuickQuiz-Theme: dev
X-QuickQuiz-Session-ID: <session-id>
```

For a wrong hardcore answer, the backend should still return the existing
answer response shape when possible:

```json
{
  "correct": false,
  "finished": true,
  "finishReason": "hardcore_wrong_answer"
}
```

After this response, the backend session state for that `sessionID` and theme
is reset. The failed run result does not need to remain readable.

If the SPA later calls:

```text
GET /api/runs/{runId}/result
```

then this response is acceptable and expected:

```json
{
  "code": "run_not_found",
  "message": "Run not found"
}
```

The backend implementation should include a short code comment near the fatal
branch explaining that deleting session state on hardcore wrong answer is a game
rule and prevents client-side manipulation of fatal progress.

## SPA Contract

The SPA must:

- warn before starting hardcore/fatal mode;
- detect `finishReason === "hardcore_wrong_answer"` from answer responses;
- avoid opening the normal result flow for fatal loss;
- reset local session state after fatal loss;
- show a concise fatal-loss state or notification;
- return to the start/selection flow with fresh backend progress.

The SPA may also handle `run_not_found` while loading a result as fatal loss if
the last known flow was a wrong hardcore answer.

## Acceptance Examples

### Scenario: wrong hardcore answer resets session

Given the player is in theme `dev`

And has used several questions in the current session

And starts a hardcore run

When the player answers the current question incorrectly

Then the backend returns `finishReason: "hardcore_wrong_answer"`

And deletes backend progress for the current session and theme

And the failed run result is not required to be readable.

### Scenario: SPA does not show result after fatal loss

Given the player answers a hardcore question incorrectly

When the answer response includes `finishReason: "hardcore_wrong_answer"`

Then the SPA does not navigate to the normal result screen

And the SPA resets local session state

And the SPA returns to the start or selection flow.

### Scenario: run result missing is expected after fatal loss

Given the player has just lost a hardcore run

When the SPA calls `/api/runs/{runId}/result`

And the backend returns `run_not_found`

Then the SPA treats it as fatal loss

And does not show a generic error.

### Scenario: non-hardcore wrong answer

Given the player is in difficulty `1`, `2`, or `3`

When the player answers incorrectly

Then the backend does not reset the whole session

And the SPA keeps the current non-hardcore behavior.

### Scenario: theme-scoped reset

Given the same session ID has progress in theme `dev`

And another theme has independent progress

When a wrong hardcore answer happens in `dev`

Then only the selected theme's session progress is reset.

## Data and Contracts

Inputs:

- selected theme header;
- session ID header;
- run ID;
- answer request payload;
- difficulty of the active run.

Outputs:

- existing answer response with `hardcore_wrong_answer`;
- cleared backend session state for selected theme;
- SPA fatal-loss transition instead of run result.

API, schema, event, or CLI changes:

- no new endpoint is required;
- no response schema change is required.

Persistence changes:

- none beyond deleting in-memory run/session state.

Machine-readable contract:

- `finishReason: "hardcore_wrong_answer"` remains the fatal signal;
- `run_not_found` after known fatal loss is an expected consequence.

## Quality Attributes

Security:

- backend must enforce the reset so players cannot avoid fatal loss by blocking
  frontend code.

Privacy:

- no new tracking or persistent profile data.

Accessibility:

- hardcore warning and fatal-loss message must be readable by assistive
  technologies.

Performance:

- reset is an in-memory delete operation and should be cheap.

Reliability:

- frontend must recover cleanly if the result is already gone.

Observability:

- backend logs may include the theme and session reset reason, without player
  personal data.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- none required.

Rollback:

- standard deploy rollback restores current hardcore behavior.

Monitoring:

- manual gameplay checks for hardcore wrong answer;
- backend test coverage for reset behavior.

## Verification

Planned checks:

- backend tests for wrong hardcore answer resetting selected-theme session
  progress;
- backend tests confirming non-hardcore wrong answers do not reset the session;
- frontend lint and build;
- manual SPA check for fatal-loss UX.

Evidence to record:

- command output and manual check notes.
