# API: Topic backend contract

## Intent

Problem:

The backend API currently uses `language` for the selectable quiz package. That
term is too narrow because the same selection axis also covers frameworks,
methodologies, books, and other development topics.

Users or stakeholders:

- Players who choose the quiz subject.
- Frontend and manager clients that consume the public API.
- Content maintainers who publish question packages.
- Backend maintainers who need domain names that match product behavior.

Desired outcome:

Replace `language` with `topic` in the backend API and backend domain model. The
backend must run and pass tests with the new topic-based contracts even if
frontend and manager clients break temporarily.

Non-goals:

- Do not keep API compatibility with `language`.
- Do not introduce topic categories, topic kinds, or taxonomy in this stage.
- Do not change the local question folder layout.
- Do not migrate existing question files.
- Do not fix frontend or manager clients in this stage.
- Do not change quiz rules, difficulty rules, locale resolution, or session
  exhaustion behavior.

## Scope

In scope:

- Backend domain terminology for the selectable quiz package changes from
  `language` to `topic`.
- Public backend API fields, query parameters, OpenAPI schemas, and docs use
  `topic`.
- `GET /api/catalog` remains the catalog endpoint and changes its payload from
  `languages` to `topics`.
- Existing language-based backend endpoints and request fields are removed or
  replaced.
- Backend tests are updated to the topic contract.
- Documentation for the JSON folder structure refers to `<topic>` instead of
  `<language>`, while the physical directory structure remains unchanged.

Out of scope:

- Frontend refactor from language wording to topic wording.
- Manager UI refactor from language wording to topic wording.
- Compatibility shims for existing clients.
- Topic categorization.
- Database persistence.
- Authentication or authorization.
- S3 key migration.

Assumptions:

- The project is in a controlled development phase.
- Local commits protect the current state, so breaking client contracts is
  acceptable.
- A topic ID is the existing stable package key, such as `php`, `go`,
  `laravel`, `scrum`, `xgh`, `clean-code`, or `pragmatic-programmer`.
- The existing local path segment currently named `<language>` already acts as a
  topic ID and can keep the same filesystem position.
- Session exhaustion remains keyed by canonical question ID, topic ID, and
  difficulty.
- Locale remains separate from topic and continues to represent localized
  content using BCP 47 tags.

Dependencies:

- Existing backend API endpoints and OpenAPI contract.
- Existing question package loader and local JSON source layout.
- Existing session memory store.

## Behavior

1. The canonical backend term for the selectable quiz package must be `topic`.
2. A topic must be identified by a stable string ID.
3. A topic must have the same metadata previously exposed for a language:
   `id`, `label`, `description`, `weight`, `createdAt`, and `difficulties`.
4. The backend must not require or expose category information for topics in
   this stage.
5. Backend API requests must use `topic`, not `language`.
6. Backend API responses must use `topics` and `topic`, not `languages` and
   `language`.
7. Requests that still send `language` instead of `topic` must fail as invalid
   input or unknown fields, depending on the endpoint.
8. The backend must continue resolving localized content by `locale`, not by
   topic.
9. The backend must continue falling back to `FALLBACK_LOCALE` when localized
   questions are unavailable for the requested topic and difficulty.
10. The backend must continue loading only active packages from the central
    index.
11. The backend must continue validating that every supported locale replicates
    the canonical question package paths from the fallback locale.
12. Error codes, enum values, logs, metrics, traces, and machine-readable IDs
    must not be localized.

## API Contract

### Replaced endpoints

The backend must expose topic-based endpoints:

```text
GET /api/catalog
GET /api/session/topics
GET /api/session/difficulties?topic=<topic-id>
POST /api/runs
GET /api/runs/{runId}/result
```

The backend does not need to keep these language-based compatibility endpoints:

```text
GET /api/session/languages
GET /api/session/difficulties?language=<topic-id>
```

`GET /api/catalog` remains available. This refactor changes its response
contract from `languages` to `topics`; it does not require a new catalog route.

### Expected topic behavior

- `GET /api/catalog` returns the published topic catalog for the resolved
  locale.
- `GET /api/session/topics` returns topic availability for the current session.
- `GET /api/session/difficulties?topic=<topic-id>` returns difficulty
  availability for the selected topic.
- `POST /api/runs` accepts `topic` in the JSON body.
- `GET /api/runs/{runId}/result` exposes `topic` in the response.

### Invalid legacy requests

Legacy language-shaped requests are not supported in this stage:

- `GET /api/session/difficulties?language=php` must not be accepted as a valid
  topic request.
- `POST /api/runs` with `language` and without `topic` must fail.
- Responses must not include duplicate `language` fields for compatibility.

### Response shape

Topic catalog response:

```json
{
  "locale": "pt-BR",
  "fallbackLocale": "en-US",
  "topics": [
    {
      "id": "laravel",
      "label": "Laravel",
      "description": "Quiz sobre Laravel",
      "weight": 100,
      "createdAt": "2026-01-01T00:00:00-03:00",
      "difficulties": [
        {
          "id": 1,
          "optionCount": 3,
          "questionCount": 10,
          "hardcore": false
        }
      ]
    }
  ],
  "difficulties": [
    {
      "id": 1,
      "optionCount": 3,
      "questionCount": 10,
      "hardcore": false
    }
  ]
}
```

Session topic availability response:

