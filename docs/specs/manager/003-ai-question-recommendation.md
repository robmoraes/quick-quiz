# Feature: AI question recommendation in the manager

## Intent

Problem: creating quiz questions manually from scratch is slow and makes it
easy to repeat subjects already covered by a language and difficulty package.
The manager already supports human-reviewed AI localization, but it does not
yet help administrators draft a new original question.

Users or stakeholders: content administrators who curate quiz packs.

Desired outcome: the manager can ask AI to recommend one new question draft for
the selected language and difficulty, using existing prompts as context to
avoid obvious repetition. The generated draft is shown to the administrator in
the existing `New question with AI` flow for human review and confirmation
before any JSON file is saved.

Non-goals:

- saving an AI-recommended question automatically;
- bypassing human review;
- replacing the existing manual `New question` flow;
- changing the quiz pack JSON contract;
- changing Go API behavior;
- generating many questions in batch;
- sending answer options from existing questions to AI;
- using all locales as recommendation context.

## Scope

In scope:

- add an AI recommendation action from the Questions screen;
- use the currently selected `locale`, `language`, and `difficulty` as
  recommendation inputs;
- send language metadata as context, including key, display name, and
  description;
- send AI only existing question prompts for the selected language, selected
  difficulty, and selected locale;
- limit the number of existing prompts included in the recommendation context;
- ask AI to generate one draft question with `prompt`, `correctOptions`, and
  `wrongOptions`;
- ask AI to include exactly 9 `wrongOptions` in the recommended draft;
- use the selected locale to steer the draft language for human review;
- prefill the existing `New question with AI` screen with the recommended
  draft;
- keep final save behavior in the existing AI localization flow.

Out of scope:

- automatic persistence of the recommendation;
- background jobs;
- recommendation history;
- semantic embeddings or vector search;
- answer-option-aware duplicate detection using existing question answers;
- bulk recommendation;
- recommendations that combine multiple languages or difficulties.

Assumptions:

- the Questions screen already has selected `locale`, `language`, and
  `difficulty` filters;
- the selected locale is supported and can be used as the desired draft
  language;
- the recommendation button uses the values currently filled in the Questions
  filter form as the context source;
- existing question prompts are enough context for a first duplicate-avoidance
  pass;
- the recommendation context can be truncated to 50 prompts without breaking the
  workflow;
- the administrator remains responsible for reviewing and editing the draft;
- OpenAI credentials and model configuration may be provided through manager
  environment variables.

Dependencies:

- manager spec `docs/specs/manager/001-manager.md`;
- AI localization spec
  `docs/specs/manager/002-automatic-question-translation.md`;
- quiz pack contract `docs/quiz-pack-contract.md`;
- OpenAI API credentials configured for the manager;
- selected Questions screen filters;
- local quiz content root.

## Behavior

1. The Questions screen must keep the existing `New question` and
   `New question with AI` actions.
2. The Questions screen must provide a separate action to request an AI question
   recommendation.
3. The recommendation action must use the selected `locale`, `language`, and
   `difficulty` from the Questions filters.
4. The manager must send the selected language key, display name, and
   description as recommendation context.
5. When selected-locale language metadata exists, the manager must use it for
   display name and description; otherwise it must fall back to central catalog
   metadata.
6. The manager must collect existing questions only from the selected locale,
   selected language, and selected difficulty.
7. The manager must send only existing question prompts as prior-question
   context; existing `correctOptions` and `wrongOptions` must not be sent.
8. The manager must limit the number of existing prompts sent to AI to 50 by
   default.
9. The prompt limit must be locally centralized so it can be changed without
   altering recommendation behavior elsewhere.
10. The manager must ask AI for exactly one new question draft.
11. The draft must include `prompt`, `correctOptions`, and `wrongOptions`.
12. The recommended draft must include exactly 9 `wrongOptions`.
13. The draft language must be guided by the selected locale.
14. The AI must not choose or alter `locale`, `language`, `difficulty`, or
    `questionId`.
15. The manager must validate the recommended draft before presenting it to the
    administrator.
16. The manager must reject an AI recommendation that is empty, malformed, has
    insufficient options, or repeats an existing prompt by exact string match.
17. When the recommendation is valid, the manager must open the existing
    `New question with AI` screen prefilled with the draft.
18. The administrator must be able to edit the draft before saving.
19. Saving the recommended draft must use the existing AI localization flow and
    all its validations.
20. If OpenAI configuration is missing, the recommendation action must fail with
    an explicit administrator-facing message.
21. The recommendation action must not create, edit, or delete any quiz JSON
    files.

## Acceptance Examples

### Scenario: recommend a Portuguese draft from selected context

Given the Questions screen is filtered by locale `pt-BR`

And language `php`

And difficulty `1`

And there are existing `pt-BR/php/1/*.json` question files

When the administrator requests an AI question recommendation

