import type { LocalePreference } from 'src/i18n/locale';
import type { AudioSettings } from 'src/services/audio-settings';
import type {
  Difficulty,
  DifficultyInfo,
  PublicQuestion,
  RunResult,
  TopicOption,
} from 'src/services/api';
import type { SessionEvent } from 'src/services/session-events';

export type QuizScreen =
  | 'start'
  | 'difficulty'
  | 'question'
  | 'fatalLoss'
  | 'runResult'
  | 'sessionResult';

export type AnswerFeedback = 'idle' | 'correct' | 'wrong';

export type ResultAnswer = RunResult['answers'][number];
export type ResultStats = RunResult['stats'];
export type ResultVariant = 'run' | 'session';

export type DifficultyState = DifficultyInfo & {
  availableQuestionCount?: number;
  available?: boolean;
};

export type TopicState = TopicOption & {
  questionCount?: number;
  availableQuestionCount?: number;
  available?: boolean;
};

export type TopicSelectOption = {
  label: string;
  value: string;
  disable: boolean;
};

export type DifficultyOption = {
  label: string;
  value: Difficulty;
  color: string;
  keepColor: boolean;
  class: string;
  disable: boolean;
};

export interface AvailabilityCounter {
  available: number;
  total: number;
  active: boolean;
}

export interface ResultSnapshot {
  stats: ResultStats;
  answers: ResultAnswer[];
  events: SessionEvent[];
}

export interface ResultView extends ResultSnapshot {
  variant: ResultVariant;
  title: string;
  subtitle: string;
}

export type TopicFilterUpdater = (callback: () => void) => void;

export interface StartPanelProps {
  selectedTopic: string;
  topicOptions: TopicSelectOption[];
  selectedTopicDescription: string;
  sessionQuestionCounter: AvailabilityCounter | null;
  selectedTopicQuestionCounter: AvailabilityCounter | null;
  loadingCatalog: boolean;
  errorMessage: string;
  busy: boolean;
  canAdvance: boolean;
  canEndSession: boolean;
  nextActionLabel: string | undefined;
  endSessionActionLabel: string | undefined;
}

export type StartPanelEmit = {
  (event: 'update:selectedTopic', value: string): void;
  (event: 'clear-topic'): void;
  (event: 'filter-topic-options', inputValue: string, update: TopicFilterUpdater): void;
  (event: 'topic-changed', value?: string | null): void;
  (event: 'open-settings'): void;
  (event: 'open-rules'): void;
  (event: 'next'): void;
  (event: 'end-session'): void;
};

export interface DifficultyPanelProps {
  selectedTopicLabel: string;
  selectedDifficulty: Difficulty;
  difficultyOptions: DifficultyOption[];
  selectedDifficultyInfo: DifficultyState | null;
  sessionQuestionCounter: AvailabilityCounter | null;
  selectedTopicQuestionCounter: AvailabilityCounter | null;
  selectedDifficultyClass: string;
  selectedDifficultyPrefix: string;
  selectedDifficultyMessage: string;
  errorMessage: string;
  busy: boolean;
  canStart: boolean;
  canEndSession: boolean;
  startRunActionLabel: string | undefined;
  endSessionActionLabel: string | undefined;
}

export type DifficultyPanelEmit = {
  (event: 'update:selectedDifficulty', value: Difficulty): void;
  (event: 'change-topic'): void;
  (event: 'difficulty-changed'): void;
  (event: 'open-settings'): void;
  (event: 'open-rules'): void;
  (event: 'start-run'): void;
  (event: 'end-session'): void;
};

export interface QuestionPanelProps {
  currentQuestion: PublicQuestion;
  selectedTopicLabel: string;
  selectedDifficultyLabel: string;
  sessionQuestionCounter: AvailabilityCounter | null;
  selectedTopicQuestionCounter: AvailabilityCounter | null;
  busy: boolean;
  feedback: AnswerFeedback;
  canEndSession: boolean;
  endSessionActionLabel: string | undefined;
}

export type QuestionPanelEmit = {
  (event: 'answer', optionId: string): void;
  (event: 'end-session'): void;
};

export interface FatalLossPanelProps {
  messageVisible: boolean;
}

export type FatalLossPanelEmit = {
  (event: 'new-session'): void;
};

export interface ResultPanelProps {
  result: ResultView;
  errorMessage: string;
  busy: boolean;
  sessionCompleted: boolean;
  canEndSession: boolean;
  newRunActionLabel: string | undefined;
  endSessionActionLabel: string | undefined;
  newSessionActionLabel: string | undefined;
}

export type ResultPanelEmit = {
  (event: 'open-rules'): void;
  (event: 'open-settings'): void;
  (event: 'new-run'): void;
  (event: 'end-session'): void;
  (event: 'new-session'): void;
};

export type LocalePreferenceOption = {
  label: string;
  value: LocalePreference;
};

export interface SettingsDialogProps {
  modelValue: boolean;
  selectedLocalePreference: LocalePreference;
  selectedAudioSettings: AudioSettings;
  selectedMusicVolumePercent: number;
  selectedSoundsVolumePercent: number;
  localePreferenceOptions: LocalePreferenceOption[];
}

export type SettingsDialogEmit = {
  (event: 'update:modelValue', value: boolean): void;
  (event: 'update:selectedLocalePreference', value: LocalePreference): void;
  (event: 'update:selectedAudioSettings', value: AudioSettings): void;
  (event: 'update:selectedMusicVolumePercent', value: number): void;
  (event: 'update:selectedSoundsVolumePercent', value: number): void;
  (event: 'before-show'): void;
  (event: 'save'): void;
};

export interface RulesModalProps {
  modelValue: boolean;
  documentText: string;
}

export type RulesModalEmit = {
  (event: 'update:modelValue', value: boolean): void;
};
