# API: Theme backend foundation

## Intent

Problem:

QuickQuiz can grow beyond developer quizzes. The backend and manager should not
be tied to a single frontend atmosphere such as Dev, while individual frontends
can remain theme-specific and reinterpret generic quiz concepts for their
audience.

Users or stakeholders:

- Frontend maintainers who publish one player app for a specific theme.
- Backend API maintainers who need one API for many themes.
- Manager maintainers and content administrators.
- Future players for themes such as Dev, Math, History, English, and Finance.

Desired outcome:

Introduce `theme` as the top-level content and API selection axis. The backend
API should serve multiple themes from one deployment while keeping the current
topic, difficulty, locale, run, and session concepts intact where possible.

Non-goals:

- Do not design multiple frontend themes in this spec.
- Do not require compatibility with the current local folder layout during
  implementation.
- Do not rename `difficulty` in the API to frontend-specific words such as
  `severity`.
- Do not add authentication, ranking, payments, or user profiles.
- Do not integrate theme-specific visual assets into the backend.

## Scope

In scope:

- Add `theme` as a required backend domain concept above `topic`.
- Select the active theme for public API requests, preferably through an HTTP
  header.
- Load theme metadata from `backend/.local/themes.json`.
- Move local content layout to `backend/.local/<theme>/...`.
- Keep locale as localized content, not as theme.
- Keep topic as the selectable package inside a theme.
- Keep difficulty as the API concept even when a frontend labels it differently.
- Key sessions, runs, availability, and exhaustion by theme.
- Update OpenAPI and backend tests when implemented.

Out of scope:

- Theme-specific frontend implementation.
- Theme taxonomy beyond a stable theme ID and metadata.
- Database storage.
- S3 migration details beyond preserving an equivalent prefix structure later.
- Real content migration for every future theme.
- A public endpoint that lists themes.

Assumptions:

- Theme IDs are stable machine-readable values such as `dev`, `math`,
  `history`, `english`, or `finance`.
- A frontend build normally targets one theme and sends the same theme on all
  backend requests.
- The current migrated theme is `dev`.
- The Dev frontend may call API `difficulty` values "severity", show syslog,
  present results as tests and pull requests, and use developer-oriented
  typography. Those choices stay outside the API contract.
- `topic` remains the content package inside a theme, such as `php` for Dev or
  `algebra` for Math.
- `locale` remains a BCP 47 tag such as `en-US` or `pt-BR`.
- Existing API response shapes should remain as close as possible to the current
  topic contract, adding theme selection rather than rebuilding the contract.
- Theme metadata descriptions are canonical in English (`en-US`) in this stage.
  Localized theme metadata overrides are not planned for this delivery.

Dependencies:

- Existing topic backend contract.
- Existing locale and fallback-locale behavior.
- Existing local JSON question loader.
- Existing session memory store.
- Existing OpenAPI document.

## Behavior

1. The backend must treat `theme` as a first-class domain value.
2. A theme must be selected for every player-facing request that reads catalog,
   availability, run, or result data.
3. The preferred request contract is an HTTP header:
   `X-QuickQuiz-Theme: <theme-id>`.
4. If the theme header is absent, empty, unknown, or inactive, the backend must
   reject the request with a stable machine-readable error.
5. The backend must not derive theme from locale, topic, user agent, or
   frontend text.
6. The backend must keep `difficulty` as the API field and enum/value concept.
   Frontends may localize or reinterpret the label.
7. The backend must scope catalog topics by theme.
8. The backend must scope session availability by theme.
9. The backend must scope run creation, answer validation, and result lookup by
   theme.
10. Session exhaustion must be keyed by theme, canonical question ID, topic, and
    difficulty. A question exhausted in `dev` must not affect `math`.
11. The backend must reject unknown or inactive themes.
12. The backend must keep error codes, enum values, logs, metrics, traces, and
    machine-readable IDs unlocalized.
13. The backend must keep theme IDs machine-readable and not translated.
14. The backend must continue using locale for localized prompt and option
    content.
15. The backend must discover themes from `backend/.local/themes.json`, not from
    a static `SUPPORTED_THEMES` environment variable.
16. The backend must not expose a public `GET /api/themes` endpoint in this
    stage. Theme selection remains an internal deployment/frontend concern.

## API Contract

### Theme selection header

Preferred header:

```text
X-QuickQuiz-Theme: dev
```

This header applies to:

```text
GET /api/catalog
GET /api/session/topics
GET /api/session/difficulties?topic=<topic-id>
POST /api/runs
POST /api/runs/{runId}/answers
POST /api/runs/{runId}/finish
GET /api/runs/{runId}/result
POST /api/session/reset
```

The existing locale headers and payload fields remain separate:

```text
X-QuickQuiz-Locale: pt-BR
```

### Response shape

Catalog responses should include the resolved theme:

```json
{
  "theme": "dev",
  "locale": "pt-BR",
  "fallbackLocale": "en-US",
  "topics": []
}
```

Create-run responses should include the resolved theme:

```json
{
  "theme": "dev",
  "locale": "pt-BR",
  "runId": "run_example",
  "question": {}
}
```

Run-result responses should include the resolved theme:

```json
{
  "theme": "dev",
  "runId": "run_example",
  "locale": "pt-BR",
  "topic": "php",
  "difficulty": 1,
  "finishReason": "max_questions_reached",
  "stats": {},
  "answers": []
}
```

### Local content layout

The local content root gains one level:

```text
backend/.local/themes.json
backend/.local/<theme>/index.json
backend/.local/<theme>/<locale>/index.json
backend/.local/<theme>/<locale>/<topic>/<difficulty>/<question-id>.json
```

