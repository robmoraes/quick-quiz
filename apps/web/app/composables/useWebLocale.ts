import { parsePublicSettings, type WebLocale } from '#shared/config'
import { countLabel, message, tagLabel, type MessageKey } from '../utils/messages'

export function useWebLocale() {
  const route = useRoute()
  const settings = parsePublicSettings(useRuntimeConfig().public)
  const locale = computed<WebLocale>(() => settings.supportedLocales.includes(route.params.locale as WebLocale)
    ? route.params.locale as WebLocale : settings.defaultLocale)
  return {
    locale,
    supportedLocales: settings.supportedLocales,
    t: (key: MessageKey) => message(locale.value, key),
    labelTag: (slug: string) => tagLabel(slug, locale.value),
    localPath: (path = '/') => `/${locale.value}${path.startsWith('/') ? path : `/${path}`}`,
    number: (value: number) => new Intl.NumberFormat(locale.value).format(value),
    countLabel: (count: number, kind: 'questions' | 'topics') => countLabel(locale.value, count, kind),
  }
}
