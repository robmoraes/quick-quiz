# Feature: Manager Question Authoring Flows

## Intent

Problem:
The manager needs clearer question authoring flows. A single screen that mixes
manual creation, manual editing, AI creation and AI editing would be too complex
for editors and hard to validate. The current entry points also make AI feel
like part of the default creation path instead of an explicit assisted mode.

Users or stakeholders:
Quiz pack editors who create, review, localize and publish question packages in
the Symfony manager.

Desired outcome:
Editors choose between two explicit authoring modes:

- fully manual;
- AI-assisted.

Manual flows prioritize direct human control and explicit locale review.
AI-assisted flows prioritize fast generation or regeneration while preserving
the existing autonomous localization behavior.

Non-goals:

- Redesign the entire manager navigation.
- Change the public player API contract.
- Change the quiz pack JSON shape.
- Add authentication, authorization or role changes.
- Change the OpenAI provider or model integration strategy.
- Add text quality validation for translations beyond required fields and
  answer-count parity.

Source issue:
https://github.com/robmoraes/quick-quiz/issues/7

## Scope

In scope:

- Split question authoring into fully manual and AI-assisted flows.
- Replace ambiguous creation entry points with clear mode-specific actions.
- Support manual creation from one selected locale and replicate it to every
  supported locale.
- Support manual editing in a multi-locale tabbed editor.
- Support AI-assisted creation after topic and difficulty are selected.
- Support AI-assisted editing after the editor chooses the source locale to
  edit.
- Preserve autonomous AI localization across supported locales.
- Preserve the existing answer-translation checkbox behavior for AI-assisted
  save.
- Preserve locale package parity for all question packages.

Out of scope:

- Changing theme, topic or difficulty management workflows outside the
  question authoring entry points.
- Changing player-facing run, question or answer APIs.
- Changing the stored question payload fields: `prompt`, `correctOptions` and
  `wrongOptions`.
- Adding a database or changing storage away from local quiz pack files.
- Automatically validating translation quality, semantic equivalence or wording
  across locales in the manual editor.

Assumptions:

- `FALLBACK_LOCALE` remains the canonical locale for structural validation.
- Supported locales must contain the same question package ids for each topic
  and difficulty.
- A question is created once, with one question id, then represented across all
  supported locales.
- In manual creation, the editor-selected locale is the source text that is
  copied to every supported locale on save.
- In AI-assisted creation or editing, AI identifies the source language and
  creates or updates the other locale packages autonomously.
- AI recommendations remain optional and can fail without preventing manual
  editing.
- Existing validation for prompt, correct options and wrong options remains
  authoritative.

Dependencies:

- The manager can read the selected theme, supported locales, topics,
  difficulties and existing question package files.
- The existing question recommender can produce a full draft question package.
- The existing localizer can localize a question package across supported
  locales.

## Behavior

### Authoring Mode Entry Points

1. The question list must show two create buttons above the list: `Criar` and
   `Criar` with an AI icon.
2. Creating a question must make the authoring mode explicit before the editor
   enters the form.
3. Activating `Criar` must open fully manual creation.
4. Activating `Criar` with the AI icon must open AI-assisted creation.
5. Each question row must show two edit icons: a pencil icon with tooltip
   `Edição manual`, and an AI icon with tooltip `Editar com AI`.
6. Activating the pencil icon must open fully manual editing.
7. Activating the AI edit icon must open a context menu for choosing the base
   locale before opening AI-assisted editing.
8. Simplifying the entry points must not remove the ability to edit an existing
   question.

### Fully Manual Creation

1. Manual creation must allow the editor to choose the locale used to author the
   initial question text.
2. Manual creation must collect topic, difficulty, optional question id,
   prompt, correct options and wrong options.
3. If the question id is blank, the manager must assign the next valid id using
   the existing id generation rule.
4. Opening the manual creation form must not call AI.
5. Saving a manually created question must create one question id only.
6. Saving a manually created question must replicate the authored prompt,
   correct options and wrong options to every supported locale.
7. Manual creation must not create locale-exclusive question packages.
8. Manual creation must validate required fields before writing any question
   files.

### Fully Manual Editing

1. Manual editing must be more complete than manual creation because it edits
   the entire localized question package set.
2. Manual editing must show one tab for each supported locale.
3. Each locale tab must expose required fields for prompt, correct options and
   wrong options.
4. The editor must fill all required fields for all supported locales before
   saving.
5. The question id must not change during manual editing.
6. Manual editing must validate that the canonical locale's answer structure is
   respected by every other locale.
