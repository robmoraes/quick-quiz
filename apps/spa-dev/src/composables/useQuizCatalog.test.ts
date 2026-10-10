import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { effectScope, ref, type EffectScope } from 'vue';
import {
  getCatalog,
  getSessionTopics,
  getSessionDifficulties,
  type Catalog,
  type SessionTopics,
  type SessionDifficulties,
} from 'src/services/api';
import { useQuizCatalog } from './useQuizCatalog';

vi.mock('src/services/api', () => ({
  Difficulty: { Easy: 1, Normal: 2, Hard: 3, Hardcore: 4 },
  getSessionId: () => 'test-session',
  getCatalog: vi.fn(),
  getSessionTopics: vi.fn(),
  getSessionDifficulties: vi.fn(),
}));
vi.mock('src/services/sounds', () => ({ playQuizSound: vi.fn() }));
const locale = ref('en-US');
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale }) }));
const scopes: EffectScope[] = [];
const catalogData = (theme: string, name = theme): Catalog => ({
  theme,
  locale: 'en-US',
  fallbackLocale: 'en-US',
  difficulties: [],
  topics: [{ id: 'shared', label: name, weight: 10, difficulties: [] }],
});
function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((done) => {
    resolve = done;
  });
  return { promise, resolve };
}
function setup() {
  const theme = ref('dev');
  const scope = effectScope();
  scopes.push(scope);
  const state = scope.run(() => useQuizCatalog(theme));
  if (!state) throw new Error('Missing effect scope');
  return { theme, state };
}
beforeEach(() => {
  vi.clearAllMocks();
  locale.value = 'en-US';
});
afterEach(() => {
  scopes.splice(0).forEach((scope) => scope.stop());
});

it('discards an old theme catalog even when topic IDs are shared', async () => {
  const old = deferred<Catalog>();
  vi.mocked(getCatalog)
    .mockReturnValueOnce(old.promise)
    .mockResolvedValueOnce(catalogData('kubernetes'));
  const { theme, state } = setup();
  state.selectedTopic.value = 'shared';
  const pending = state.loadCatalog();
  theme.value = 'kubernetes';
  state.resetForTheme();
  await state.loadCatalog();
  old.resolve(catalogData('dev'));
  expect(await pending).toBe(false);
  expect(state.catalog.value.theme).toBe('kubernetes');
  expect(state.selectedTopic.value).toBe('');
  expect(state.loadingCatalog.value).toBe(false);
  expect(getCatalog).toHaveBeenLastCalledWith('kubernetes');
});

it('discards responses from an earlier locale or an older request in the same context', async () => {
  const old = deferred<Catalog>();
  const { state } = setup();
  vi.mocked(getCatalog)
    .mockReturnValueOnce(old.promise)
    .mockResolvedValueOnce(catalogData('dev', 'Novo'));
  const pending = state.loadCatalog();
  locale.value = 'pt-BR';
  await state.loadCatalog();
  old.resolve(catalogData('dev', 'Old'));
  await pending;
  expect(state.catalog.value.topics[0]?.label).toBe('Novo');
});

it('does not restore old availability after a theme/session reset', async () => {
  const old = deferred<SessionTopics>();
  vi.mocked(getSessionTopics).mockReturnValue(old.promise);
  const { state, theme } = setup();
  state.sessionHasBackendState.value = true;
  const pending = state.refreshTopicAvailability();
  theme.value = 'kubernetes';
  state.resetForTheme();
  old.resolve({ ...catalogData('dev'), topics: [] });
  await pending;
  expect(state.sessionTopics.value).toBeNull();
  expect(state.sessionHasBackendState.value).toBe(false);
  expect(state.loadingSessionTopics.value).toBe(false);
});

it('does not apply difficulties fetched for a previous topic', async () => {
  const old = deferred<SessionDifficulties>();
  vi.mocked(getSessionDifficulties)
    .mockReturnValueOnce(old.promise)
    .mockResolvedValueOnce({ theme: 'dev', locale: 'en-US', topic: 'new', difficulties: [] });
  const { state } = setup();
  state.sessionHasBackendState.value = true;
  state.selectedTopic.value = 'old';
  const pending = state.refreshDifficultyAvailability();
  state.selectedTopic.value = 'new';
  await state.refreshDifficultyAvailability();
  old.resolve({ theme: 'dev', locale: 'en-US', topic: 'old', difficulties: [] });
  await pending;
  expect(state.sessionDifficulties.value?.topic).toBe('new');
});

it('clears pending difficulty loading when its topic is cleared', async () => {
  const old = deferred<SessionDifficulties>();
  vi.mocked(getSessionDifficulties).mockReturnValueOnce(old.promise);
  const { state } = setup();
  state.sessionHasBackendState.value = true;
  state.selectedTopic.value = 'old';
  const pending = state.refreshDifficultyAvailability();
  expect(state.loadingSessionDifficulties.value).toBe(true);
  state.clearSelectedTopic();
  expect(state.loadingSessionDifficulties.value).toBe(false);
  old.resolve({ theme: 'dev', locale: 'en-US', topic: 'old', difficulties: [] });
  await pending;
  expect(state.sessionDifficulties.value).toBeNull();
});

it('clears topic loading when a completion check supersedes an availability request', async () => {
  const old = deferred<SessionTopics>();
  vi.mocked(getSessionTopics)
    .mockReturnValueOnce(old.promise)
    .mockResolvedValueOnce({ ...catalogData('dev'), topics: [] });
  const { state } = setup();
  state.sessionHasBackendState.value = true;
  const pending = state.refreshTopicAvailability();
  expect(await state.refreshSessionCompletion()).toBe(true);
  expect(state.loadingSessionTopics.value).toBe(false);
  old.resolve({ ...catalogData('dev'), topics: [] });
  await pending;
  expect(state.loadingSessionTopics.value).toBe(false);
});
