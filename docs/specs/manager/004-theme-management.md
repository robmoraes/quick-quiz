# Feature: Manager theme management

## Intent

Problem:

The manager currently assumes one product content space. QuickQuiz needs one
manager capable of maintaining content for many themes, while each player
frontend can remain specialized for one theme and audience.

Users or stakeholders:

- Content administrators who manage quiz packages.
- Manager maintainers.
- Backend API maintainers.
- Future frontend maintainers for Dev, Math, History, English, Finance, and
  other themes.

Desired outcome:

Add theme selection and theme management to the manager. After login, an
administrator chooses a theme before managing catalog topics, localized content,
questions, AI translation, or AI recommendations. All following manager actions
operate inside the selected theme.

Non-goals:

- Do not create player frontend themes.
- Do not make theme a security boundary.
- Do not migrate question content to a database.
- Do not build advanced workflow, approvals, or publishing pipelines.
- Do not rename API difficulty to theme-specific frontend labels.

## Scope

In scope:

- Manage theme metadata.
- Require the administrator to select a theme before normal catalog and question
  workflows.
- Scope catalog, question, validation, AI translation, and AI recommendation
  workflows by selected theme.
- Adapt local content paths to `backend/.local/<theme>/...`.
- Keep locale management inside the selected theme.
- Preserve existing topic and question contracts inside each theme.
- Allow inactive themes to remain fully manageable.

Out of scope:

- Per-theme permissions.
- Theme-specific frontend preview.
- Theme-specific design settings, fonts, syslog, result metaphors, or labels.
- S3 publishing implementation.
- Database-backed question storage.
- Migration of the current local content into the first `dev` theme.

Assumptions:

- Theme IDs are stable machine-readable values such as `dev`, `math`,
  `history`, `english`, and `finance`.
- The initial migrated theme is `dev`, created by the API theme-foundation
  migration before this manager spec is implemented.
- The manager remains an administrative tool running in a restricted
  environment.
- The content source remains JSON files.
- The selected theme can be stored as a manager UI preference, but all writes
  must still be determined by explicit selected theme state.
- The backend API will use the same theme IDs.
- Theme metadata lives in `backend/.local/themes.json`.
- Theme descriptive metadata is canonical English (`en-US`) in this stage.
  Localized overrides are not planned for this delivery.

Dependencies:

- API theme backend foundation spec.
- Existing manager authentication.
- Existing manager catalog and question services.
- Existing local content root.
- Existing OpenAI-assisted translation and recommendation services.

## Behavior

1. The manager must treat theme as the top-level content scope.
2. After login, the manager must require the administrator to choose a theme
   before showing catalog, question, AI translation, or AI recommendation
   screens.
3. The theme selector should behave like a project selector: the current theme
   is a persistent context for subsequent manager screens.
4. The manager must provide a theme management screen.
5. The manager must allow creating and editing theme metadata.
6. Theme metadata must include at least `id`, `name`, `description`, `weight`,
   `createdAt`, and `active`.
7. Theme IDs must be machine-readable and must not be localized.
8. Theme names and descriptions must be stored in canonical English in
   `backend/.local/themes.json`; localized theme metadata overrides are out of
   scope for this delivery.
9. The manager must scope topic catalogs by selected theme.
10. The manager must scope question browsing by selected theme, locale, topic,
    and difficulty.
11. The manager must derive theme, locale, topic, difficulty, and question ID
    from the selected theme and file path, not from the question JSON body.
12. The manager must save question files under
    `backend/.local/<theme>/<locale>/<topic>/<difficulty>/<question-id>.json`.
13. The manager must save the selected theme central catalog under
    `backend/.local/<theme>/index.json`.
14. The manager must save selected theme localized catalog overrides under
    `backend/.local/<theme>/<locale>/index.json`.
15. The manager must prevent a non-fallback locale from adding or removing
    question packages that do not exist in the selected theme fallback locale.
16. The manager must validate locale parity per theme. A missing translation in
    `dev` must not affect `math`.
17. The manager must validate duplicate question IDs inside a selected theme,
    topic, difficulty, and locale scope.
18. The manager must not mix topics or questions from different themes in one
    list view.
19. AI translation and AI recommendation requests must include the selected
    theme in their context.
20. Theme-specific frontend concepts, such as Dev severity labels or syslog
    presentation, must not change manager storage contracts.
21. The manager must keep difficulty as the storage and API concept.
22. The manager must allow inactive themes to be created, edited, selected, and
    fully managed.
23. The manager must clearly indicate when the selected theme is inactive for
    player APIs.
