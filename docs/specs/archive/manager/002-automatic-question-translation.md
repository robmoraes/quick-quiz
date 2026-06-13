# Feature: Automatic question localization in the manager

## Intent

Problem: QuickQuiz Dev requires every supported locale to replicate the same
canonical question package paths. Creating a new question manually in each
locale is repetitive and increases the risk of missing translations, divergent
IDs, and invalid localized packs.

Users or stakeholders: content administrators who create quiz questions in the
manager.

Desired outcome: administrators can draft a new question in any human language.
When the question is submitted, the manager uses AI to detect the source
language and creates localized question files for every supported locale,
preserving the canonical quiz pack contract.

Non-goals:

- replacing JSON quiz packs as the source of truth;
- translating existing questions in bulk;
- changing the Go API quiz pack loading behavior;
- allowing non-canonical locales to define exclusive question packages;
- generating explanations, hints, tags, or extra educational content;
- implementing a public localization API;
- supporting human review workflows beyond the manager save operation.

## Scope

In scope:

- accept new question drafts written in any human language;
- use the configured OpenAI integration to detect the source language and
  localize the submitted question to every supported locale;
- create one question JSON file per supported locale using the same language,
  difficulty, and question ID;
- include the fallback locale in the generated output, even when the source
  draft was not written in the fallback locale language;
- preserve the existing quiz pack body contract: `prompt`, `correctOptions`,
  and `wrongOptions`;
- keep language keys, difficulty IDs, question IDs, option counts, and JSON
  structure unchanged across locales;
- show validation and localization failures clearly to the administrator before
  any partial content is published.

Out of scope:

- database persistence for quiz content;
- S3 upload or synchronization;
- automatic localization for edits to existing questions;
- automatic localization when a locale is added later;
- translation memory, glossary management, or custom terminology UI;
- asynchronous background jobs;
- audit trail for generated localized content.

Assumptions:

- `FALLBACK_LOCALE` is still the canonical package locale and defaults to
  `en-US`;
- `SUPPORTED_LOCALES` contains the fallback locale and every target locale;
- new question drafts may be written in any human language the configured AI
  model can detect and translate;
- question IDs continue to follow the established pattern
  `<language>-<difficulty>-<increment>`;
- JSON remains the canonical storage format for quiz packs indefinitely;
- OpenAI credentials and model configuration may be provided through manager
  environment variables;
- localized output can be stored immediately if it passes the same structural
  validations as manually edited questions.

Dependencies:

- manager spec `docs/specs/manager/001-manager.md`;
- quiz pack contract `docs/quiz-pack-contract.md`;
- OpenAI API credentials configured for the manager;
- `FALLBACK_LOCALE`;
- `SUPPORTED_LOCALES`;
- local quiz content root configured for the manager.

## Behavior

1. The manager must allow creating a new question draft without requiring the
   administrator to choose the text language.
2. The new question form must not require a source locale field.
3. The manager must use AI to detect the language used in the submitted
   `prompt`, `correctOptions`, and `wrongOptions`.
4. The manager must continue allowing edits to existing questions in any
   supported locale.
5. When a new question draft is submitted, the manager must validate the
   source question before requesting localization.
6. The manager must generate or accept the canonical question ID before
   localization starts.
7. The manager must localize the source `prompt`, `correctOptions`, and
   `wrongOptions` to every supported locale, including `FALLBACK_LOCALE`.
8. The manager must not translate machine-readable values, including locale,
   language key, difficulty ID, question ID, field names, and file paths.
9. Each localized question must keep the same number of correct options and
   wrong options as the source question.
10. Each localized question must keep answer semantics aligned with the source:
   correct options remain correct and wrong options remain wrong.
11. The manager must save one file for every supported locale using the same
    relative path:
    `<language>/<difficulty>/<question-id>.json`.
12. The manager must reject the operation if source language detection fails.
13. The manager must reject the operation if any target locale localization is
    missing, malformed, empty, or fails quiz pack validation.
14. The manager must avoid leaving a partially created question set when a
    localization or validation failure occurs.
15. If the canonical question ID already exists in any supported locale, the
    manager must reject the create operation before calling OpenAI.
16. The manager must show the administrator which locale failed when automatic
    localization cannot be completed.
17. The manager must treat inactive languages as manageable content: new
    questions may be created for inactive languages, but inactive languages
    remain unpublished to the Go API.
18. The manager must not call OpenAI when the source question is invalid.
19. The manager must not call OpenAI when required OpenAI configuration is
    missing for automatic localization.
20. The manager must keep the existing JSON package contract unchanged for both
    fallback and localized files.

## Acceptance Examples

### Scenario: create a new question from a Portuguese draft

Given `FALLBACK_LOCALE` is `en-US`

And `SUPPORTED_LOCALES` is `en-US,pt-BR,es-ES`

And the administrator is creating a new `php` difficulty `1` question

When the administrator submits a valid source question written in Portuguese

Then the manager detects the source language

And creates an English file at
`backend/.local/en-US/php/1/<question-id>.json`

