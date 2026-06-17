import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useQuasar } from 'quasar';
import {
  answerQuestion,
  createRun,
  finishRun,
  getResult,
  getSessionId,
  isApiErrorCode,
  resetLocalSession,
  resetSession,
  Difficulty,
  type PublicQuestion,
  type RunResult,
} from 'src/services/api';
import { requestAdRefresh } from 'src/services/ad-refresh';
import { resetResultViewCount } from 'src/services/result-ad-frequency';
import {
  appendSessionEvent,
  clearSessionEventLog,
  cloneSessionEvents,
  sessionEventLog,
  type SessionEvent,
  type SessionEventInput,
} from 'src/services/session-events';
import { playQuizSound } from 'src/services/sounds';
import type { QuizCatalog } from './useQuizCatalog';
import type {
  AnswerFeedback,
  QuizScreen,
  ResultAnswer,
  ResultSnapshot,
  ResultView,
} from 'src/components/quiz/contracts';

interface UseQuizRunOptions {
  catalog: QuizCatalog;
}

type RunFlowOperation = 'answer' | 'result' | 'endSession';

export function useQuizRun({ catalog }: UseQuizRunOptions) {
  const $q = useQuasar();
  const { t, locale } = useI18n();

  const screen = ref<QuizScreen>('start');
  const runId = ref('');
  const currentQuestion = ref<PublicQuestion | null>(null);
  const result = ref<RunResult | null>(null);
  const sessionResult = ref<ResultSnapshot | null>(null);
  const sessionRuns = ref<RunResult[]>([]);
  const sessionEvents = sessionEventLog;
  const sessionOpen = ref(false);
  const sessionCompleted = ref(false);
  const busy = ref(false);
  const feedback = ref<AnswerFeedback>('idle');
  const fatalLossMessageVisible = ref(false);
  const errorMessage = catalog.errorMessage;

  const displayedResult = computed<ResultView | null>(() => {
    if (screen.value === 'runResult' && result.value) {
      return {
        variant: 'run',
        title: t('result.runTitle'),
        subtitle: finishReasonLabel(result.value.finishReason, t),
        stats: result.value.stats,
        answers: resultAnswers(result.value),
        events: cloneSessionEvents(sessionEvents.value),
      };
    }

    if (screen.value === 'sessionResult' && sessionResult.value) {
      return {
        variant: 'session',
        title: t('result.sessionTitle'),
        subtitle: t('result.sessionFinished'),
        stats: sessionResult.value.stats,
        answers: sessionResult.value.answers,
        events: sessionResult.value.events,
      };
    }

    return null;
  });

  const sessionAnsweredCount = computed(() => {
    const completedRunAnswers = sessionRuns.value.reduce(
      (total, run) => total + run.stats.answered,
      0,
    );
    const activeRunAnswers =
      screen.value === 'question' && currentQuestion.value
        ? Math.max(0, currentQuestion.value.current - 1)
        : 0;

    return completedRunAnswers + activeRunAnswers;
  });

  const canEndSession = computed(() => sessionOpen.value && sessionAnsweredCount.value > 0);

  const canAdvance = computed(() =>
    Boolean(
      catalog.selectedTopic.value &&
      catalog.selectedTopicAvailable.value &&
      !catalog.loadingCatalog.value &&
      !catalog.loadingSessionTopics.value &&
      !busy.value,
    ),
  );

  const canStart = computed(() =>
    Boolean(
      catalog.selectedTopic.value &&
      catalog.selectedDifficultyInfo.value &&
      catalog.selectedDifficultyAvailable.value &&
      !catalog.loadingSessionDifficulties.value &&
      !busy.value,
    ),
  );

  catalog.setSessionEventRecorder(recordSessionEvent);

  function initializeSession() {
    playQuizSound('appLoaded');
    recordSessionEvent({
      event: 'session.initialized',
      severity: 'info',
      sessionId: getSessionId(),
      message: 'Session initialized in frontend',
      fields: {
        locale: locale.value,
      },
    });
  }

  async function goToDifficulty() {
    playQuizSound('actionNext');
    sessionOpen.value = true;
    sessionResult.value = null;
    recordSessionEvent({
      event: 'session.topic_selected',
      severity: 'info',
      sessionId: getSessionId(),
      message: 'Player selected topic',
      fields: {
        locale: locale.value,
        topic: catalog.selectedTopic.value,
        topicLabel: catalog.selectedTopicLabel.value,
      },
    });
    screen.value = 'difficulty';
    await catalog.refreshDifficultyAvailability();
    catalog.syncSelectedDifficulty();
  }

  async function goToTopicSelection() {
    screen.value = 'start';
    await catalog.refreshTopicAvailability();
    catalog.syncSelectedTopic();
    catalog.syncSelectedDifficulty();
  }

  async function startRun() {
    if (catalog.selectedDifficulty.value === Difficulty.Hardcore) {
      confirmHardcoreRun();
      return;
    }

    await createSelectedRun();
  }

  function confirmHardcoreRun() {
    playQuizSound('severityChanged');
    $q.dialog({
      title: t('confirm.hardcoreTitle'),
      message: t('confirm.hardcoreMessage'),
      cancel: {
        label: t('confirm.cancel'),
        flat: true,
        color: 'white',
      },
      ok: {
        label: t('confirm.hardcoreConfirm'),
        color: 'red-10',
        textColor: 'white',
      },
      persistent: true,
    }).onOk(() => {
      void createSelectedRun();
    });
  }

  async function createSelectedRun() {
    playQuizSound('runStarted');
    busy.value = true;
    errorMessage.value = '';

    try {
      const response = await createRun(
        catalog.selectedTopic.value,
        catalog.selectedDifficulty.value,
      );
      catalog.sessionHasBackendState.value = true;
      sessionCompleted.value = false;
      catalog.sessionTopics.value = null;
      catalog.sessionDifficulties.value = null;
      sessionOpen.value = true;
      runId.value = response.runId;
      currentQuestion.value = response.question;
      result.value = null;
      sessionResult.value = null;
      recordSessionEvent({
        event: 'session.difficulty_selected',
        severity: 'info',
        sessionId: getSessionId(),
        message: 'Player selected severity',
        fields: {
          locale: locale.value,
          topic: catalog.selectedTopic.value,
          difficulty: catalog.selectedDifficulty.value,
          severity: catalog.selectedDifficultyLabel.value,
        },
      });
      recordSessionEvent({
        event: 'run.started',
        severity: 'info',
        sessionId: getSessionId(),
        runId: response.runId,
        message: 'Run started',
        fields: {
          locale: response.locale,
          topic: catalog.selectedTopic.value,
          difficulty: catalog.selectedDifficulty.value,
          severity: catalog.selectedDifficultyLabel.value,
          questionId: response.question.id,
          questionCurrent: response.question.current,
          questionTotal: response.question.total,
        },
      });
      await refreshActiveAvailability();
      screen.value = 'question';
    } catch {
      errorMessage.value = t('errors.startRun');
      await catalog.refreshDifficultyAvailability();
    } finally {
      busy.value = false;
    }
  }

  async function submitAnswer(optionId: string) {
    if (!currentQuestion.value) {
      return;
    }

    busy.value = true;
    errorMessage.value = '';

    try {
      const answeredQuestion = currentQuestion.value;
      const response = await answerQuestion(runId.value, currentQuestion.value.id, optionId);
      const fatalLoss = response.finishReason === 'hardcore_wrong_answer';
      feedback.value = response.correct ? 'correct' : 'wrong';
      if (!fatalLoss) {
        notifyAnswerFeedback(response.correct);
      }
      recordSessionEvent({
        event: 'run.question_answered',
        severity: response.correct ? 'info' : 'warn',
        sessionId: getSessionId(),
        runId: runId.value,
        message: response.correct ? 'Question answered correctly' : 'Question answered incorrectly',
        fields: {
          locale: locale.value,
          topic: catalog.selectedTopic.value,
          difficulty: catalog.selectedDifficulty.value,
          severity: catalog.selectedDifficultyLabel.value,
          questionId: answeredQuestion.id,
          optionId,
          correct: response.correct,
          finished: response.finished,
          finishReason: response.finishReason ?? null,
          questionCurrent: answeredQuestion.current,
          questionTotal: answeredQuestion.total,
        },
      });
      if (fatalLoss) {
        handleFatalHardcoreLoss();
        return;
      }

      await refreshActiveAvailability();
      playQuizSound(response.correct ? 'questionPassed' : 'questionFailed');
      await wait(700);

      if (response.finished) {
        const loaded = await loadRunResult(true);
        if (loaded) {
          playQuizSound('runComplete');
        }
        return;
      }

      currentQuestion.value = response.question ?? null;
      feedback.value = 'idle';
    } catch (error) {
      if (handleRunNotFound(error, 'answer')) {
        return;
      }
      errorMessage.value = t('errors.answer');
      feedback.value = 'idle';
    } finally {
      busy.value = false;
    }
  }

  function handleFatalHardcoreLoss() {
    const lostRunId = runId.value;
    recordSessionEvent({
      event: 'session.fatal_hardcore_loss',
      severity: 'critical',
      sessionId: getSessionId(),
      runId: lostRunId,
      message: 'Hardcore wrong answer reset the backend session',
      fields: {
        locale: locale.value,
        topic: catalog.selectedTopic.value,
        difficulty: catalog.selectedDifficulty.value,
        finishReason: 'hardcore_wrong_answer',
      },
    });
    resetLocalSession();
    requestAdRefresh();
    clearSessionState();
    fatalLossMessageVisible.value = false;
    screen.value = 'fatalLoss';
    window.setTimeout(() => {
      if (screen.value === 'fatalLoss') {
        playQuizSound('fatal');
      }
    }, 180);
    window.setTimeout(() => {
      if (screen.value === 'fatalLoss') {
        fatalLossMessageVisible.value = true;
      }
    }, 1900);
    recordSessionEvent({
      event: 'session.initialized',
      severity: 'info',
      sessionId: getSessionId(),
      message: 'Session initialized after fatal hardcore loss',
      fields: {
        locale: locale.value,
      },
    });
  }

  async function loadRunResult(showResult: boolean): Promise<boolean> {
    let runResult: RunResult;
    try {
      runResult = await getResult(runId.value);
    } catch (error) {
      if (handleRunNotFound(error, 'result')) {
        return false;
      }
      throw error;
    }
    recordRunResult(runResult);
    requestAdRefresh();
    result.value = runResult;
    currentQuestion.value = null;
    feedback.value = 'idle';
    recordSessionEvent({
      event: 'run.completed',
      severity: runResult.finishReason === 'hardcore_wrong_answer' ? 'error' : 'info',
      sessionId: getSessionId(),
      runId: runResult.runId,
      message: 'Run result loaded',
      fields: {
        locale: runResult.locale,
        topic: runResult.topic,
        difficulty: runResult.difficulty,
        finishReason: runResult.finishReason,
        answered: runResult.stats.answered,
        passed: runResult.stats.correct,
        failed: runResult.stats.wrong,
        accuracyPercent: runResult.stats.accuracyPercent,
      },
    });

    if (showResult) {
      sessionCompleted.value = await catalog.refreshSessionCompletion();
      showRunResult();
    }

    return true;
  }

  function recordRunResult(runResult: RunResult) {
    if (sessionRuns.value.some((item) => item.runId === runResult.runId)) {
      return;
    }
    sessionRuns.value = [...sessionRuns.value, runResult];
  }

  function showRunResult() {
    screen.value = 'runResult';

    // advertisingConfig.mobileResultInterstitialEnabled &&
    //   shouldShowResultInterstitial(resultViewCount) &&
  }

  function notifyAnswerFeedback(correct: boolean) {
    $q.notify({
      message: correct ? t('game.correct') : t('game.wrong'),
      caption: 'QQUnit',
      icon: correct ? 'check_circle' : 'cancel',
      color: correct ? 'green-9' : 'red-9',
      textColor: 'white',
      position: 'top',
      timeout: 900,
      group: false,
    });
  }

  async function refreshActiveAvailability() {
    await catalog.refreshTopicAvailability();
    await catalog.refreshDifficultyAvailability();
  }

  async function newRun() {
    playQuizSound('actionNewRun');
    busy.value = true;
    errorMessage.value = '';

    try {
      await catalog.refreshDifficultyAvailability();
      catalog.syncSelectedDifficulty();

      runId.value = '';
      currentQuestion.value = null;
      result.value = null;
      sessionResult.value = null;
      feedback.value = 'idle';
      sessionOpen.value = true;

      if (!catalog.hasAvailableDifficulty.value) {
        await goToTopicSelection();
        return;
      }

      screen.value = 'difficulty';
    } finally {
      busy.value = false;
    }
  }

  function confirmEndSession() {
    playQuizSound('actionEndSession');
    $q.dialog({
      title: t('confirm.sessionResultTitle'),
      message: t('confirm.sessionResultMessage'),
      cancel: {
        label: t('confirm.cancel'),
        flat: true,
        color: 'white',
      },
      ok: {
        label: t('confirm.sessionResultConfirm'),
        color: 'amber-8',
        textColor: 'black',
      },
      persistent: true,
    }).onOk(() => {
      void endSession();
    });
  }

  function endSessionFromAction() {
    playQuizSound('actionEndSession');
    void endSession();
  }

  function handleResultEndSession() {
    if (sessionCompleted.value) {
      endSessionFromAction();
      return;
    }

    confirmEndSession();
  }

  async function endSession() {
    busy.value = true;
    errorMessage.value = '';

    try {
      if (screen.value === 'question' && runId.value) {
        recordSessionEvent({
          event: 'run.abandoned',
          severity: 'warn',
          sessionId: getSessionId(),
          runId: runId.value,
          message: 'Active run finished by session result request',
          fields: {
            locale: locale.value,
            topic: catalog.selectedTopic.value,
            difficulty: catalog.selectedDifficulty.value,
          },
        });
        await finishRun(runId.value);
        const loaded = await loadRunResult(false);
        if (!loaded) {
          return;
        }
      }

      const nextSessionResult = buildSessionResultFromRuns(sessionRuns.value, sessionEvents.value);
      recordSessionEvent({
        event: 'session.result_requested',
        severity: 'info',
        sessionId: getSessionId(),
        message: 'Session result requested',
        fields: {
          runs: sessionRuns.value.length,
          answered: nextSessionResult.stats.answered,
          passed: nextSessionResult.stats.correct,
          failed: nextSessionResult.stats.wrong,
          accuracyPercent: nextSessionResult.stats.accuracyPercent,
        },
      });
      sessionResult.value = {
        ...nextSessionResult,
        events: cloneSessionEvents(sessionEvents.value),
      };
      result.value = null;
      currentQuestion.value = null;
      runId.value = '';
      feedback.value = 'idle';
      screen.value = 'sessionResult';
      sessionOpen.value = false;

      try {
        await resetSession();
        catalog.clearSessionAvailability();
        sessionCompleted.value = false;
      } catch {
        errorMessage.value = t('errors.endSession');
      }
    } catch (error) {
      if (handleRunNotFound(error, 'endSession')) {
        return;
      }
      errorMessage.value = t('errors.endSession');
    } finally {
      busy.value = false;
    }
  }

  function newSession() {
    clearSessionState();
  }

  function clearSessionState() {
    resetResultViewCount();
    runId.value = '';
    currentQuestion.value = null;
    result.value = null;
    sessionResult.value = null;
    sessionRuns.value = [];
    sessionOpen.value = false;
    catalog.clearSessionAvailability();
    sessionCompleted.value = false;
    clearSessionEventLog();
    feedback.value = 'idle';
    fatalLossMessageVisible.value = false;
    screen.value = 'start';
    catalog.syncSelectedDifficulty();
  }

  function recordSessionEvent(input: SessionEventInput) {
    appendSessionEvent(input);
  }

  function handleRunNotFound(error: unknown, operation: RunFlowOperation) {
    if (!isApiErrorCode(error, 'run_not_found')) {
      return false;
    }

    recoverExpiredRun(operation);
    return true;
  }

  function recoverExpiredRun(operation: RunFlowOperation) {
    const expiredRunId = runId.value;
    const expiredSessionId = getSessionId();

    resetLocalSession();
    resetResultViewCount();
    runId.value = '';
    currentQuestion.value = null;
    result.value = null;
    sessionResult.value = null;
    sessionRuns.value = [];
    sessionOpen.value = false;
    sessionCompleted.value = false;
    catalog.clearSessionAvailability();
    feedback.value = 'idle';
    fatalLossMessageVisible.value = false;
    clearSessionEventLog();
    catalog.syncSelectedTopic();
    catalog.syncSelectedDifficulty();
    screen.value = catalog.selectedTopic.value ? 'difficulty' : 'start';
    errorMessage.value = t('errors.runExpired');

    recordSessionEvent({
      event: 'run.expired',
      severity: 'warn',
      sessionId: getSessionId(),
      ...(expiredRunId ? { runId: expiredRunId } : {}),
      message: 'Backend run was not found; frontend session recovered',
      fields: {
        locale: locale.value,
        topic: catalog.selectedTopic.value || null,
        difficulty: catalog.selectedDifficulty.value,
        operation,
        expiredSessionId,
      },
    });
    recordSessionEvent({
      event: 'session.initialized',
      severity: 'info',
      sessionId: getSessionId(),
      message: 'Session initialized after expired run recovery',
      fields: {
        locale: locale.value,
      },
    });
    $q.notify({
      message: t('errors.runExpired'),
      caption: 'QQUnit',
      icon: 'timer_off',
      color: 'amber-9',
      textColor: 'black',
      position: 'top',
      timeout: 1800,
      group: false,
    });
  }

  return {
    screen,
    runId,
    currentQuestion,
    result,
    sessionResult,
    sessionRuns,
    sessionEvents,
    sessionOpen,
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
    recordSessionEvent,
  };
}

