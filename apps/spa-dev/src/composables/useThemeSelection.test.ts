import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { getThemes, type Theme } from 'src/services/api';
import { useThemeSelection } from './useThemeSelection';

vi.mock('src/services/api', () => ({ getThemes: vi.fn() }));
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }));
const theme = (id: string, tags = ['dev'], active = true): Theme => ({
  id,
  name: id,
  description: '',
  weight: 10,
  tags,
  active,
});

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal('window', { __QUICKQUIZ_CONFIG__: { themeTag: 'dev' } });
});
afterEach(() => vi.unstubAllGlobals());

it('defaults to dev, restricts choices, and refuses changes during an open session', async () => {
  vi.mocked(getThemes).mockResolvedValue([
    theme('kubernetes'),
    theme('dev'),
    theme('literature', ['arts']),
    theme('draft', ['dev'], false),
  ]);
  const selected = ref('');
  const locked = ref(false);
  const state = useThemeSelection(selected, locked);
  expect(await state.loadThemes()).toBe(true);
  expect(selected.value).toBe('dev');
  expect(state.themes.value.map((item) => item.id)).toEqual(['kubernetes', 'dev']);
  expect(state.selectTheme('literature')).toBe(false);
  expect(state.selectTheme('kubernetes')).toBe(true);
  locked.value = true;
  expect(state.selectTheme('dev')).toBe(false);
  expect(await state.loadThemes()).toBe(false);
  expect(selected.value).toBe('kubernetes');
  locked.value = false;
  expect(state.selectTheme('dev')).toBe(true);
});

it('uses the first eligible theme when dev is absent; empty/error never falls back', async () => {
  const selected = ref('');
  const state = useThemeSelection(selected, ref(false));
  vi.mocked(getThemes).mockResolvedValue([theme('kubernetes')]);
  await state.loadThemes();
  expect(selected.value).toBe('kubernetes');
  vi.mocked(getThemes).mockResolvedValue([]);
  expect(await state.loadThemes()).toBe(false);
  expect(selected.value).toBe('');
  expect(state.themeError.value).toBe('');
  vi.mocked(getThemes).mockRejectedValue(new Error('unavailable'));
  expect(await state.loadThemes()).toBe(false);
  expect(state.themeError.value).toBe('themes.loadError');
  expect(getThemes).toHaveBeenCalledTimes(3);
  expect(getThemes).toHaveBeenLastCalledWith('dev');
});

it('does not request discovery with invalid configuration', async () => {
  vi.stubGlobal('window', { __QUICKQUIZ_CONFIG__: { themeTag: '' } });
  const state = useThemeSelection(ref(''), ref(false));
  expect(await state.loadThemes()).toBe(false);
  expect(state.themeError.value).toBe('themes.configurationError');
  expect(getThemes).not.toHaveBeenCalled();
});
