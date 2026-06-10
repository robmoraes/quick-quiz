# SPA Refactor Acceptance

Date: 2026-06-09

## Structure

`apps/spa-dev/src/pages/IndexPage.vue` is the route-level composition layer. Final
line count: 211.

UI components:

- `StartPanel.vue`: topic selection, topic description, rules/settings actions,
  next and end-session actions.
- `DifficultyPanel.vue`: selected topic summary, difficulty selection,
  difficulty explanation, start-run and end-session actions.
- `QuestionPanel.vue`: active question progress, multiline prompt, multiline
  options, answer and end-session events.
- `RunAdPanel.vue`: mobile result interstitial display and close event.
- `FatalLossPanel.vue`: fatal hardcore loss display and new-session event.
- `ResultPanel.vue`: run/session summary, answer review, terminal event view,
  and result actions.
- `SettingsDialog.vue`: locale and audio preference form.
- `RulesModal.vue`: localized RFC/rules document overlay.

Composables:

- `useQuizCatalog.ts`: catalog loading, session topic/difficulty availability,
  topic filtering, selected topic metadata, difficulty metadata and labels.
- `useQuizRun.ts`: run creation, answer submission, result loading, fatal loss,
  ad-result transitions, session end/reset, sounds, and session events.
- `useQuizPreferences.ts`: locale/audio preference state, draft loading, and
  preference persistence.

## Acceptance Notes

- UI components receive state through props and emit intent events.
- API calls remain outside UI components and are centralized in composables.
- Existing API models and service functions are reused.
- Existing i18n keys and locale preference behavior are preserved.
- Existing session event names are preserved in composables.
- Multiline question and option rendering remains covered by existing
  `white-space: pre-wrap` styles for `.question-panel h2` and
  `.option-button .q-btn__content`.
- CSS behavior was not intentionally changed for this final acceptance step.

## Validation

Automated checks:

- `cd apps/spa-dev && npm run lint`: passed.
- `cd apps/spa-dev && npm run build`: passed.

Manual checks:

- Full browser flow smoke test: not run in this environment.
- Mobile result interstitial viewport check: not run in this environment.
- Multiline prompt browser check: not run in this environment; CSS preservation
  was verified by code inspection.
