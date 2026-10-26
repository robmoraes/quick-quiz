export const webLocales = ['pt-BR', 'en-US'] as const
export type WebLocale = typeof webLocales[number]

export interface WebSettings {
  apiBaseUrl: string
  browserApiBaseUrl: string
  siteUrl: string
  defaultLocale: WebLocale
  supportedLocales: WebLocale[]
  indexable: boolean
  apiTimeoutMs: number
  excludedThemeIds: string[]
}

export function parseExcludedThemeIds(value: unknown): string[] {
  if (value === undefined || value === '') return []
  if (typeof value !== 'string') throw new Error('Invalid Web configuration: excludedThemeIds')
  if (!value.trim()) return []
  const ids = value.split(',').map(id => id.trim())
  if (ids.length > 50 || new Set(ids).size !== ids.length || ids.some(id => id.length > 128 || !/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(id))) {
    throw new Error('Invalid Web configuration: excludedThemeIds')
  }
  return ids
}

function origin(value: unknown, key: string): string {
  if (typeof value !== 'string' || !value.trim()) throw new Error(`Invalid Web configuration: ${key}`)
  let url: URL
  try { url = new URL(value.trim()) } catch { throw new Error(`Invalid Web configuration: ${key}`) }
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash || url.pathname !== '/') {
    throw new Error(`Invalid Web configuration: ${key}`)
  }
  return url.origin
}

function flag(value: unknown): boolean {
  if (value === true || value === 'true') return true
  if (value === false || value === 'false') return false
  throw new Error('Invalid Web configuration: indexable')
}

export function parseWebSettings(config: {
  apiBaseUrl: unknown
  apiTimeoutMs: unknown
  public: { apiBaseUrl: unknown; siteUrl: unknown; defaultLocale: unknown; supportedLocales: unknown; indexable: unknown; excludedThemeIds?: unknown }
}): WebSettings {
  const p = config.public
  const supported = typeof p.supportedLocales === 'string' ? p.supportedLocales.split(',').map(value => value.trim()) : []
  if (!supported.length || new Set(supported).size !== supported.length || supported.some(value => !webLocales.includes(value as WebLocale))) {
    throw new Error('Invalid Web configuration: supportedLocales')
  }
  if (!supported.includes(String(p.defaultLocale))) throw new Error('Invalid Web configuration: defaultLocale')
  const timeout = Number(config.apiTimeoutMs)
  if (!Number.isInteger(timeout) || timeout < 100 || timeout > 30000) throw new Error('Invalid Web configuration: apiTimeoutMs')
  return {
    apiBaseUrl: origin(config.apiBaseUrl, 'apiBaseUrl'),
    browserApiBaseUrl: origin(p.apiBaseUrl, 'public.apiBaseUrl'),
    siteUrl: origin(p.siteUrl, 'siteUrl'),
    defaultLocale: p.defaultLocale as WebLocale,
    supportedLocales: supported as WebLocale[],
    indexable: flag(p.indexable),
    apiTimeoutMs: timeout,
    excludedThemeIds: parseExcludedThemeIds(p.excludedThemeIds),
  }
}

export function parsePublicSettings(p: { apiBaseUrl: unknown; siteUrl: unknown; defaultLocale: unknown; supportedLocales: unknown; indexable: unknown; excludedThemeIds?: unknown }) {
  const settings = parseWebSettings({ apiBaseUrl: p.apiBaseUrl, apiTimeoutMs: 5000, public: p })
  return {
    apiBaseUrl: settings.browserApiBaseUrl,
    siteUrl: settings.siteUrl,
    defaultLocale: settings.defaultLocale,
    supportedLocales: settings.supportedLocales,
    indexable: settings.indexable,
    excludedThemeIds: settings.excludedThemeIds,
  }
}
