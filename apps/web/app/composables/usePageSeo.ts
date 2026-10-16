import { parsePublicSettings } from '#shared/config'

export function usePageSeo(title: MaybeRefOrGetter<string>, description: MaybeRefOrGetter<string>, playable: MaybeRefOrGetter<boolean> = true, indexablePage = true) {
  const route = useRoute()
  const { locale, supportedLocales } = useWebLocale()
  const settings = parsePublicSettings(useRuntimeConfig().public)
  const canonicalPath = computed(() => route.path.replace(/\/$/, '') || '/')
  useHead(() => ({
    htmlAttrs: { lang: locale.value },
    title: `${toValue(title)} · QuickQuiz`,
    meta: [
      { name: 'description', content: toValue(description) },
      { name: 'robots', content: settings.indexable && indexablePage && toValue(playable) && !Object.keys(route.query).length ? 'index,follow' : 'noindex,follow' },
      { property: 'og:title', content: toValue(title) },
      { property: 'og:description', content: toValue(description) },
      { property: 'og:type', content: 'website' },
      { property: 'og:url', content: `${settings.siteUrl}${canonicalPath.value}` },
    ],
    link: [
      { rel: 'canonical', href: `${settings.siteUrl}${canonicalPath.value}` },
      ...supportedLocales.map(value => ({ rel: 'alternate' as const, type: 'text/html', hreflang: value, href: `${settings.siteUrl}${canonicalPath.value.replace(/^\/(pt-BR|en-US)/, `/${value}`)}` })),
    ],
  }))
}
