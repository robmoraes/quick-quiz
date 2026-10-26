import { parsePublicSettings } from '#shared/config'

export default defineNuxtRouteMiddleware(to => {
  if (!to.params.locale) return
  const settings = parsePublicSettings(useRuntimeConfig().public)
  if (!settings.supportedLocales.includes(to.params.locale as 'pt-BR' | 'en-US')) {
    throw createError({ statusCode: 404, statusMessage: 'Page not found' })
  }
  const theme = to.params.theme ?? to.query.theme
  if (typeof theme === 'string' && settings.excludedThemeIds.includes(theme)) {
    throw createError({ statusCode: 404, statusMessage: 'Page not found' })
  }
})
