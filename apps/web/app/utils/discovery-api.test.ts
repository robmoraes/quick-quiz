import { describe, expect, it, vi } from 'vitest'
import { createDiscoveryClient } from './discovery-api'

describe('public discovery client', () => {
  it('sends only explicit metadata queries and no player context', async () => {
    const fetcher = vi.fn(async () => new Response(JSON.stringify({ themes: [] }), { status: 200 }))
    await createDiscoveryClient('http://api:8080/', 'pt-BR', fetcher).themes({ themeTag: 'dev', q: 'a%b', page: 2 })
    const [url, options] = fetcher.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('http://api:8080/api/discovery/themes?locale=pt-BR&themeTag=dev&q=a%25b&page=2')
    expect(options.headers).toEqual({ Accept: 'application/json' })
    expect(options.cache).toBe('no-store')
    expect(options.method).toBeUndefined()
  })
  it('preserves safe API status/code while discarding raw infrastructure messages', async () => {
    const client = createDiscoveryClient('http://api:8080', 'en-US', async () => new Response(JSON.stringify({ error: { code: 'topic_not_found', message: 'private connection data' } }), { status: 404 }))
    await expect(client.topic('dev', 'absent')).rejects.toMatchObject({ status: 404, code: 'topic_not_found', message: 'Discovery request failed' })
  })
  it('applies the fixed site exclusions to lists details and tags without replacing user filters', async () => {
    const fetcher = vi.fn(async () => new Response('{}', { status: 200 }))
    const client = createDiscoveryClient('http://api:8080', 'pt-BR', fetcher, 5000, undefined, ['dev', 'dslab'])
    await client.themes({ themeTag: 'it', page: 2 })
    await client.topics({ topicTag: 'memory' })
    await client.tags('topic')
    await client.theme('php')
    await client.topic('php', 'basics')
    expect(fetcher).toHaveBeenCalledTimes(5)
    for (const [url] of fetcher.mock.calls as unknown as [string][]) {
      expect(new URL(url).searchParams.get('excludeThemes')).toBe('dev,dslab')
    }
    const first = new URL((fetcher.mock.calls[0] as unknown as [string])[0])
    expect(first.searchParams.get('themeTag')).toBe('it')
    expect(first.searchParams.get('page')).toBe('2')
  })
  it('bounds the complete response read and respects a shared operation deadline', async () => {
    const parent = new AbortController()
    const fetcher = vi.fn(async (_url: string, options: { signal: AbortSignal }) => new Promise<Response>((_resolve, reject) => {
      options.signal.addEventListener('abort', () => reject(new Error('aborted')), { once: true })
    }))
    const request = createDiscoveryClient('http://api:8080', 'pt-BR', fetcher, 1000, parent.signal).themes()
    parent.abort()
    await expect(request).rejects.toMatchObject({ status: 503, code: 'storage_unavailable' })
  })
})
