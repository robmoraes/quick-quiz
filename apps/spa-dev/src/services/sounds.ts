import actionEndSessionSound from 'src/assets/sounds/action-end-session.wav?url';
import actionNewRunSound from 'src/assets/sounds/action-new-run.wav?url';
import actionNextSound from 'src/assets/sounds/action-next.oga?url';
import appLoadedSound from 'src/assets/sounds/app-loaded.oga?url';
import fatalSound from 'src/assets/sounds/glass-breaking.mp3?url';
import topicChangedSound from 'src/assets/sounds/language-changed.ogg?url';
import questionFailedAltSound from 'src/assets/sounds/question-failed.wav?url';
import questionPassedSound from 'src/assets/sounds/question-passed.mp3?url';
import runCompleteSound from 'src/assets/sounds/run-complete.mp3?url';
import runStartedSound from 'src/assets/sounds/run-started.oga?url';
import severityChangedSound from 'src/assets/sounds/severity-changed.oga?url';
import { getAudioSettings } from 'src/services/audio-settings';

export type QuizSound =
  | 'appLoaded'
  | 'topicChanged'
  | 'actionNext'
  | 'severityChanged'
  | 'runStarted'
  | 'runComplete'
  | 'actionNewRun'
  | 'actionEndSession'
  | 'questionPassed'
  | 'questionFailed'
  | 'fatal';

const soundSources: Record<QuizSound, string> = {
  appLoaded: appLoadedSound,
  topicChanged: topicChangedSound,
  actionNext: actionNextSound,
  severityChanged: severityChangedSound,
  runStarted: runStartedSound,
  questionPassed: questionPassedSound,
  questionFailed: questionFailedAltSound,
  runComplete: runCompleteSound,
  actionNewRun: actionNewRunSound,
  actionEndSession: actionEndSessionSound,
  fatal: fatalSound,
};

export function playQuizSound(sound: QuizSound) {
  const settings = getAudioSettings();
  if (!settings.soundsEnabled || settings.soundsVolume <= 0) {
    return;
  }

  const audio = new Audio(soundSources[sound]);
  audio.currentTime = 0;
  audio.volume = settings.soundsVolume;

  void audio.play().catch(() => {
    // Browsers can block audio until the user grants playback; quiz flow must continue.
  });
}
