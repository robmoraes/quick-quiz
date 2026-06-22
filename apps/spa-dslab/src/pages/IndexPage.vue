<template>
  <q-page class="quiz-page" :class="{ 'quiz-page--ad-interstitial': screen === 'fatalLoss' }">
    <WelcomePanel v-if="welcomePanelVisible" @advance="advanceFromWelcomePanel" />

    <TopicPanel
      v-else-if="screen === 'start'"
      v-model:selected-topic="selectedTopic"
      :topic-options="topicOptions"
      :selected-topic-description="selectedTopicDescription"
      :session-question-counter="sessionQuestionCounter"
      :selected-topic-question-counter="selectedTopicQuestionCounter"
      :loading-catalog="loadingCatalog"
      :error-message="errorMessage"
      :busy="busy"
      :can-advance="canAdvance"
      :can-end-session="canEndSession"
      :next-action-label="actionLabel('actions.next', canEndSession)"
      :end-session-action-label="actionLabel('actions.endSession', canEndSession)"
      @clear-topic="handleClearSelectedTopic"
      @filter-topic-options="filterTopicOptions"
      @topic-changed="handleTopicChanged"
      @open-settings="settingsOpen = true"
      @open-rules="rulesOpen = true"
      @next="goToDifficulty"
      @end-session="confirmEndSession"
    />

    <DifficultyPanel
      v-else-if="screen === 'difficulty'"
      v-model:selected-difficulty="selectedDifficulty"
      :selected-topic-label="selectedTopicLabel"
      :difficulty-options="difficultyOptions"
      :selected-difficulty-info="selectedDifficultyInfo"
      :session-question-counter="sessionQuestionCounter"
      :selected-topic-question-counter="selectedTopicQuestionCounter"
      :selected-difficulty-class="selectedDifficultyClass"
      :selected-difficulty-prefix="selectedDifficultyPrefix"
      :selected-difficulty-message="selectedDifficultyMessage"
      :error-message="errorMessage"
      :busy="busy"
      :can-start="canStart"
      :can-end-session="canEndSession"
      :start-run-action-label="actionLabel('actions.startRun', canEndSession)"
      :end-session-action-label="actionLabel('actions.endSession', canEndSession)"
      @change-topic="goToTopicSelection"
      @difficulty-changed="onDifficultyChanged"
      @open-settings="settingsOpen = true"
      @open-rules="rulesOpen = true"
      @start-run="startRun"
      @end-session="confirmEndSession"
    />

    <QuestionPanel
      v-else-if="screen === 'question' && currentQuestion"
      :current-question="currentQuestion"
      :selected-topic-label="selectedTopicLabel"
      :selected-difficulty-label="selectedDifficultyLabel"
      :session-question-counter="sessionQuestionCounter"
      :selected-topic-question-counter="selectedTopicQuestionCounter"
      :busy="busy"
      :feedback="feedback"
      :can-end-session="canEndSession"
      :end-session-action-label="actionLabel('actions.endSession')"
      @answer="submitAnswer"
      @end-session="confirmEndSession"
    />

    <div v-else-if="screen === 'question'" class="column items-center justify-center q-pa-xl">
      <q-spinner-dots color="amber-8" size="42px" />
    </div>

    <FatalLossPanel
      v-else-if="screen === 'fatalLoss'"
      :message-visible="fatalLossMessageVisible"
      @new-session="newSession"
    />

    <ResultPanel
      v-else-if="displayedResult"
      :result="displayedResult"
      :error-message="errorMessage"
      :busy="busy"
      :session-completed="sessionCompleted"
      :can-end-session="canEndSession"
      :new-run-action-label="actionLabel('actions.newRun', canEndSession)"
      :end-session-action-label="actionLabel('actions.endSession', !sessionCompleted)"
      :new-session-action-label="actionLabel('actions.newSession')"
      @open-rules="rulesOpen = true"
      @open-settings="settingsOpen = true"
      @new-run="newRun"
      @end-session="handleResultEndSession"
      @new-session="newSession"
    />

    <SettingsDialog
      v-model="settingsOpen"
      v-model:selected-locale-preference="selectedLocalePreference"
      v-model:selected-audio-settings="selectedAudioSettings"
      v-model:selected-music-volume-percent="selectedMusicVolumePercent"
      v-model:selected-sounds-volume-percent="selectedSoundsVolumePercent"
      :locale-preference-options="localePreferenceOptions"
      @before-show="loadSettingsDraft"
      @save="saveSettings"
    />

    <RulesModal v-model="rulesOpen" :document-text="currentRulesText" />
  </q-page>
</template>

<script setup lang="ts">
import helpRfcEnUS from 'src/assets/help/rfc-0001-quick-quiz-dev-game-rules-protocol.en-US.txt?raw';
import helpRfcPtBR from 'src/assets/help/rfc-0001-quick-quiz-dev-game-rules-protocol.pt-BR.txt?raw';
import { useQuasar } from 'quasar';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import DifficultyPanel from 'src/components/quiz/DifficultyPanel.vue';
import FatalLossPanel from 'src/components/quiz/FatalLossPanel.vue';
import QuestionPanel from 'src/components/quiz/QuestionPanel.vue';
import ResultPanel from 'src/components/quiz/ResultPanel.vue';
import RulesModal from 'src/components/quiz/RulesModal.vue';
import SettingsDialog from 'src/components/quiz/SettingsDialog.vue';
import TopicPanel from 'src/components/quiz/TopicPanel.vue';
import WelcomePanel from 'src/components/quiz/WelcomePanel.vue';
import { useQuizCatalog } from 'src/composables/useQuizCatalog';
import { useLayoutAds } from 'src/composables/useLayoutAds';
import { useQuizPreferences } from 'src/composables/useQuizPreferences';
import { useQuizRun } from 'src/composables/useQuizRun';

