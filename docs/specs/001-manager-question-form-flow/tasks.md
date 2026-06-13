# Tasks: Manager Question Authoring Flows

## Implementation Tasks

1. Add service coverage for manual multi-locale creation.
   - Implement tests proving one source payload is written to every supported
     locale, blank ids are generated once and reused across locales, duplicate
     ids fail before writes, and invalid input creates no partial files.
   - Validation: `cd apps/manager && php bin/phpunit --filter QuizPackServiceTest`.

2. Implement manual multi-locale creation in `QuizPackService`.
   - Add a service method that accepts source locale, topic, difficulty,
     optional question id and one question payload, then validates and writes
     the same payload to every supported locale atomically.
   - Preserve the current quiz pack JSON shape and existing id generation
     rules.
   - Validation: service tests from task 1 pass.

3. Add service coverage for loading and saving manual multi-locale edits.
   - Cover loading all locale payloads for an existing question id.
   - Cover rejection when any locale is missing required fields.
   - Cover canonical answer-count enforcement across locales.
   - Cover acceptance of arbitrary non-empty translation wording when answer
     counts match.
   - Cover no partial writes on validation failure.
   - Validation: `cd apps/manager && php bin/phpunit --filter QuizPackServiceTest`.

4. Implement manual multi-locale edit operations in `QuizPackService`.
   - Add read and update methods for localized question sets keyed by locale.
   - Validate every locale payload and enforce canonical correct/wrong option
     counts.
   - Keep the question id fixed and update all locale variants together.
   - Validation: service tests from task 3 pass.

5. Extend the AI recommendation contract for answer-only suggestions.
   - Add a recommender method for answer suggestions that receives locale,
     topic metadata, difficulty metadata, current prompt and existing prompt
     context, then returns only correct and wrong options.
   - Implement the method in `OpenAiQuestionRecommender` without changing the
     existing full-package recommendation behavior.
   - Validation: add/update `OpenAiQuestionRecommenderTest` coverage for the
     request contract and parsed response.

6. Add service coverage for AI-assisted update of existing localized question
   sets.
   - Cover updating an existing question id across all supported locales.
   - Cover rejecting missing existing ids.
   - Cover `translateOptions = false` semantics where answer options visible in
     the form are copied verbatim to non-source locales.
   - Validation: `cd apps/manager && php bin/phpunit --filter QuizPackServiceTest`
     and `php bin/phpunit --filter OpenAiQuestionLocalizerTest`.

7. Implement AI-assisted create/update persistence flow.
   - Reuse the current localizer for autonomous locale creation/update.
   - Add a distinct service path for updating existing ids so AI-assisted edit
     cannot accidentally create a new question id.
   - Preserve prompt-only localization behavior when answer translation is
     disabled.
   - Validation: service and localizer tests from task 6 pass.

8. Refactor question routes into explicit authoring flows.
   - Keep `GET /questions` as the list/filter screen.
   - Add manual creation and manual update routes.
   - Add AI-assisted creation, full recommendation, answer-only suggestion and
     save routes.
   - Add AI-assisted edit routes that require a selected base locale and keep
     the question id immutable.
   - Preserve auth, selected-theme and CSRF checks.
   - Validation: `cd apps/manager && php bin/phpunit`, plus manual smoke check
     of each route in local manager.

9. Update the question list UI entry points.
   - Above the list, show `Criar` and `Criar` with an AI icon.
   - Per row, show a pencil icon with tooltip `Edição manual`.
   - Per row, show an AI icon with tooltip `Editar com AI`.
   - Make the AI edit icon open a locale context menu before navigation.
   - Validation: functional/controller test if practical; otherwise local
     browser smoke check with an existing question.

10. Build the manual creation form.
    - Include source locale, topic, difficulty, optional question id, prompt,
      correct options and wrong options.
    - Saving delegates to the manual replicated creation service method.
    - Failed validation re-renders the form with draft values preserved.
    - Validation: service tests plus local browser smoke check creating a
      question and confirming files exist for every supported locale.

11. Build the manual editing form.
    - Load all supported locale variants and show one tab per locale.
    - Keep the question id fixed.
    - Require all locale fields before save.
    - Show canonical answer-count validation errors without losing drafts.
    - Validation: service tests plus local browser smoke check editing all
      locale tabs and intentionally triggering answer-count drift.

12. Build the AI-assisted creation form.
    - Require topic and difficulty before enabling AI recommendation actions.
    - Support full question generation and answer-only suggestion.
    - Keep recommendation results as editable draft values before save.
    - Preserve the answer-translation checkbox behavior.
    - Validation: recommender tests plus local smoke check with a mocked or
      configured AI provider where available.

13. Build the AI-assisted editing form.
    - Open only after a base locale is selected from the question list menu.
    - Show only the selected locale's question content.
    - Keep the question id visible or preserved but not editable.
    - Support full generation and answer-only suggestion before save.
    - Save by updating every supported locale for the same existing question
      id.
    - Validation: service tests plus local browser smoke check editing from a
      non-canonical base locale.

14. Preserve draft values and errors across all failure paths.
    - Re-render the relevant form with selected mode, topic, difficulty, locale,
      question id and current field values after validation, recommendation or
      localization failures.
    - Validation: controller/functional tests if practical; otherwise local
      smoke checks for validation failure and AI failure.

15. Run the manager validation suite and update follow-up notes.
    - Run `cd apps/manager && php bin/phpunit`.
    - If controller tests are not practical in this codebase, record the manual
      smoke checks in the PR.
    - Confirm no OpenAPI, player API or quiz pack JSON shape changes were made.

## Completion Criteria

- Every acceptance example in `spec.md` is covered by automated tests where
  practical or by documented manual smoke evidence.
- `php bin/phpunit` passes in `apps/manager`.
- The PR references `docs/specs/001-manager-question-form-flow/spec.md`,
  `plan.md` and this task list.
