import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { effectScope, ref, watch, type EffectScope } from 'vue';
import type * as VueModule from 'vue';
import {
  answerQuestion,
  createRun,
  finishRun,
  getResult,
  getRunState,
  getSessionTopics,
  resetSession,
  Difficulty,
  type RunResult,
} from 'src/services/api';
import { clearSessionEventLog } from 'src/services/session-events';
import { useQuizCatalog } from './useQuizCatalog';
import { useQuizRun } from './useQuizRun';

const quasar = vi.hoisted(() => ({ dialog: vi.fn(), notify: vi.fn() }));
vi.mock('quasar', () => ({ useQuasar: () => quasar }));
vi.mock('vue', async (importOriginal) => ({
  ...(await importOriginal<typeof VueModule>()),
  onMounted: vi.fn(),
  onUnmounted: vi.fn(),
}));
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: ref('en-US') }),
}));
vi.mock('src/services/api', () => ({
  Difficulty: { Easy: 1, Normal: 2, Hard: 3, Hardcore: 4 },
  getSessionId: () => 'test-session',
  isApiErrorCode: () => false,
  getCatalog: vi.fn(),
  getSessionTopics: vi.fn(),
  getSessionDifficulties: vi.fn(),
  createRun: vi.fn(),
  answerQuestion: vi.fn(),
  getResult: vi.fn(),
  getRunState: vi.fn(),
  finishRun: vi.fn(),
  resetSession: vi.fn(),
  resetLocalSession: vi.fn(),
}));
vi.mock('src/services/sounds', () => ({ playQuizSound: vi.fn() }));
vi.mock('src/services/ad-refresh', () => ({ requestAdRefresh: vi.fn() }));

const scopes: EffectScope[] = [];

beforeEach(() => {
  vi.resetAllMocks();
  vi.useFakeTimers();
  vi.stubGlobal('window', {
    location: { host: 'localhost' },
    crypto: globalThis.crypto,
    setTimeout: globalThis.setTimeout,
    setInterval: globalThis.setInterval,
    clearInterval: globalThis.clearInterval,
  });
  clearSessionEventLog();
  vi.mocked(resetSession).mockResolvedValue();
  vi.mocked(finishRun).mockResolvedValue();
});