const $q = useQuasar();
const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const quizCatalog = useQuizCatalog();
const { setLayoutAdsTopic, refreshLayoutAds } = useLayoutAds();
const {
  selectedTopic,
  selectedDifficulty,
  loadingCatalog,
  topicOptions,
  selectedTopicDescription,
  sessionQuestionCounter,
  selectedTopicQuestionCounter,
  difficultyOptions,
  selectedTopicLabel,
  selectedDifficultyInfo,
  selectedDifficultyLabel,
  selectedDifficultyClass,
  selectedDifficultyPrefix,
  selectedDifficultyMessage,
  onTopicChanged: updateCatalogTopic,
  clearSelectedTopic: clearCatalogSelectedTopic,
  filterTopicOptions,
  onDifficultyChanged,
  loadCatalog,
  syncSelectedDifficulty,
  syncSelectedTopic,
  refreshTopicAvailability,
  restoreSessionAvailability,
  refreshDifficultyAvailability,
} = quizCatalog;

const {
  screen,
  currentQuestion,
  sessionCompleted,
  busy,
  errorMessage,
  feedback,
  fatalLossMessageVisible,
  displayedResult,
  canEndSession,
  canAdvance,
  canStart,
  initializeSession,
  goToDifficulty,
  goToTopicSelection,
  startRun,
  submitAnswer,
  newRun,
  confirmEndSession,
  handleResultEndSession,
  newSession,
} = useQuizRun({ catalog: quizCatalog });

const {
  locale,
  settingsOpen,
  selectedLocalePreference,
  selectedAudioSettings,
  localePreferenceOptions,
  selectedSoundsVolumePercent,
  selectedMusicVolumePercent,
  saveSettings: savePreferences,
  loadSettingsDraft,
} = useQuizPreferences();

const welcomePanelSeenStorageKey = 'quickquiz.welcomePanelSeen';
const catalogReady = ref(false);
const rulesOpen = ref(false);
const welcomePanelVisible = ref(isWelcomePanelPending());

const currentRulesText = computed(() => (locale.value === 'pt-BR' ? helpRfcPtBR : helpRfcEnUS));

watch(
  () => route.query.topic,
  (value) => {
    const routeTopic = normalizeTopicQueryValue(value);
    if (routeTopic === selectedTopic.value) {
      return;
    }

    selectedTopic.value = routeTopic;
    if (catalogReady.value) {
      syncSelectedTopic();
    }
    syncSelectedDifficulty();
  },
  { immediate: true },
);

watch(selectedTopic, (topic) => {
  syncTopicQuery(topic);
  updateAdsTopic(topic);
});

onMounted(() => {
  initializeSession();
  void initializeCatalog();
});

function actionLabel(key: string, compactOnXs = false) {
  return $q.screen.xs && compactOnXs ? undefined : t(key);
}

async function saveSettings() {
  savePreferences();
  if (screen.value === 'start' || screen.value === 'difficulty') {
    await loadCatalog();
    if (screen.value === 'start') {
      await refreshTopicAvailability();
      syncSelectedTopic();
      syncSelectedDifficulty();
    } else if (screen.value === 'difficulty') {
      await refreshDifficultyAvailability();
      syncSelectedDifficulty();
    }
  }
}

async function initializeCatalog() {
  await loadCatalog();
  catalogReady.value = true;
  await restoreSessionAvailability();
}

function advanceFromWelcomePanel() {
  welcomePanelVisible.value = false;
  try {
    window.sessionStorage.setItem(welcomePanelSeenStorageKey, 'true');
  } catch {
    // Session storage can be unavailable in restricted browser modes.
  }
}

function handleTopicChanged(value?: string | null) {
  updateCatalogTopic(value);
}

function handleClearSelectedTopic() {
  clearCatalogSelectedTopic();
}

function updateAdsTopic(topic: string) {
  setLayoutAdsTopic(topic);
  void refreshLayoutAds();
}

function syncTopicQuery(topic: string) {
  if (normalizeTopicQueryValue(route.query.topic) === topic) {
    return;
  }

  const query = { ...route.query };
  if (topic) {
    query.topic = topic;
  } else {
    delete query.topic;
  }

  void router.replace({ query });
}

function normalizeTopicQueryValue(value: unknown) {
  if (typeof value === 'string') {
    return value.trim();
  }

  if (Array.isArray(value)) {
    return normalizeTopicQueryValue(value[0]);
  }

  return '';
}

function isWelcomePanelPending() {
  if (typeof window === 'undefined') {
    return true;
  }

  try {
    return window.sessionStorage.getItem(welcomePanelSeenStorageKey) !== 'true';
  } catch {
    return true;
  }
}
</script>
