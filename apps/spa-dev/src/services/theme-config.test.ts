import { afterEach, describe, expect, it, vi } from 'vitest';
import { configuredThemeTag } from './theme-config';

afterEach(() => vi.unstubAllGlobals());

describe('runtime audience tag', () => {
  it('defaults only when absent and normalizes ASCII input', () => {
    vi.stubGlobal('window', {});
    expect(configuredThemeTag()).toBe('dev');
    vi.stubGlobal('window', { __QUICKQUIZ_CONFIG__: { themeTag: ' \tDEV\n' } });
    expect(configuredThemeTag()).toBe('dev');
  });

  it.each([
    '',
    ' ',
    'dev ops',
    'dev,arts',
    '-dev',
    'dev--ops',
    'á',
    'Kubernetes',
    'a'.repeat(51),
    null,
  ])('rejects invalid config %j instead of widening scope', (themeTag) => {
    vi.stubGlobal('window', { __QUICKQUIZ_CONFIG__: { themeTag } });
    expect(configuredThemeTag).toThrow();
  });

  it('does not read URL or stored audience overrides', () => {
    vi.stubGlobal('window', {
      __QUICKQUIZ_CONFIG__: { themeTag: 'dev' },
      location: { search: '?tag=arts&theme=literature' },
      localStorage: { getItem: () => 'arts' },
    });
    expect(configuredThemeTag()).toBe('dev');
  });
});