```json
{
  "locale": "pt-BR",
  "fallbackLocale": "en-US",
  "topics": [
    {
      "id": "scrum",
      "label": "Scrum",
      "description": "Quiz sobre Scrum",
      "weight": 200,
      "questionCount": 10,
      "availableQuestionCount": 8,
      "available": true,
      "difficulties": [
        {
          "id": 1,
          "optionCount": 3,
          "questionCount": 10,
          "availableQuestionCount": 8,
          "available": true,
          "hardcore": false
        }
      ]
    }
  ]
}
```

Session difficulty availability response:

```json
{
  "locale": "pt-BR",
  "topic": "scrum",
  "difficulties": [
    {
      "id": 1,
      "optionCount": 3,
      "questionCount": 10,
      "availableQuestionCount": 8,
      "available": true,
      "hardcore": false
    }
  ]
}
```

Create run request:

```json
{
  "topic": "clean-code",
  "difficulty": 1,
  "locale": "pt-BR"
}
```

Run result response:

```json
{
  "runId": "run_example",
  "locale": "pt-BR",
  "topic": "clean-code",
  "difficulty": 1,
  "finishReason": "max_questions_reached",
  "stats": {
    "answered": 3,
    "correct": 2,
    "wrong": 1,
    "accuracyPercent": 66
  },
  "answers": []
}
```

## Data and Contracts

Inputs:

- `topic`: stable topic ID in topic-based requests.
- `difficulty`: numeric difficulty value.
- `locale`: BCP 47 content locale.
- `X-QuickQuiz-Locale`: content locale header.
- `Accept-Language`: fallback locale negotiation header.

Outputs:

- Topic catalog with `topics`.
- Session topic availability with `topics`.
- Difficulty availability with `topic`.
- Run results with `topic`.

API, schema, event, or CLI changes:

- Replace language-based endpoints with topic-based endpoints.
- Replace language-based request fields with topic-based request fields.
- Replace language-based response fields with topic-based response fields.
- Update OpenAPI to document `topic` as canonical.

Persistence changes:

- No database changes.
- No local folder migration.
- Existing local paths remain physically unchanged but must be documented as
  topic paths:

```text
backend/.local/<locale>/<topic>/<difficulty>/<question-id>.json
```

Machine-readable contract:

- Update `docs/openapi.yaml`.

## Acceptance Examples

### Scenario: list topic catalog

Given the central index publishes active topic `laravel`

And the resolved locale is `pt-BR`

When the client calls `GET /api/catalog`

Then the response contains topic `laravel` under `topics`

And the response does not contain `languages`

And the response does not require a category field.

### Scenario: request topic availability

Given session `s1` has not exhausted topic `scrum`

When the client calls `GET /api/session/topics`

Then the response contains availability for topic `scrum` under `topics`.

### Scenario: request difficulties by topic

Given session `s1` has not exhausted topic `scrum`

When the client calls `GET /api/session/difficulties?topic=scrum`

Then the response contains the remaining difficulties for topic `scrum`

And the response contains `topic: "scrum"`.

### Scenario: reject legacy difficulty query

Given session `s1` has not exhausted topic `scrum`

When the client calls `GET /api/session/difficulties?language=scrum`

Then the request is not accepted as a valid topic request.

### Scenario: create run with topic

Given topic `clean-code` has available easy questions

When the client calls `POST /api/runs` with `topic` set to `clean-code`

Then the backend creates a run for topic `clean-code`.

### Scenario: reject legacy create run body

Given topic `clean-code` has available easy questions

When the client calls `POST /api/runs` with `language` set to `clean-code` and
without `topic`

Then the API rejects the request.

### Scenario: session exhaustion remains topic-based

Given session `s1` has already used question `clean-code-1-001` for topic
`clean-code` and difficulty `1`

When the same session starts another run for topic `clean-code` and difficulty
`1`

Then that canonical question is not selected again.

And the same question ID may still exist as a localized translation without
resetting availability.

## Quality Attributes

Security:

- No authentication or authorization changes.
- Topic IDs are machine-readable IDs and must not be treated as user-provided
  file paths in HTTP handlers.

Privacy:

- No personal data changes.

Accessibility:

- Not applicable to backend API behavior.

Performance:

- The topic refactor must not require duplicate question loading.
- Topic lookup should continue using the same in-memory data shape and
  complexity as the previous language lookup.

Reliability:

- The backend must pass its test suite after the contract change.
- Temporary frontend and manager breakage is acceptable in this controlled
  development phase.
- Legacy language requests must fail predictably instead of partially working.

Observability:

- Logs and diagnostic messages should use `topic`.
- Existing `language` log fields should be renamed where they refer to the quiz
  package selection.

## Rollout and Operations

Migration:

- No question file migration.
- Backend API migrates directly from language contracts to topic contracts.
- Frontend and manager clients will be fixed in later work after backend is
  working and tested.

Feature flag or configuration:

- None.

Rollback:

- Standard local commit rollback.
- Because compatibility is intentionally not preserved, rollback is the recovery
  path if the backend contract change needs to be undone.

Monitoring:

- Track successful calls to topic endpoints.
- Track invalid requests still using language contracts while clients are being
  migrated.

## Verification

Planned checks:

- Backend unit tests for topic request parsing.
- Backend unit tests proving topic IDs select the expected questions.
- Backend tests for session exhaustion using topic IDs.
- HTTP handler tests for topic endpoints.
- OpenAPI contract review.
- `go test ./...`.

Evidence to record:

- Test command output.
- OpenAPI diff or review note.
- Timeline entry.

## Open Questions

- None for this backend contract.
