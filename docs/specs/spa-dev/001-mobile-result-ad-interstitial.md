# Feature: mobile result advertising interstitial

## Intent

Problem: on small screens the SPA hides all advertising slots to preserve the
quiz experience, which removes the visible monetization area exactly where many
users will play.

Users or stakeholders: players on mobile devices, project maintainers, and
future sponsors.

Desired outcome: when no layout advertising slot is visible, the SPA can show a
full-screen advertising message between the end of a run and the run result,
using a predictable low-frequency rule.

Non-goals:

- integrate a real advertising provider;
- require a minimum viewing time;
- block the session result flow;
- track users across devices or send advertising counters to the backend.

## Scope

In scope:

- a maximized advertising interstitial for the run-result flow;
- placeholder advertising region markup;
- close action that immediately continues to the run result;
- game-session result-view counter;
- prime-number frequency rule for mobile-only interstitial display.

Out of scope:

- real ad SDKs, third-party scripts, bidding, targeting, or analytics;
- backend API changes;
- consent management UI;
- timing gates before closing the interstitial.

Assumptions:

- all layout advertising slots are hidden below the same breakpoint currently
  used by the SPA mobile layout;
- viewing the run result means the SPA has completed the run result transition,
  whether or not the advertising interstitial is shown first;
- the result-view counter belongs to the current game session and resets when a
  new game session starts.

Dependencies:

- Quasar screen breakpoints;
- current SPA game session state.

## Behavior

1. The SPA must keep the normal run flow unchanged on screen sizes where at
   least one layout advertising slot is visible.
2. The SPA must keep the existing layout advertising slots as placeholders and
   must not integrate a real ad provider in this feature.
3. The SPA must increment a result-view counter for the current game session
   each time a run result is shown to the user, independent of screen size.
4. When all layout advertising slots are hidden and the incremented result-view
   counter is a prime number, the SPA must show a maximized advertising
   interstitial after the run ends and before the run result screen.
5. The interstitial must include a visible advertising placeholder region.
6. The interstitial must include a close icon that immediately opens the run
   result. There must be no minimum view duration.
7. Closing the interstitial must not increment the counter again.
8. Session result screens must not trigger this interstitial.
9. Starting a new game session must reset the result-view counter to `0`.
10. Advertising in QuickQuiz Dev exists to keep the system free and help fund
   its own infrastructure. The system is intended to be open source.

## Acceptance Examples

### Scenario: desktop run result

Given the player finishes a run on a screen where an advertising slot is visible

When the SPA loads the run result

Then the result-view counter increments

And the run result appears without the interstitial.

### Scenario: mobile non-prime count

Given all layout advertising slots are hidden

And the next result-view counter value is `6`

When the player finishes a run

Then the run result appears without the interstitial.

### Scenario: mobile prime count

Given all layout advertising slots are hidden

And the next result-view counter value is `2`

When the player finishes a run

Then the SPA opens a maximized advertising interstitial

And the close icon immediately opens the run result.

### Scenario: new game session resets the counter

Given the current game session result-view counter is `7`

When the player starts a new game session

Then the result-view counter resets to `0`.

### Scenario: session result

Given all layout advertising slots are hidden

When the player requests the session result

Then the SPA does not show the advertising interstitial.

## Data and Contracts

Inputs:

- current Quasar screen breakpoint;
- current game session result-view counter, defaulting to `0`;
- run result loaded from the existing backend API.

Outputs:

- maximized interstitial with advertising placeholder;
- run result screen.

API, schema, event, or CLI changes:

- none.

Persistence changes:

- none.

Machine-readable contract:

- not required.

## Quality Attributes

Security:

- no third-party advertising script is loaded by this feature.

Privacy:

- the counter is local to the current game session and does not identify the
  player.

Accessibility:

- the interstitial close action must have an accessible label;
- focus handling should follow Quasar dialog behavior.

Performance:

- the placeholder must not add network requests.

Reliability:

- starting a new game session must recover the counter to `0`.

Observability:

- existing frontend session events are sufficient for the MVP.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- none.

Rollback:

- standard deploy rollback.

Monitoring:

- manual mobile viewport checks for the fifth run result.

## Verification

Planned checks:

- frontend lint;
- frontend build;
- manual viewport check where practical.

Evidence to record:

- command output or noted inability to run checks.
