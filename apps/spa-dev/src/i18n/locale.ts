export const fallbackLocale = 'en-US';

const supportedLocales = ['en-US', 'pt-BR'] as const;
const localePreferenceKey = 'quickquiz.localePreference';

export type SupportedLocale = (typeof supportedLocales)[number];
export type LocalePreference = 'browser' | SupportedLocale;

export function getActiveLocale(): SupportedLocale {
  const preference = getLocalePreference();
  if (preference !== 'browser') {
    return preference;
  }

  const locale = normalizeLocale(window.navigator.language);
  return isSupportedLocale(locale) ? locale : fallbackLocale;
}

export function getLocalePreference(): LocalePreference {
  const preference = window.localStorage.getItem(localePreferenceKey);
  return isLocalePreference(preference) ? preference : 'browser';
}

export function setLocalePreference(preference: LocalePreference) {
  window.localStorage.setItem(localePreferenceKey, preference);
}

function normalizeLocale(locale: string) {
  const [language = '', region = ''] = locale.replace('_', '-').split('-');
  if (!language) {
    return '';
  }
  return region ? `${language.toLowerCase()}-${region.toUpperCase()}` : language.toLowerCase();
}

function isSupportedLocale(locale: string): locale is SupportedLocale {
  return supportedLocales.includes(locale as SupportedLocale);
}

function isLocalePreference(preference: string | null): preference is LocalePreference {
  return preference === 'browser' || isSupportedLocale(preference ?? '');
}
