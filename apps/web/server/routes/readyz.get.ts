import { parseWebSettings } from '#shared/config'

export default defineEventHandler(async event => {
  const settings = parseWebSettings(useRuntimeConfig(event))
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), settings.apiTimeoutMs)
  const exclusions: Record<string, string> = {}
  if (settings.excludedThemeIds.length) exclusions.excludeThemes = settings.excludedThemeIds.join(',')
  try {
    const responses = await Promise.all([
      fetch(`${settings.apiBaseUrl}/readyz`, { signal: controller.signal, cache: 'no-store' }),
      ...settings.supportedLocales.map(locale => fetch(`${settings.apiBaseUrl}/api/discovery/themes?${new URLSearchParams({ locale, pageSize: '1', ...exclusions })}`, { signal: controller.signal, cache: 'no-store' })),
    ])
    if (responses.some(response => !response.ok)) throw new Error('Upstream unavailable')
    for (const response of responses.slice(1)) {
      const body = await response.json() as { themes?: unknown; locale?: unknown }
      if (!Array.isArray(body.themes) || typeof body.locale !== 'string') throw new Error('Invalid discovery contract')
    }
    return { status: 'ready' }
  } catch {
    setResponseStatus(event, 503)
    return { status: 'unavailable' }
  } finally { clearTimeout(timer) }
})
