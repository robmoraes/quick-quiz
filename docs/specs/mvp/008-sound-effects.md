# QuickQuiz Dev MVP: sound effects

## Objective

Add sound effects for interface events using the same mechanism that already
exists in `frontend/src/services/sounds.ts`.

This spec must not create a sound package architecture, package selection, or a
package configuration screen. The current goal is only to centralize the files in
the project, import them in the frontend, and play the sounds on the defined
events.

## Audio Files

External files must be copied to `frontend/src/assets/sounds/`. After being
copied, the frontend must import the local files, never absolute paths like
`/usr/share/...`.

| event                     | source                                                                         | suggested destination                                    |
| ------------------------- | ------------------------------------------------------------------------------ | -------------------------------------------------------- |
| load QUICK QUIZ DEV       | `/usr/share/sounds/freedesktop/stereo/service-login.oga`                       | `frontend/src/assets/sounds/app-loaded.oga`              |
| change LANGUAGE           | `/usr/share/sounds/gnome/default/alerts/click.ogg`                             | `frontend/src/assets/sounds/language-changed.ogg`        |
| action button NEXT        | `/usr/share/sounds/freedesktop/stereo/dialog-information.oga`                  | `frontend/src/assets/sounds/action-next.oga`             |
| change SEVERITY           | `/usr/share/sounds/freedesktop/stereo/dialog-information.oga`                  | `frontend/src/assets/sounds/severity-changed.oga`        |
| action button START RUN   | `/usr/share/sounds/Yaru/stereo/desktop-login.oga`                              | `frontend/src/assets/sounds/run-started.oga`             |
| end RUN                   | `frontend/src/assets/sounds/run-complete.mp3`                                  | `frontend/src/assets/sounds/run-complete.mp3`            |
| action button NEW RUN     | `/usr/share/sounds/speech-dispatcher/test.wav`                                 | `frontend/src/assets/sounds/action-new-run.wav`          |
| action button END SESSION | `/usr/share/sounds/speech-dispatcher/test.wav`                                 | `frontend/src/assets/sounds/action-end-session.wav`      |
| Answer CORRECT            | `frontend/src/assets/sounds/question-passed.mp3`                               | `frontend/src/assets/sounds/question-passed.mp3`         |
| Answer WRONG              | `/usr/share/sounds/sound-icons/pipe.wav`                                       | `frontend/src/assets/sounds/question-failed.wav`         |

## Implementation

Expand the `QuizSound` type in `frontend/src/services/sounds.ts` to include the
new events:

```ts
export type QuizSound =
  | 'appLoaded'
  | 'languageChanged'
  | 'actionNext'
  | 'severityChanged'
  | 'runStarted'
  | 'runComplete'
  | 'actionNewRun'
  | 'actionEndSession'
  | 'questionPassed'
  | 'questionFailed';
```

Import each audio file in `sounds.ts` and add the corresponding entry in
`soundSources`.

Keep the `playQuizSound(sound)` function with the current behavior:

- respect `soundsEnabled`;
- respect `soundsVolume`;
- create `new Audio(...)`;
- ignore `audio.play()` failures so the quiz flow does not break.

## Screen Events

Add calls to `playQuizSound(...)` in the events below:

- `appLoaded`: initial loading of QuickQuiz Dev.
- `languageChanged`: language selection/change.
- `actionNext`: `Next` button.
- `severityChanged`: severity selection/change.
- `runStarted`: `Start run` button.
- `runComplete`: end of run.
- `actionNewRun`: `New run` button.
- `actionEndSession`: `End session` button.
- `questionPassed`: correct answer.
- `questionFailed`: wrong answer.

## Notes

The `appLoaded` sound may be blocked by the browser's autoplay policy. This is
acceptable. The implementation must keep the silent failure already used in
`playQuizSound`.

This spec covers only sound effects. Background music and configurable sound
packages are out of scope.
