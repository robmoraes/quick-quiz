# Plan: Manager Question Authoring Flows

## Overview

Implement the spec inside `apps/manager` by keeping the current Symfony
controller/template structure and moving file-system rules into
`QuizPackService`. The manager remains a server-rendered app with no database
and no public player API changes.

The implementation should split the current question authoring behavior into
four explicit flows:

- manual creation;
- manual editing;
- AI-assisted creation;
- AI-assisted editing.

The shared invariant is that every saved question id is represented across all
supported locales for the selected topic and difficulty.

## Affected Modules

- `apps/manager/src/Controller/QuestionController.php`
  - Replace the current mixed create/edit/AI actions with explicit route
    handlers for manual and AI-assisted flows.
  - Keep auth, selected-theme and CSRF checks in every mutating path.
- `apps/manager/src/Service/QuizPackService.php`
  - Own all quiz-pack file reads, writes, id generation, locale parity checks
    and answer-count validation.
  - Add methods for multi-locale create and update operations instead of
    duplicating file logic in the controller.
- `apps/manager/src/Service/QuestionRecommender.php`
  - Extend the contract to support answer-only recommendation for an existing
    prompt.
- `apps/manager/src/Service/OpenAiQuestionRecommender.php`
  - Implement the answer-only recommendation contract.
- `apps/manager/src/Service/QuestionLocalizer.php`
  - Keep the existing localization contract. The current `translateOptions =
    false` behavior already maps to prompt-only localization with answer-option
    copy semantics.
- `apps/manager/src/Service/OpenAiQuestionLocalizer.php`
  - Keep the current prompt-only localization behavior and cover it with the
    AI-assisted save tests that need literal answer copies.
- `apps/manager/templates/question/*.html.twig`
  - Split templates or partials by flow so the screens stay readable.
- `apps/manager/tests/Service/*`
  - Add service-level tests for the package invariants.
- `apps/manager/tests/Controller/*`, if practical in the current test setup
  - Add route/form behavior coverage for entry points and draft preservation.

## Route and Screen Design

### Question List

Keep `GET /questions` as the list and filtering screen.

The list template should show:

- a `Criar` button above the table for manual creation;
- a `Criar` button with an AI icon above the table for AI-assisted creation;
- a pencil icon per row with tooltip `Edição manual`;
- an AI icon per row with tooltip `Editar com AI`;
- a context menu or dropdown from the AI edit icon listing supported locales.

Use existing Bootstrap conventions in the manager templates. The AI icon can be
implemented with the icon system already available in the app; if none exists,
use a small textual/icon-compatible fallback while keeping the tooltip text.

### Manual Creation

Add an explicit manual create screen, for example:

- `GET /questions/manual/new`
- `POST /questions/manual/create`

The screen collects:

- source locale;
- topic;
- difficulty;
- optional question id;
- prompt;
- correct options;
- wrong options.

On save, the controller validates CSRF and delegates to a service method that:

1. assigns the next question id when blank;
2. validates topic and difficulty;
3. validates the question payload once;
4. verifies the id is available in all supported locales;
5. writes the same payload to every supported locale atomically via the
   existing multi-file write pattern.

### Manual Editing

Add an explicit manual edit screen, for example:

- `GET /questions/manual/{id}/edit`
- `POST /questions/manual/{id}/update`

The screen loads all supported locale variants for the selected topic,
difficulty and question id. It displays one tab per locale. The question id is
fixed and not editable.

On save, the controller delegates to a service method that:

1. normalizes each locale payload;
2. validates required fields for every locale;
3. validates the canonical locale payload using the existing difficulty rules;
4. requires every other locale to have the same number of correct and wrong
   options as the canonical locale;
5. writes all locale files together, with no partial update on validation
   failure.

The service must not compare or judge text quality between locales.

### AI-Assisted Creation

Keep or replace the existing AI creation screen with an explicit flow, for
example:

- `GET /questions/ai/new`
- `POST /questions/ai/recommend`
- `POST /questions/ai/suggest-answers`
- `POST /questions/ai/create`

The screen must require topic and difficulty before recommendation actions are
available. The question id remains optional and normally blank.

Recommendation actions:

- full package: call `QuestionRecommender::recommend(...)`;
- answer-only: call a new `QuestionRecommender::recommendAnswers(...)` or
  equivalent contract that receives the editor prompt and returns correct and
  wrong options only.

The recommendation actions return the editor to the same form with populated
draft fields and do not write files.

On save:

1. prepare the new question id and source payload;
2. call `QuestionLocalizer::localize($question, $supportedLocales,
   $translateOptions)`;
3. save the localized set as a new package across all supported locales.

When `translateOptions` is false, non-source locales must receive the answer
options visible in the form at save time, copied verbatim. The existing
localizer prompt-only mode is the preferred implementation path for this.

### AI-Assisted Editing

Add explicit AI edit routes, for example:

- `GET /questions/ai/{id}/edit?locale=pt-BR`
- `POST /questions/ai/{id}/recommend`
- `POST /questions/ai/{id}/suggest-answers`
- `POST /questions/ai/{id}/update`