afterEach(() => {
  scopes.splice(0).forEach((scope) => scope.stop());
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

function setup() {
  const scope = effectScope();
  scopes.push(scope);
  const state = scope.run(() => {
    const catalog = useQuizCatalog(ref('kubernetes'));
    const difficulty = { id: Difficulty.Easy, optionCount: 3, questionCount: 2, hardcore: false };
    catalog.catalog.value = {
      theme: 'kubernetes',
      locale: 'en-US',
      fallbackLocale: 'en-US',
      topics: [{ id: 'basics', label: 'Basics', weight: 1, difficulties: [difficulty] }],
      difficulties: [difficulty],
    };
    catalog.selectedTopic.value = 'basics';
    vi.spyOn(catalog, 'refreshTopicAvailability').mockResolvedValue();
    vi.spyOn(catalog, 'refreshDifficultyAvailability').mockResolvedValue();
    const completion = vi.spyOn(catalog, 'refreshSessionCompletion').mockResolvedValue(true);
    const flow = useQuizRun({ catalog });
    const screens: string[] = [];
    watch(flow.screen, (screen) => screens.push(screen), { flush: 'sync' });
    return { catalog, flow, completion, screens };
  });
  if (!state) throw new Error('Missing effect scope');
  return state;
}

function prepareRun(id: string, correct = true): RunResult {
  const question = {
    id: `${id}-question`,
    prompt: 'Synthetic question',
    options: [{ id: 'option', text: 'Answer' }],
    current: 1,
    total: 1,
  };
  vi.mocked(createRun).mockResolvedValue({
    runId: id,
    theme: 'kubernetes',
    locale: 'en-US',
    question,
  });
  vi.mocked(answerQuestion).mockResolvedValue({
    correct,
    finished: true,
    finishReason: 'no_questions_left',
  });
  const result: RunResult = {
    runId: id,
    theme: 'kubernetes',
    locale: 'en-US',
    topic: 'basics',
    difficulty: Difficulty.Easy,
    finishReason: 'no_questions_left',
    stats: {
      answered: 1,
      correct: correct ? 1 : 0,
      wrong: correct ? 0 : 1,
      accuracyPercent: correct ? 100 : 0,
    },
    answers: [{ questionId: question.id, prompt: question.prompt, correct }],
  };
  vi.mocked(getResult).mockResolvedValue(result);
  return result;
}

async function answerLastQuestion(flow: ReturnType<typeof useQuizRun>) {
  const pending = flow.submitAnswer('option');
  await vi.advanceTimersByTimeAsync(700);
  await pending;
}

it('opens the consolidated session result directly when the final answer exhausts the session', async () => {
  const { flow, screens } = setup();
  prepareRun('last');
  await flow.startRun();
  await answerLastQuestion(flow);
  expect(screens).toEqual(['question', 'sessionResult']);
  expect(flow.displayedResult.value?.variant).toBe('session');
  expect(flow.displayedResult.value?.stats.answered).toBe(1);
  expect(flow.sessionOpen.value).toBe(false);
  expect(flow.canEndSession.value).toBe(false);
  expect(resetSession).toHaveBeenCalledExactlyOnceWith('kubernetes');
  expect(finishRun).not.toHaveBeenCalled();
  expect(getRunState).not.toHaveBeenCalled();
  expect(quasar.dialog).not.toHaveBeenCalled();
});

it('keeps the session open while questions remain and consolidates every run when exhausted', async () => {
  const { flow, completion } = setup();
  completion.mockResolvedValueOnce(false).mockResolvedValueOnce(true);
  prepareRun('first');
  await flow.startRun();
  await answerLastQuestion(flow);
  expect(flow.screen.value).toBe('runResult');
  expect(flow.sessionOpen.value).toBe(true);
  expect(resetSession).not.toHaveBeenCalled();
  await flow.newRun();
  prepareRun('last', false);
  await flow.startRun();
  await answerLastQuestion(flow);
  expect(flow.screen.value).toBe('sessionResult');
  expect(flow.displayedResult.value?.stats).toEqual({
    answered: 2,
    correct: 1,
    wrong: 1,
    accuracyPercent: 50,
  });
  expect(flow.displayedResult.value?.answers.map((answer) => [answer.runId, answer.theme])).toEqual(
    [
      ['first', 'kubernetes'],
      ['last', 'kubernetes'],
    ],
  );
  expect(resetSession).toHaveBeenCalledTimes(1);
});

it('also closes an exhausted session when polling discovers the completed run', async () => {
  const { flow } = setup();
  const result = prepareRun('polled');
  vi.mocked(getRunState).mockResolvedValue({
    ...result,
    status: 'finished',
    finished: true,
    answered: 1,
    total: 1,
  });
  await flow.startRun();
  await vi.advanceTimersByTimeAsync(15000);
  expect(flow.screen.value).toBe('sessionResult');
  expect(flow.sessionOpen.value).toBe(false);
  expect(resetSession).toHaveBeenCalledExactlyOnceWith('kubernetes');
});

it('does not interpret an availability request failure as an exhausted session', async () => {
  const { flow, completion } = setup();
  completion.mockRestore();
  vi.mocked(getSessionTopics).mockRejectedValue(new Error('Unavailable'));
  prepareRun('last');
  await flow.startRun();
  await answerLastQuestion(flow);
  expect(flow.screen.value).toBe('runResult');
  expect(flow.sessionOpen.value).toBe(true);
  expect(flow.errorMessage.value).toBe('errors.topics');
  expect(resetSession).not.toHaveBeenCalled();
});

it('preserves the consolidated result if resetting the backend fails', async () => {
  const { flow } = setup();
  prepareRun('last');
  vi.mocked(resetSession).mockRejectedValue(new Error('Unavailable'));
  await flow.startRun();
  await answerLastQuestion(flow);
  expect(flow.screen.value).toBe('sessionResult');
  expect(flow.displayedResult.value?.answers).toHaveLength(1);
  expect(flow.errorMessage.value).toBe('errors.endSession');
  expect(flow.busy.value).toBe(false);
});

it('waits for session reset before allowing the existing new-session action', async () => {
  const { flow } = setup();
  prepareRun('last');
  let resolveReset!: () => void;
  vi.mocked(resetSession).mockReturnValue(
    new Promise<void>((resolve) => {
      resolveReset = resolve;
    }),
  );
  await flow.startRun();
  const pending = flow.submitAnswer('option');
  await vi.advanceTimersByTimeAsync(700);
  expect(flow.screen.value).toBe('sessionResult');
  expect(flow.busy.value).toBe(true);
  flow.newSession();
  expect(flow.screen.value).toBe('sessionResult');
  resolveReset();
  await pending;
  flow.newSession();
  expect(flow.screen.value).toBe('start');
  expect(flow.sessionRuns.value).toEqual([]);
});

it('still confirms and finishes an active run when the player ends the session early', async () => {
  const { flow } = setup();
  prepareRun('active');
  let confirm!: () => void;
  quasar.dialog.mockReturnValue({
    onOk: (callback: () => void) => {
      confirm = callback;
    },
  });
  await flow.startRun();
  flow.confirmEndSession();
  expect(finishRun).not.toHaveBeenCalled();
  confirm();
  await vi.advanceTimersByTimeAsync(0);
  expect(finishRun).toHaveBeenCalledExactlyOnceWith('kubernetes', 'active');
  expect(flow.screen.value).toBe('sessionResult');
  expect(flow.displayedResult.value?.answers).toHaveLength(1);
  expect(resetSession).toHaveBeenCalledExactlyOnceWith('kubernetes');
});