Then the manager uses the currently filled filter values as context

And sends AI the selected language `php`

And the display name and description for `php`

And selected difficulty `1`

And selected locale `pt-BR`

And up to 50 existing `pt-BR/php/1` prompts

And does not send existing correct or wrong options.

### Scenario: limit recommendation context to 50 prompts

Given the Questions screen is filtered by locale `pt-BR`

And language `php`

And difficulty `1`

And there are 80 existing `pt-BR/php/1/*.json` question files

When the administrator requests an AI question recommendation

Then the manager sends no more than 50 existing prompts to AI

And does not send any existing correct or wrong options.

### Scenario: open review screen with recommended draft

Given AI returns a valid question draft

When the recommendation succeeds

Then the manager opens `New question with AI`

And pre-fills `prompt`, `correctOptions`, and `wrongOptions`

And keeps the selected language and difficulty

And does not save any quiz JSON file yet.

### Scenario: reject exact duplicate prompt only

Given `pt-BR/php/1/php-1-001.json` has prompt `Qual tag inicia PHP?`

When AI recommends a draft with prompt `Qual tag inicia PHP?`

Then the manager rejects the recommendation

And tells the administrator that the recommendation duplicates an existing
prompt.

### Scenario: allow non-exact similar prompt

Given `pt-BR/php/1/php-1-001.json` has prompt `Qual tag inicia PHP?`

When AI recommends a draft with prompt `Qual comando imprime texto em PHP?`

Then the manager accepts the recommendation for human review

And does not reject it as a duplicate.

### Scenario: recommendation with no existing context

Given the selected language and difficulty have no existing questions in the
selected locale

When the administrator requests an AI question recommendation

Then the manager still asks AI for one draft

And sends an empty prior-prompt list.

### Scenario: recommendation does not bypass localization review

Given AI recommends a valid draft in `pt-BR`

When the administrator edits and submits the prefilled `New question with AI`
form

Then the existing localization flow creates files for every supported locale

And the recommendation action itself remains non-persistent.

## Data and Contracts

Inputs:

- selected locale from the Questions screen;
- selected language key;
- selected language display name and description;
- selected numeric difficulty;
- up to 50 existing question prompts for that locale, language, and difficulty;
- OpenAI configuration from the manager environment.

Outputs:

- one administrator-reviewed draft payload:
  `prompt`, `correctOptions`, and `wrongOptions`;
- administrator-facing recommendation errors;
- no quiz JSON file from recommendation alone.

API, schema, event, or CLI changes:

- no public Go API changes;
- no quiz JSON schema changes;
- manager UI flow changes for recommendation and prefilled draft review.

Persistence changes:

- no persistence for recommendations in this delivery;
- quiz content remains persisted only when the administrator submits the
  existing `New question with AI` flow.

Machine-readable contract:

- recommended draft payload:

```json
{
  "prompt": "Which PHP construct outputs text?",
  "correctOptions": ["echo"],
  "wrongOptions": ["select", "mount"]
}
```

## Quality Attributes

Security:

- OpenAI credentials must not be exposed in templates, logs, generated files, or
  client-side JavaScript.
- Only authenticated administrators may request AI recommendations.

Privacy:

- Existing question prompts from the selected context are sent to OpenAI.
- Existing correct and wrong options must not be sent as recommendation
  context.
- Administrator credentials, session values, local filesystem paths, and
  unrelated project data must not be sent to OpenAI.

Accessibility:

- The recommendation action and resulting errors must be reachable and readable
  through normal form navigation.

Performance:

- The recommendation request may wait on OpenAI.
- Existing prompt context must be limited to avoid excessive payload size and
  latency.

Reliability:

- Recommendation failures must not alter quiz JSON files.
- Malformed AI responses must be rejected before reaching the review form.

Observability:

- The manager should log recommendation failures with locale, language,
  difficulty, and prompt-context count.
- Logs must not include API keys or full administrator session data.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- AI recommendation requires configured OpenAI API credentials and model
  settings.
- The existing prompt context limit is 50 by default and should be centralized
  in the manager implementation.

Rollback:

- standard deploy rollback.
- No generated JSON files require rollback because recommendation alone is
  non-persistent.

Monitoring:

- recommendation failure logs;
- manual verification that recommendations open the review form without saving
  files.

## Verification

Planned checks:

- unit tests for context collection using only selected locale/language/
  difficulty prompts;
- unit tests that answer options from existing questions are not included in AI
  context;
- unit tests for prompt context limiting;
- unit tests for malformed recommendation rejection;
- unit tests for exact prompt duplicate rejection;
- functional test that successful recommendation pre-fills `New question with
  AI`;
- Twig lint and container lint for manager changes.

Evidence to record:

- test command output;
- manual note showing a recommendation prefilled in the review form;
- failure case showing no JSON files created.

## Open Questions

- None.
