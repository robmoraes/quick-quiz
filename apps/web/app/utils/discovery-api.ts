import type { DiscoveryQuery, TagPage, ThemeDetail, ThemePage, TopicDetail, TopicPage } from '../types/discovery'

export interface DiscoveryFailure extends Error { status: number; code: string }
export type DiscoveryFetch = (url: string, options: { headers: Record<string, string>; signal: AbortSignal; cache: 'no-store' }) => Promise<Response>

export function createDiscoveryClient(baseUrl: string, locale: string, fetcher: DiscoveryFetch = fetch, timeoutMs = 5000, parentSignal?: AbortSignal) {
  async function request<T>(path: string, query: Record<string, string | number | undefined> = {}): Promise<T> {
    const params = new URLSearchParams({ locale })
    for (const [key, value] of Object.entries(query)) if (value !== undefined) params.set(key, String(value))
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), timeoutMs)
    try {
      const response = await fetcher(`${baseUrl.replace(/\/$/, '')}/api/discovery/${path}?${params}`, {
        headers: { Accept: 'application/json' }, signal: parentSignal ? AbortSignal.any([controller.signal, parentSignal]) : controller.signal, cache: 'no-store',
      })
      if (!response.ok) {
        const error = new Error('Discovery request failed') as DiscoveryFailure
        error.status = response.status
        error.code = 'discovery_failed'
        try { error.code = (await response.json() as { error?: { code?: string } }).error?.code ?? error.code } catch { /* Preserve safe error. */ }
        throw error
      }
      return await response.json() as T
    } catch (cause) {
      if (cause instanceof Error && 'status' in cause) throw cause
      const error = new Error('Discovery is temporarily unavailable') as DiscoveryFailure
      error.status = 503
      error.code = 'storage_unavailable'
      throw error
    } finally { clearTimeout(timer) }
  }
  return {
    themes: (query: Omit<DiscoveryQuery, 'theme'> = {}) => request<ThemePage>('themes', { ...query }),
    theme: (theme: string) => request<ThemeDetail>(`themes/${encodeURIComponent(theme)}`),
    topics: (query: DiscoveryQuery = {}) => request<TopicPage>('topics', { ...query }),
    topic: (theme: string, topic: string) => request<TopicDetail>(`themes/${encodeURIComponent(theme)}/topics/${encodeURIComponent(topic)}`),
    tags: (scope: 'theme' | 'topic', query: DiscoveryQuery = {}) => request<TagPage>('tags', { scope, ...query }),
  }
}