And creates a Portuguese file at
`backend/.local/pt-BR/php/1/<question-id>.json`

And creates a Spanish file at
`backend/.local/es-ES/php/1/<question-id>.json`

And all files contain only `prompt`, `correctOptions`, and `wrongOptions`.

### Scenario: create a new question from an English draft

Given `FALLBACK_LOCALE` is `en-US`

And `SUPPORTED_LOCALES` is `en-US,pt-BR`

When the administrator submits a valid source question written in English

Then the manager creates the fallback `en-US` file

And creates the localized `pt-BR` file using the same relative question path.

### Scenario: preserve canonical identity across locales

Given the manager generated question ID `php-1-042`

When the manager creates localized files for supported locales

Then every created file uses the path `php/1/php-1-042.json`

And no localized file contains locale, language, difficulty, or ID fields in
the JSON body.

### Scenario: fail without partial content

Given `SUPPORTED_LOCALES` is `en-US,pt-BR,es-ES`

And the `pt-BR` localization succeeds

And the `es-ES` localization response is malformed

When the administrator submits the new source question

Then the manager rejects the create operation

And reports that the `es-ES` localization failed

And does not leave only some locale files created for that question.

### Scenario: reject duplicate path before localization

Given `backend/.local/pt-BR/php/1/php-1-042.json` already exists

And the same question ID would be used for a new question

When the administrator submits the new question

Then the manager rejects the operation before calling OpenAI

And informs that the question path already exists in a supported locale.

### Scenario: allow inactive language content management

Given language `rust` exists with `active: false`

And `SUPPORTED_LOCALES` is `en-US,pt-BR`

When the administrator creates a new `rust` question from a valid draft

Then the manager creates localized files for all supported locales

And the language remains inactive and unpublished to the Go API.

## Data and Contracts

Inputs:

- source question draft written in any human language;
- generated or explicit canonical question ID;
- selected language key and numeric difficulty;
- `FALLBACK_LOCALE`;
- `SUPPORTED_LOCALES`;
- OpenAI configuration from the manager environment;
- local quiz content root.

Outputs:

- one localized question JSON file for every supported locale;
- administrator-facing validation or localization error messages.

API, schema, event, or CLI changes:

- no public Go API changes;
- no quiz JSON schema changes;
- manager UI flow changes for new question creation.

Persistence changes:

- no database persistence for quiz content;
- SQLite may continue to store only manager administrative state;
- quiz content persists as local JSON files.

Machine-readable contract:

- `docs/quiz-pack-contract.md` remains the source contract;
- question files remain:

```json
{
  "prompt": "What does PHP stand for?",
  "correctOptions": ["PHP: Hypertext Preprocessor"],
  "wrongOptions": ["Private Home Page", "Personal Hyperlink Protocol"]
}
```

## Quality Attributes

Security:

- OpenAI credentials must not be exposed in templates, logs, generated files, or
  client-side JavaScript.
- Only authenticated administrators may trigger automatic localization.

Privacy:

- Question content is sent to OpenAI for language detection and localization.
- The manager must not send administrator credentials, session values, local
  filesystem paths, or unrelated project data to OpenAI.

Accessibility:

- Localization and validation errors must be visible as normal form feedback and
  reachable by keyboard navigation.

Performance:

- New question submission may take longer because it waits for language
  detection and localization.
- The manager should keep timeout behavior explicit and show a clear failure if
  localization cannot complete.

Reliability:

- The create operation must be all-or-nothing across supported locales.
- OpenAI failures, malformed responses, language detection failures, and timeout
  failures must not create partial quiz packs.

Observability:

- The manager should log language detection and localization failures with
  locale, language, difficulty, and question ID.
- Logs must not include API keys or full administrator session data.

## Rollout and Operations

Migration:

- none for existing quiz files.

Feature flag or configuration:

- Automatic localization requires configured OpenAI API credentials and model
  settings.
- If OpenAI configuration is missing, new question creation must fail with an
  explicit administrative message instead of silently creating only some files.

Rollback:

- standard deploy rollback.
- Existing generated JSON files remain valid quiz packs and do not require a
  rollback migration.

Monitoring:

- language detection and localization failure logs;
- manual verification that every supported locale receives the same relative
  question path.

## Verification

Planned checks:

- unit tests for new question creation without a source locale field;
- unit tests for language detection failure handling;
- unit tests for supported locale target resolution;
- unit tests for duplicate path rejection before OpenAI calls;
- unit tests for malformed localization response rejection;
- unit tests for all-or-nothing file creation;
- integration or functional test for successful creation across multiple
  locales;
- Twig lint and container lint for manager changes.

Evidence to record:

- test command output;
- manual note with generated files for a sample question;
- localization failure case showing no partial files.

## Open Questions

- Should automatic localization be enabled for every environment, or guarded by
  a manager-specific feature flag?
- Should the manager present localized drafts for review before saving, or is
  immediate all-locale save acceptable for this iteration?
- Should edits to a generated locale later offer a separate relocalize action,
  or remain manual by default?
