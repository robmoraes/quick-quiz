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