Example:

```text
backend/.local/themes.json
backend/.local/dev/index.json
backend/.local/dev/pt-BR/index.json
backend/.local/dev/pt-BR/php/1/php-1-001.json
backend/.local/math/en-US/algebra/1/algebra-1-001.json
```

`backend/.local/themes.json` is the global theme metadata file. It is the
authoritative source for known themes and whether they are active for player
APIs.

`backend/.local/<theme>/index.json` is the central publication catalog for that
theme. Localized catalog overrides live under
`backend/.local/<theme>/<locale>/index.json`.

Theme metadata uses canonical English text in this stage:

```json
{
  "themes": [
    {
      "id": "dev",
      "name": "Development",
      "description": "Programming and software engineering quizzes.",
      "weight": 100,
      "createdAt": "2026-01-01T00:00:00-03:00",
      "active": true
    }
  ]
}
```

Question files still contain only:

```json
{
  "prompt": "Question displayed to the player",
  "correctOptions": ["Correct option"],
  "wrongOptions": ["Wrong option"]
}
```

## Acceptance Examples

### Scenario: frontend requests Dev catalog

Given the backend has a published `dev` theme

And `dev` contains the topic `php`

When the frontend sends `GET /api/catalog` with `X-QuickQuiz-Theme: dev`

Then the backend returns the `dev` catalog

And the response includes `"theme": "dev"`

And the response topic contract remains `topics`.

### Scenario: same topic ID in different themes

Given themes `dev` and `math` both have a topic ID `basics`

When a player starts a run with `X-QuickQuiz-Theme: dev` and topic `basics`

Then the backend selects questions only from `backend/.local/dev/...`

And it does not read `backend/.local/math/...`.

### Scenario: unknown theme

Given no theme `finance` is published

When a request sends `X-QuickQuiz-Theme: finance`

Then the backend rejects the request with a stable machine-readable error.

### Scenario: missing theme header

Given the backend has a published `dev` theme

When a request omits `X-QuickQuiz-Theme`

Then the backend rejects the request with a stable machine-readable error.

### Scenario: inactive theme

Given `backend/.local/themes.json` contains theme `math`

And theme `math` has `active: false`

When a request sends `X-QuickQuiz-Theme: math`

Then the backend rejects the request with a stable machine-readable error.

### Scenario: frontend-specific wording stays outside the API

Given the Dev frontend labels difficulty as severity

When it creates a run

Then the API request still sends `difficulty`

And the backend stores and returns `difficulty`.

## Data and Contracts

Inputs:

- `X-QuickQuiz-Theme` header;
- `X-QuickQuiz-Locale` header or locale payload field;
- `backend/.local/themes.json`;
- local content root using `<theme>/<locale>/<topic>/<difficulty>`.

Outputs:

- theme-scoped catalogs;
- theme-scoped runs;
- theme-scoped session availability;
- theme-scoped results.

API, schema, event, or CLI changes:

- add theme selection to public API requests;
- add `theme` to relevant public API responses;
- add stable errors for missing, unknown, and inactive themes;
- do not add public theme-listing endpoints in this stage.

Persistence changes:

- add `backend/.local/themes.json`;
- local JSON source layout gains a `<theme>` level;
- in-memory session and run records must include theme.

Machine-readable contract:

- `docs/openapi.yaml` must be updated during implementation.

## Quality Attributes

Security:

- theme is not an authorization boundary in this spec.

Privacy:

- theme selection is not personal data by itself.

Accessibility:

- not applicable to the backend API.

Performance:

- theme scoping should preserve current in-memory catalog and question loading
  characteristics.

Reliability:

- missing global theme metadata must fail clearly at startup;
- missing, inactive, or unknown request themes must fail clearly at request
  validation time.

Observability:

- logs and events should include theme as a machine-readable field.

## Rollout and Operations

Migration:

- this API spec owns the current local content migration;
- migrate current content directly in the non-versioned `backend/.local`
  directory;
- migrate current content from `backend/.local/<locale>/...` to
  `backend/.local/dev/<locale>/...`;
- migrate central catalog from `backend/.local/index.json` to
  `backend/.local/dev/index.json` if that central file is present;
- create `backend/.local/themes.json` with an active `dev` theme;
- backup of `backend/.local` is explicitly outside this spec and may be done
  manually before implementation;
- this spec must be implemented before the manager theme-management spec.

Feature flag or configuration:

- none required for theme discovery;
- the frontend must send `X-QuickQuiz-Theme`.

Rollback:

- standard deploy rollback before content layout migration;
- after migration, rollback requires restoring the old local content layout or
  shipping compatibility code.

Monitoring:

- catalog load success by theme;
- run creation failures by theme;
- missing, inactive, and unknown-theme request count.

## Verification

Planned checks:

- migration check for current local content under `backend/.local/dev`;
- theme metadata load validation for `backend/.local/themes.json`;
- backend unit tests for theme-scoped catalog loading;
- backend unit tests for session exhaustion by theme;
- backend unit tests for missing, inactive, and unknown theme rejection;
- OpenAPI contract check;
- local content load validation;
- `go test ./...`.

Evidence to record:

- test command output and any content migration notes.

## Decisions

- Missing `X-QuickQuiz-Theme` is invalid.
- Unknown or inactive themes are invalid for player APIs.
- The API does not expose `GET /api/themes` in this stage.
- Themes are discovered from `backend/.local/themes.json`.
- Current content migration to `backend/.local/dev/...` belongs to this API
  spec and runs before manager theme management.
