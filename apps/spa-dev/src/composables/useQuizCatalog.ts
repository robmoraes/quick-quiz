import { computed, ref, watch, type Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  Difficulty,
  getCatalog,
  getSessionDifficulties,
  getSessionId,
  getSessionTopics,
  type Catalog,
  type SessionDifficulties,
  type SessionTopics,
} from 'src/services/api';
import { playQuizSound } from 'src/services/sounds';
import type {
  AvailabilityCounter,
  DifficultyOption,
  DifficultyState,
  TopicSelectOption,
  TopicState,
} from 'src/components/quiz/contracts';
import type { SessionEventInput } from 'src/services/session-events';

type SessionEventRecorder = (input: SessionEventInput) => void;

export function useQuizCatalog(theme: Ref<string>) {
  const { t, locale } = useI18n();

  const catalog = ref<Catalog>({
    theme: theme.value,
    locale: 'en-US',
    fallbackLocale: 'en-US',
    topics: [],
    difficulties: [],
  });
  const selectedTopic = ref('');
  const selectedDifficulty = ref<Difficulty>(Difficulty.Easy);
  const sessionHasBackendState = ref(false);
  const sessionTopics = ref<SessionTopics | null>(null);
  const sessionDifficulties = ref<SessionDifficulties | null>(null);
  const loadingCatalog = ref(false);
  const loadingSessionTopics = ref(false);
  const loadingSessionDifficulties = ref(false);
  const errorMessage = ref('');
  const topicOptions = ref<TopicSelectOption[]>([]);
  const sessionEventRecorder = ref<SessionEventRecorder | null>(null);

  let generation = 0;
  const requests = { catalog: 0, topics: 0, difficulties: 0 };

  function invalidateRequests() {
    generation++;
    loadingCatalog.value = false;
    loadingSessionTopics.value = false;
    loadingSessionDifficulties.value = false;
  }

  function requestContext(kind: keyof typeof requests, topic?: string) {
    const version = generation;
    const request = ++requests[kind];
    const requestedTheme = theme.value;
    const requestedLocale = locale.value;
    const session = getSessionId();
    return () =>
      version === generation &&
      request === requests[kind] &&
      requestedTheme === theme.value &&
      requestedLocale === locale.value &&
      session === getSessionId() &&
      (topic === undefined || topic === selectedTopic.value);
  }

  watch([theme, locale], invalidateRequests, { flush: 'sync' });
  watch(
    selectedTopic,
    () => {
      requests.difficulties++;
      loadingSessionDifficulties.value = false;
      sessionDifficulties.value = null;
    },
    { flush: 'sync' },
  );

  function resetForTheme() {
    clearSessionAvailability();
    selectedTopic.value = '';
    selectedDifficulty.value = Difficulty.Easy;
    errorMessage.value = '';
    catalog.value = {
      theme: theme.value,
      locale: locale.value,
      fallbackLocale: 'en-US',
      topics: [],
      difficulties: [],
    };
  }

  const topicSource = computed<TopicState[]>(() =>
    sessionHasBackendState.value && sessionTopics.value
      ? sessionTopics.value.topics
      : catalog.value.topics,
  );

  const sortedTopics = computed(() => sortTopics(topicSource.value, locale.value));

  const allTopicOptions = computed<TopicSelectOption[]>(() =>
    sortedTopics.value.filter(topicIsAvailable).map((topic) => ({
      label: topic.label,
      value: topic.id,
      disable: loadingSessionTopics.value,
    })),
  );

  const selectedTopicMetadata = computed(
    () => sortedTopics.value.find((topic) => topic.id === selectedTopic.value) ?? null,
  );

  const selectedTopicDescription = computed(
    () => selectedTopicMetadata.value?.description?.trim() ?? '',
  );
  const sessionQuestionCounter = computed<AvailabilityCounter | null>(() => {
    if (!sessionHasBackendState.value || !sessionTopics.value) {
      const total = catalog.value.topics.reduce(
        (counterTotal, topic) => counterTotal + topicTotalCount(topic),
        0,
      );
      return buildCounter(total, total, false);
    }

    return buildCounter(
      sessionTopics.value.topics.reduce((total, topic) => total + topicAvailableCount(topic), 0),
      sessionTopics.value.topics.reduce((total, topic) => total + topicTotalCount(topic), 0),
      true,
    );
  });
  const selectedTopicQuestionCounter = computed<AvailabilityCounter | null>(() => {
    const topic = selectedTopicMetadata.value;
    if (!topic) {
      return null;
    }

    const total = topicTotalCount(topic);
    const available = sessionHasBackendState.value ? topicAvailableCount(topic) : total;
    return buildCounter(available, total, sessionHasBackendState.value);
  });

  const sessionDifficultySource = computed(() =>
    sessionHasBackendState.value && sessionDifficulties.value?.topic === selectedTopic.value
      ? sessionDifficulties.value.difficulties
      : null,
  );

  const availableDifficulties = computed<DifficultyState[]>(() =>
    [...(sessionDifficultySource.value ?? selectedTopicMetadata.value?.difficulties ?? [])]
      .filter((difficulty) => difficulty.questionCount > 0)
      .sort((left, right) => left.id - right.id),
  );

  const difficultyOptions = computed<DifficultyOption[]>(() =>
    availableDifficulties.value.map((difficulty) => ({
      label: difficultyLabel(difficulty.id, t),
      value: difficulty.id,
      color: difficultyColor(difficulty.id),
      keepColor: true,
      class: `difficulty-option--${difficultySeverity(difficulty.id)}`,
      disable: loadingSessionDifficulties.value || !difficultyIsAvailable(difficulty),
    })),
  );

  const selectedTopicLabel = computed(
    () => selectedTopicMetadata.value?.label ?? selectedTopic.value,
  );
  const selectedTopicAvailable = computed(() =>
    selectedTopicMetadata.value ? topicIsAvailable(selectedTopicMetadata.value) : false,
  );

  const selectedDifficultyInfo = computed(
    () =>
      availableDifficulties.value.find(
        (difficulty) => difficulty.id === selectedDifficulty.value,
      ) ?? null,
  );
  const selectedDifficultyAvailable = computed(() =>
    selectedDifficultyInfo.value ? difficultyIsAvailable(selectedDifficultyInfo.value) : false,
  );
  const hasAvailableDifficulty = computed(() =>
    availableDifficulties.value.some(difficultyIsAvailable),
  );
  const selectedDifficultyLabel = computed(() => difficultyLabel(selectedDifficulty.value, t));
  const selectedDifficultySeverity = computed(() => difficultySeverity(selectedDifficulty.value));
  const selectedDifficultyClass = computed(
    () => `difficulty-option--${selectedDifficultySeverity.value}`,
  );
  const selectedDifficultyPrefix = computed(() => `Severity.${selectedDifficultyLabel.value}`);
  const selectedDifficultyMessage = computed(() =>
    t(`difficultyMessages.${selectedDifficultySeverity.value}`),
  );

  watch(
    allTopicOptions,
    (options) => {
      topicOptions.value = options;
    },
    { immediate: true },
  );

  function setSessionEventRecorder(recorder: SessionEventRecorder) {
    sessionEventRecorder.value = recorder;
  }

  function onTopicChanged(value?: string | null) {
    if (value == null) {
      selectedTopic.value = '';
    }
    playQuizSound('topicChanged');
    syncSelectedDifficulty();
  }

  function clearSelectedTopic() {
    selectedTopic.value = '';
    syncSelectedDifficulty();
  }

  function filterTopicOptions(inputValue: string, update: (callback: () => void) => void) {
    update(() => {
      const needle = normalizeSearch(inputValue);
      if (!needle) {
        topicOptions.value = allTopicOptions.value;
        return;
      }

      topicOptions.value = allTopicOptions.value.filter(
        (option) =>
          normalizeSearch(option.label).includes(needle) ||
          normalizeSearch(option.value).includes(needle),
      );
    });
  }

  function onDifficultyChanged() {
    playQuizSound('severityChanged');
  }

  async function loadCatalog(): Promise<boolean> {
    if (!theme.value) return false;
    const isCurrent = requestContext('catalog');
    loadingCatalog.value = true;
    errorMessage.value = '';

    try {
      const previousTopic = selectedTopic.value;
      const loaded = await getCatalog(theme.value);
      if (!isCurrent()) return false;
      catalog.value = loaded;
      selectedTopic.value = sortedTopics.value.some((topic) => topic.id === previousTopic)
        ? previousTopic
        : '';
      syncSelectedTopic();
      syncSelectedDifficulty();
      return true;
    } catch {
      if (isCurrent()) errorMessage.value = t('errors.apiUnavailable');
      return false;
    } finally {
      if (isCurrent()) loadingCatalog.value = false;
    }
  }

  function syncSelectedDifficulty() {
    const current = selectedDifficulty.value;
    const currentStillAvailable = availableDifficulties.value.some(
      (difficulty) => difficulty.id === current && difficultyIsAvailable(difficulty),
    );

    if (!currentStillAvailable) {
      selectedDifficulty.value =
        availableDifficulties.value.find(difficultyIsAvailable)?.id ??
        availableDifficulties.value[0]?.id ??
        Difficulty.Easy;
    }
  }

  function syncSelectedTopic() {
    const current = selectedTopic.value;
    const currentStillAvailable = sortedTopics.value.some(
      (topic) => topic.id === current && topicIsAvailable(topic),
    );

    if (!currentStillAvailable) {
      selectedTopic.value = '';
    }
  }

  async function refreshTopicAvailability() {
    if (!sessionHasBackendState.value) {
      sessionTopics.value = null;
      return;
    }

    const isCurrent = requestContext('topics');
    loadingSessionTopics.value = true;
    errorMessage.value = '';
    sessionTopics.value = null;

    try {
      const loaded = await getSessionTopics(theme.value);
      if (!isCurrent()) return;
      sessionTopics.value = loaded;
      recordSessionEvent({
        event: 'availability.topics_refreshed',
        severity: sessionTopics.value.topics.some((topic) => topic.available) ? 'info' : 'critical',
        sessionId: getSessionId(),
        message: 'Session topic availability refreshed',
        fields: topicAvailabilityFields(sessionTopics.value),
      });
    } catch {
      if (isCurrent()) errorMessage.value = t('errors.topics');
    } finally {
      if (isCurrent()) loadingSessionTopics.value = false;
    }
  }

  async function restoreSessionAvailability() {
    sessionHasBackendState.value = true;
    await refreshTopicAvailability();
    syncSelectedTopic();
    syncSelectedDifficulty();
  }

  async function refreshSessionCompletion() {
    if (!sessionHasBackendState.value) {
      return false;
    }

    const isCurrent = requestContext('topics');
    loadingSessionTopics.value = true;
    try {
      const loaded = await getSessionTopics(theme.value);
      if (!isCurrent()) return false;
      sessionTopics.value = loaded;
      const completed = sessionTopics.value.topics.every((topic) => !topic.available);
      recordSessionEvent({
        event: 'availability.topics_refreshed',
        severity: completed ? 'critical' : 'info',
        sessionId: getSessionId(),
        message: completed
          ? 'Session has no available questions'
          : 'Session still has available questions',
        fields: {
          ...topicAvailabilityFields(sessionTopics.value),
          completed,
        },
      });
      return completed;
    } catch {
      if (isCurrent()) errorMessage.value = t('errors.topics');
      return false;
    } finally {
      if (isCurrent()) loadingSessionTopics.value = false;
    }
  }

  async function refreshDifficultyAvailability() {
    if (!sessionHasBackendState.value || !selectedTopic.value) {
      sessionDifficulties.value = null;
      return;
    }

    const isCurrent = requestContext('difficulties', selectedTopic.value);
    loadingSessionDifficulties.value = true;
    errorMessage.value = '';
    sessionDifficulties.value = null;

    try {
      const loaded = await getSessionDifficulties(theme.value, selectedTopic.value);
      if (!isCurrent()) return;
      sessionDifficulties.value = loaded;
      recordSessionEvent({
        event: 'availability.difficulties_refreshed',
        severity: sessionDifficulties.value.difficulties.some((difficulty) => difficulty.available)
          ? 'info'
          : 'critical',
        sessionId: getSessionId(),
        message: 'Session severity availability refreshed',
        fields: {
          locale: sessionDifficulties.value.locale,
          topic: sessionDifficulties.value.topic,
          availableDifficulties: sessionDifficulties.value.difficulties.filter(
            (difficulty) => difficulty.available,
          ).length,
          totalDifficulties: sessionDifficulties.value.difficulties.length,
          availableQuestionCount: sessionDifficulties.value.difficulties.reduce(
            (total, difficulty) => total + difficulty.availableQuestionCount,
            0,
          ),
        },
      });
    } catch {
      if (isCurrent()) errorMessage.value = t('errors.difficulties');
    } finally {
      if (isCurrent()) loadingSessionDifficulties.value = false;
    }
  }

  function clearSessionAvailability() {
    invalidateRequests();
    sessionHasBackendState.value = false;
    sessionTopics.value = null;
    sessionDifficulties.value = null;
  }

  function recordSessionEvent(input: SessionEventInput) {
    sessionEventRecorder.value?.(input);
  }

  return {
    theme,
    resetForTheme,
    catalog,
    selectedTopic,
    selectedDifficulty,
    sessionHasBackendState,
    sessionTopics,
    sessionDifficulties,
    loadingCatalog,
    loadingSessionTopics,
    loadingSessionDifficulties,
    errorMessage,
    topicOptions,
    selectedTopicDescription,
    sessionQuestionCounter,
    selectedTopicQuestionCounter,
    difficultyOptions,
    selectedTopicLabel,
    selectedTopicAvailable,
    selectedDifficultyInfo,
    selectedDifficultyAvailable,
    hasAvailableDifficulty,
    selectedDifficultyLabel,
    selectedDifficultyClass,
    selectedDifficultyPrefix,
    selectedDifficultyMessage,
    setSessionEventRecorder,
    onTopicChanged,
    clearSelectedTopic,
    filterTopicOptions,
    onDifficultyChanged,
    loadCatalog,
    syncSelectedDifficulty,
    syncSelectedTopic,
    refreshTopicAvailability,
    restoreSessionAvailability,
    refreshSessionCompletion,
    refreshDifficultyAvailability,
    clearSessionAvailability,
  };
}