7. If the canonical locale has one correct option and nine wrong options, every
   other locale must also have one correct option and nine wrong options.
8. Manual editing must not validate translation text quality, semantic
   equivalence or wording across locales.
9. Manual editing must leave text responsibility entirely with the editor.
10. Manual editing must save all locale variants for the question id together,
    avoiding partial locale updates.

### AI-Assisted Creation

1. AI-assisted creation must require the editor to choose topic and difficulty
   before AI recommendation actions are available.
2. The question id may remain blank during AI-assisted creation and is normally
   assigned automatically when saved.
3. After topic and difficulty are selected, the form must offer two AI actions:
   generate question and answers, or suggest answers for an editor-provided
   question.
4. Generate question and answers must ask AI for prompt, correct options and
   wrong options.
5. Suggest answers must require or preserve the editor-provided prompt and ask
   AI only for correct and wrong options.
6. Suggest answers must not overwrite, clear or rewrite the current prompt.
7. AI recommendations must populate the form for editor review before saving.
8. Before saving, the editor must be able to choose whether answer options
   should be translated by AI.
9. If the editor indicates that answer options do not need AI translation, the
   save flow must avoid unnecessary answer-option translation work to reduce
   token usage.
10. If answer translation is disabled, non-source locales must copy the answer
    options that are visible in the editing form at save time, verbatim.
11. Saving an AI-assisted creation must continue to identify the source language
    and create all supported locale variants autonomously.
12. AI-assisted creation must not write question files until the editor saves
    the populated form.

### AI-Assisted Editing

1. From an existing question, the editor must be able to choose edit with AI.
2. Before opening the AI-assisted edit form, the editor must choose which locale
   will be edited as the source content.
3. AI-assisted editing must open a simplified form containing only the selected
   locale's prompt, correct options and wrong options.
4. The question id must be visible or preserved but not editable in AI-assisted
   editing.
5. AI-assisted editing must offer the same two AI actions as AI-assisted
   creation: generate question and answers, or suggest answers for the current
   prompt.
6. AI-assisted editing must allow the editor to change the full question
   content in the selected locale before saving.
7. Saving AI-assisted edits must follow the same autonomous localization rules
   as AI-assisted creation.
8. Saving AI-assisted edits must update all supported locale variants for the
   same question id.
9. AI-assisted editing must not create a new question id.

### Shared Validation and Error Handling

1. All save paths must preserve the quiz pack JSON shape.
2. All save paths must preserve package parity across supported locales.
3. All forms must preserve editor-entered draft values when validation or AI
   recommendation fails.
4. AI recommendation failures must show a user-visible error and must not write
   question files.
5. Existing authentication, selected-theme checks and CSRF validation must
   remain required for form actions.

## Acceptance Examples

Scenario: manually create a question in one locale and replicate it
Given supported locales are `en-US` and `pt-BR`
And the editor opens fully manual creation
When the editor chooses source locale `pt-BR`
And enters a prompt, one correct option and nine wrong options
And saves the form
Then the manager creates one question id
And creates the question package for `pt-BR`
And creates the same prompt, correct options and wrong options for `en-US`
And no AI request is sent.

Scenario: manual creation with an automatic question id
Given the editor opens fully manual creation for topic `math` and difficulty
`1`
And the question id field is blank
When the editor saves a valid question
Then the manager assigns the next valid question id for topic `math` and
difficulty `1`
And uses that id for every supported locale.

Scenario: manual edit requires every locale tab
Given supported locales are `en-US` and `pt-BR`
And question `math-1-001` exists in both locales
When the editor opens manual edit for `math-1-001`
Then the manager shows an `en-US` tab
And the manager shows a `pt-BR` tab
When the editor clears the prompt in the `pt-BR` tab
And tries to save
Then the manager rejects the save
And shows that all locales must have required fields completed
And preserves the editor's draft values.

Scenario: manual edit enforces canonical answer counts
Given `en-US` is the canonical locale
And question `math-1-001` has one correct option and nine wrong options in
`en-US`
When the editor opens manual edit
And enters one correct option and eight wrong options in `pt-BR`
And tries to save
Then the manager rejects the save
And explains that `pt-BR` must match the canonical answer counts
And no locale files are partially updated.

Scenario: manual edit does not judge translation wording
Given question `math-1-001` has valid required fields in every locale
And every non-canonical locale matches the canonical correct and wrong option
counts
When the editor changes the `pt-BR` text to any non-empty wording
And saves
Then the manager accepts the save
And does not block the change based on translation quality or semantic
equivalence.