The list screen's AI edit icon opens the locale context menu before navigating
to the edit form. The AI edit form shows only the chosen locale's prompt,
correct options and wrong options. The question id is fixed and not editable.

On save:

1. validate that the target question id already exists for the topic and
   difficulty;
2. normalize and validate the selected locale payload;
3. localize the selected payload across supported locales using the same
   `translateOptions` semantics as AI-assisted creation;
4. overwrite all supported locale files for that existing question id together.

This requires a service method distinct from new localized creation because
existing ids must be allowed and a missing package should be an error, not a new
create.

## Service Design

Add explicit service methods to express the package operations:

- `createReplicatedQuestionSet(string $sourceLocale, string $topic, int
  $difficulty, string $questionId, array $input): string`
  - manual creation;
  - same payload written to all supported locales.
- `readLocalizedQuestionSet(string $topic, int $difficulty, string
  $questionId): array`
  - manual editing form load;
  - returns payloads keyed by locale.
- `updateManualLocalizedQuestionSet(string $topic, int $difficulty, string
  $questionId, array $localizedInputs): void`
  - manual editing save;
  - validates required fields and canonical answer counts.
- `createAiLocalizedQuestionSet(string $topic, int $difficulty, string
  $questionId, array $sourceInput, QuestionLocalizer $localizer, bool
  $translateOptions): string`
  - optional wrapper if the controller should not orchestrate localizer and
    save steps directly.
- `updateAiLocalizedQuestionSet(string $topic, int $difficulty, string
  $questionId, array $localizedPayloads): void`
  - AI editing save after localization;
  - validates existing id and overwrites every locale atomically.

The exact method names may differ, but the implementation should preserve these
ownership boundaries:

- controllers handle request parsing, redirects and template models;
- `QuestionRecommender` handles AI recommendation;
- `QuestionLocalizer` handles AI localization;
- `QuizPackService` handles file-system invariants and validation.

## Data and Contract Impact

No stored JSON shape changes are planned.

Question files remain:

```json
{
  "prompt": "...",
  "correctOptions": ["..."],
  "wrongOptions": ["..."]
}
```

No OpenAPI changes are needed because manager routes are server-rendered and
the public player API is unchanged.

No migration is required. Existing question files remain valid as long as they
already satisfy locale parity.

## Security and Privacy

- Preserve `requireAuth()` and `requireSelectedTheme()` checks on all question
  routes.
- Preserve CSRF checks for all `POST` actions.
- Do not include secrets or unrelated pack content in AI calls.
- Keep AI recommendation context limited to selected topic, difficulty and
  prompt context used for duplicate avoidance.

## Accessibility

- Use real buttons or links for authoring actions.
- Add tooltips with the exact copy from the spec for row edit icons.
- Ensure the manual edit tabs are keyboard reachable.
- Keep labels associated with all form fields.
- Do not rely only on icon shape to communicate manual vs AI behavior.

## Reliability and Atomicity

All multi-locale writes should continue using a validate-first, write-after
pattern. No file should be written until every locale payload for the operation
has passed validation.

If a recommendation, localization or save fails, the controller should re-render
the same form with:

- the error message;
- the editor's current draft values;
- the selected mode, topic, difficulty, locale and question id context.

## Testing Strategy

Service tests should cover:

- manual creation replicates one source payload to every supported locale;
- manual creation with blank id assigns the next id once and uses it for all
  locales;
- manual creation rejects duplicate ids before any write;
- manual editing reads all locale variants;
- manual editing rejects missing required fields in any locale;
- manual editing rejects non-canonical answer-count drift;
- manual editing accepts arbitrary non-empty translation wording when counts
  match;
- manual editing writes all locales and avoids partial writes on failure;
- AI editing update allows existing ids and rejects missing ids;
- AI-assisted save with `translateOptions = false` copies visible answer
  options verbatim to non-source locales.

Controller or functional tests should cover, where practical:

- question list has the two create buttons and two edit icons per row;
- AI edit icon exposes locale choices;
- manual create does not call AI;
- recommendation failures preserve draft values;
- AI-assisted edit opens with only the selected locale and a fixed question id.

Existing tests to keep running:

```sh
cd apps/manager
php bin/phpunit
```

## Rollout and Compatibility

Roll out as a normal manager change on the current trunk-based branch.

Compatibility expectations:

- existing question files remain readable;
- existing themes, topics and difficulties remain unchanged;
- existing AI configuration remains unchanged;
- public player API remains unchanged.

Rollback is a code revert. Because the JSON shape does not change, saved data
remains compatible with the previous manager as long as locale parity is
preserved.

## Alternatives Considered

Single unified form:
Rejected because the spec explicitly chooses separate manual and AI-assisted
flows to reduce screen complexity.

Controller-owned file writes:
Rejected because package parity, canonical count validation and atomic
multi-locale writes are domain rules for quiz packs and belong in
`QuizPackService`.

Manual creation creates only the chosen locale:
Rejected because the spec requires one creation to replicate across all
supported locales.

AI edit creates a new question id:
Rejected because editing with AI must update all locale variants for the same
existing id.

## ADR Impact

No ADR is required. The plan does not introduce a consequential architecture
boundary, datastore, runtime, dependency or cost model change.