export type QuizCatalog = ReturnType<typeof useQuizCatalog>;

function topicAvailabilityFields(sessionTopics: SessionTopics) {
  return {
    locale: sessionTopics.locale,
    availableTopics: sessionTopics.topics.filter((topic) => topic.available).length,
    totalTopics: sessionTopics.topics.length,
    availableQuestionCount: sessionTopics.topics.reduce(
      (total, topic) => total + topic.availableQuestionCount,
      0,
    ),
  };
}

function buildCounter(
  available: number,
  total: number,
  active: boolean,
): AvailabilityCounter | null {
  if (total <= 0) {
    return null;
  }

  return {
    available: Math.max(0, available),
    total,
    active,
  };
}

function topicTotalCount(topic: TopicState) {
  return topic.questionCount ?? countQuestionsByDifficulty(topic);
}

function topicAvailableCount(topic: TopicState) {
  return topic.availableQuestionCount ?? topicTotalCount(topic);
}

function countQuestionsByDifficulty(topic: TopicState) {
  return (topic.difficulties ?? []).reduce(
    (total, difficulty) => total + difficulty.questionCount,
    0,
  );
}

function sortTopics(topics: TopicState[], locale: string) {
  return [...topics].sort((left, right) => {
    const labelOrder = left.label.localeCompare(right.label, locale, {
      sensitivity: 'base',
    });
    if (labelOrder !== 0) {
      return labelOrder;
    }

    return left.weight - right.weight;
  });
}

