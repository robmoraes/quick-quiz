import { afterEach, beforeEach, expect, it, vi } from 'vitest';

beforeEach(() => {
  const session = new Map<string, string>();
  vi.stubGlobal('window', {
    __QUICKQUIZ_CONFIG__: { apiBaseUrl: 'http://quiz.test', adsApiBaseUrl: 'http://ads.test' },
    sessionStorage: {
      getItem: (key: string) => session.get(key),
      setItem: (key: string, value: string) => session.set(key, value),
    },
    localStorage: { getItem: () => null },
    navigator: { language: 'en-US' },
    crypto: { randomUUID: () => 'test-session' },
  });
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve(new Response(JSON.stringify({ themes: [] }), { status: 200 }))),
  );
});
afterEach(() => vi.unstubAllGlobals());

it('discovers without theme/session headers and keeps every quiz operation explicitly scoped', async () => {
  const api = await import('./api');
  await api.getThemes('dev');
  expect(fetch).toHaveBeenLastCalledWith(
    'http://quiz.test/api/themes?tag=dev',
    expect.objectContaining({
      headers: { 'Content-Type': 'application/json' },
    }),
  );
  const actions = [
    () => api.getCatalog('kubernetes'),
    () => api.getSessionTopics('kubernetes'),
    () => api.getSessionDifficulties('kubernetes', 'pods'),
    () => api.createRun('kubernetes', 'pods', api.Difficulty.Easy),
    () => api.answerQuestion('kubernetes', 'run-1', 'q1', 'o1'),
    () => api.getRunState('kubernetes', 'run-1'),
    () => api.getResult('kubernetes', 'run-1'),
    () => api.getQuestionSolution('kubernetes', 'run-1', 'q1'),
    () => api.finishRun('kubernetes', 'run-1'),
    () => api.resetSession('kubernetes'),
  ];
  for (const action of actions) {
    await action();
    expect(fetch).toHaveBeenLastCalledWith(
      expect.stringMatching(/^http:\/\/quiz.test\//),
      expect.objectContaining({
        headers: expect.objectContaining({
          'X-QuickQuiz-Theme': 'kubernetes',
          'X-QuickQuiz-Session-ID': 'test-session',
        }),
      }),
    );
  }
  await api.getAds(3, 2);
  expect(fetch).toHaveBeenLastCalledWith(
    'http://ads.test/api/ads?limit=3&emphasis=2',
    expect.objectContaining({
      headers: expect.objectContaining({ 'X-QuickQuiz-Theme': 'dev' }),
    }),
  );
});