Scenario: AI-assisted creation requires topic and difficulty before generation
Given the editor opens AI-assisted creation
When topic or difficulty is not selected
Then AI generation actions are not available
When the editor selects topic `math` and difficulty `1`
Then the manager offers generate question and answers
And the manager offers suggest answers for an editor-provided question.

Scenario: AI generates a full package for review before save
Given the editor selected topic `math` and difficulty `1`
When the editor requests AI to generate question and answers
Then the manager asks AI for prompt, correct options and wrong options
And returns those values to the form
And no question files are written until the editor saves.

Scenario: AI suggests answers without changing the prompt
Given the editor selected topic `math` and difficulty `1`
And the prompt field contains `What is 2 + 2?`
When the editor requests AI answer suggestions
Then the prompt field still contains `What is 2 + 2?`
And the correct and wrong option fields contain AI suggestions.

Scenario: AI-assisted save can avoid answer translation
Given an AI-assisted creation form is populated
When the editor marks that answer options do not need AI translation
And saves
Then the manager still creates all supported locale variants
And avoids unnecessary AI translation work for answer options
And copies the answer options visible in the form verbatim to non-source
locales.

Scenario: AI-assisted edit starts from a chosen locale
Given question `math-1-001` exists in `en-US` and `pt-BR`
When the editor activates the AI edit icon with tooltip `Editar com AI`
Then the manager opens a context menu listing the available base locales
When the editor chooses `pt-BR`
Then the AI-assisted edit form opens with only the `pt-BR` content
And the question id `math-1-001` is not editable.

Scenario: AI-assisted edit updates all locales for the same id
Given the editor opened AI-assisted edit for question `math-1-001` in locale
`pt-BR`
When the editor changes the prompt and answer options
And saves
Then the manager identifies the edited source language
And updates all supported locale variants for `math-1-001`
And does not create a new question id.

Scenario: AI failure preserves draft work
Given the editor entered a prompt and answer options in an AI-assisted form
When the editor requests an AI recommendation
And the recommendation service fails
Then the manager shows the failure message on the form
And the prompt, correct options and wrong options still contain the editor's
draft values
And no question files are written.

## Data and Contracts

Inputs:

- Authoring mode: fully manual or AI-assisted.
- Operation: create or edit.
- Selected locale for manual creation.
- Selected locale for AI-assisted editing.
- Selected topic and difficulty.
- Optional question id for creation.
- Existing fixed question id for editing.
- Form fields: `prompt`, `correctOptions` and `wrongOptions`.
- AI action selected by the editor: generate question and answers, or suggest
  answers only.
- Answer-translation choice for AI-assisted save.

Outputs:

- Updated manager HTML pages for question list, mode selection and authoring
  forms.
- Question package files under the selected theme path.
- User-visible validation or AI error messages when save or recommendation
  fails.

API/schema/event changes:

- No public player API changes.
- No OpenAPI changes.
- No event contract changes.

Persistence changes:

- No quiz pack JSON shape changes.
- Question package identity remains defined by locale, topic, difficulty and
  question id path.
- Saved question files still contain only `prompt`, `correctOptions` and
  `wrongOptions`.
- Creation produces one question id represented in every supported locale.
- Editing updates the same question id across the affected locale package set.

## Quality Attributes

Security:
Existing authentication, selected-theme checks and CSRF validation must remain
in force for all form actions.

Privacy:
AI recommendation and localization requests must not send secrets or unrelated
local quiz pack content. Recommendation context remains limited to the selected
authoring context and existing prompt context needed for duplicate avoidance.

Accessibility:
Manual editing locale tabs must remain keyboard reachable and controls must
have labels. AI recommendation actions must be explicit controls with clear
text, not hidden side effects.

Performance:
Opening manual creation, manual editing or AI-assisted editing must not wait for
AI. AI latency must occur only after the editor explicitly requests a
recommendation or saves an AI-assisted form that requires localization.

Reliability:
Recommendation failures must not lose editor-entered draft content. Multi-locale
saves must avoid partial locale package updates.

Observability:
No new metrics or logs are required by this spec. Existing exception surfaces
must still provide user-visible errors in the manager.

## Rollout and Operations

Migration:
No data migration is required.

Feature flag or configuration:
No feature flag is required for the MVP manager. Existing OpenAI configuration
continues to determine whether AI-assisted actions can succeed.

Rollback:
Rollback is a code revert. Stored question package files remain compatible
because the JSON shape does not change.

Monitoring:
No production monitoring change is required.

## Open Questions

- None.