24. The manager must not migrate existing non-themed content into `dev`; that
    migration belongs to the API theme-foundation implementation.

## Acceptance Examples

### Scenario: choose theme before managing content

Given an administrator is authenticated

And more than one theme exists

When the administrator opens the manager

Then the manager asks the administrator to choose a theme

And catalog and question navigation remain unavailable until a theme is chosen.

### Scenario: create new theme

Given an administrator is authenticated

When the administrator creates theme `math`

And provides canonical English name and description

Then the manager writes the theme metadata to `backend/.local/themes.json`

And the theme can be selected in the manager.

### Scenario: manage Dev topic inside selected theme

Given the administrator selected theme `dev`

When the administrator creates topic `php`

Then the manager writes central topic metadata to
`backend/.local/dev/index.json`

And localized topic metadata to `backend/.local/dev/<locale>/index.json`.

### Scenario: manage inactive theme

Given theme `math` exists with `active: false`

When the administrator selects theme `math`

Then the manager allows editing theme metadata, catalog topics, and questions

And the manager shows that `math` is inactive for player APIs.

### Scenario: create question in selected theme

Given the administrator selected theme `math`

And selected locale `en-US`

And selected topic `algebra`

When the administrator creates question `algebra-1-001.json`

Then the manager writes
`backend/.local/math/en-US/algebra/1/algebra-1-001.json`

And the question JSON body contains only `prompt`, `correctOptions`, and
`wrongOptions`.

### Scenario: no cross-theme locale parity failure

Given theme `dev` has topic `php`

And theme `math` has topic `algebra`

When the manager validates theme `dev`

Then missing `math` question files do not affect the `dev` validation result.

### Scenario: AI recommendation uses selected theme

Given the administrator selected theme `history`

And selected topic `ancient-rome`

When the administrator requests AI-recommended drafts

Then the recommendation context includes theme `history`

And does not include topics from other themes.

## Data and Contracts

Inputs:

- content root;
- selected theme;
- `FALLBACK_LOCALE`;
- `SUPPORTED_LOCALES`;
- `backend/.local/themes.json`;
- selected theme catalog files;
- selected theme question files.

Outputs:

- theme metadata;
- theme-scoped topic catalogs;
- theme-scoped question files;
- validation messages scoped to selected theme;
- AI prompts scoped to selected theme.

API, schema, event, or CLI changes:

- manager routes and forms gain selected theme context;
- manager forms gain theme create/edit actions.

Persistence changes:

- global theme metadata lives at:

```text
backend/.local/themes.json
```

- selected-theme content uses:

```text
backend/.local/<theme>/index.json
backend/.local/<theme>/<locale>/index.json
backend/.local/<theme>/<locale>/<topic>/<difficulty>/<question-id>.json
```

- manager SQLite may store the last selected theme per administrator as a UI
  preference.

Machine-readable contract:

- theme metadata schema:

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

- question file body remains unchanged.

## Quality Attributes

Security:

- theme selection does not grant authorization by itself;
- existing manager authentication remains required.

Privacy:

- not applicable beyond existing administrator account data.

Accessibility:

- the theme selector must be keyboard accessible and must expose the selected
  theme clearly.

Performance:

- listing content should load only the selected theme where practical.

Reliability:

- actions without a selected theme must fail closed with a clear manager error.

Observability:

- manager logs and validation messages should include selected theme.

## Rollout and Operations

Migration:

- none for current content in this manager spec;
- the API theme-foundation spec must migrate current content into `dev` first;
- update manager tests and fixtures to include theme.

Feature flag or configuration:

- none required;
- themes are discovered and managed through `backend/.local/themes.json`.

Rollback:

- before migration, standard deploy rollback;
- after migration, rollback requires restoring the previous folder layout or
  compatibility code.

Monitoring:

- manager validation failures by theme;
- missing selected-theme errors;
- content load errors by theme.

## Verification

Planned checks:

- manager service tests for `backend/.local/themes.json` create/edit behavior;
- manager service tests for theme-scoped paths;
- manager controller tests for required selected theme;
- manager controller tests for selecting inactive themes;
- manager validation tests for locale parity inside one theme;
- AI recommendation/localization tests including theme context;
- backend content-load tests after migration.

Evidence to record:

- test command output and migration notes.

## Decisions

- Global theme metadata lives in `backend/.local/themes.json`.
- Theme descriptive fields are canonical English in this delivery.
- Inactive themes are denied by player APIs but remain fully editable in the
  manager.
- Current content migration into `dev` is not part of this manager spec; it is
  performed by the API theme-foundation implementation first.