function buildSessionResultFromRuns(
  sessionRuns: RunResult[],
  sessionEvents: SessionEvent[],
): ResultSnapshot {
  const answers = sessionRuns.flatMap(resultAnswers);
  const answered = answers.length;
  const correct = answers.filter((answer) => answer.correct).length;
  const wrong = answered - correct;

  return {
    stats: {
      answered,
      correct,
      wrong,
      accuracyPercent: answered === 0 ? 0 : Math.round((correct / answered) * 100),
    },
    answers,
    events: cloneSessionEvents(sessionEvents),
  };
}

function resultAnswers(run: RunResult): ResultAnswer[] {
  return run.answers.map((answer) => ({
    ...answer,
    runId: run.runId,
    topic: run.topic,
    difficulty: run.difficulty,
    locale: run.locale,
  }));
}

function finishReasonLabel(reason: string, t: (key: string) => string) {
  const keys: Record<string, string> = {
    player_quit: 'result.reasons.player_quit',
    max_questions_reached: 'result.reasons.max_questions_reached',
    no_questions_left: 'result.reasons.no_questions_left',
    hardcore_wrong_answer: 'result.reasons.hardcore_wrong_answer',
  };

  return t(keys[reason] ?? 'result.finished');
}

function wait(ms: number) {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}