function difficultyLabel(difficulty: Difficulty, t: (key: string) => string) {
  const keys: Record<Difficulty, string> = {
    [Difficulty.Easy]: 'difficulty.info',
    [Difficulty.Normal]: 'difficulty.warn',
    [Difficulty.Hard]: 'difficulty.error',
    [Difficulty.Hardcore]: 'difficulty.fatal',
  };

  return t(keys[difficulty] ?? 'difficulty.unknown');
}

function difficultySeverity(difficulty: Difficulty) {
  const severities: Record<Difficulty, string> = {
    [Difficulty.Easy]: 'info',
    [Difficulty.Normal]: 'warn',
    [Difficulty.Hard]: 'error',
    [Difficulty.Hardcore]: 'fatal',
  };

  return severities[difficulty] ?? 'unknown';
}

function difficultyColor(difficulty: Difficulty) {
  const colors: Record<Difficulty, string> = {
    [Difficulty.Easy]: 'blue',
    [Difficulty.Normal]: 'amber',
    [Difficulty.Hard]: 'red-4',
    [Difficulty.Hardcore]: 'red-10',
  };

  return colors[difficulty] ?? 'grey';
}

function difficultyIsAvailable(difficulty: DifficultyState) {
  return difficulty.available ?? true;
}

function topicIsAvailable(topic: TopicState) {
  return topic.available ?? true;
}

function normalizeSearch(value: string) {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}
