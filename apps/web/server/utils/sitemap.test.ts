import { describe, expect, it, vi } from 'vitest'
import type { createDiscoveryClient } from '../../app/utils/discovery-api'
import { collectSitemapChunk, parseSitemapChunk, SitemapCapacityError, sitemapDocument } from './sitemap'

function apiFor(totalPages: number, calls: number[], concurrency: { current: number; max: number }) {
  return { topics: vi.fn(async ({ page }: { page: number }) => {
    calls.push(page); concurrency.current++; concurrency.max = Math.max(concurrency.max, concurrency.current)
    await Promise.resolve(); concurrency.current--
    return { topics: [{ id: `topic-${page}`, theme: { id: 'dev' }, playable: true }, { id: 'empty', theme: { id: 'dev' }, playable: false }], pagination: { totalPages } }
  }) } as unknown as ReturnType<typeof createDiscoveryClient>
}
describe('bounded dynamic sitemap', () => {
  it('parses the Nitro route parameter including its exact XML suffix', () => {
    expect(parseSitemapChunk('1.xml')).toBe(1)
    expect(parseSitemapChunk('100.xml')).toBe(100)
    for (const value of ['1', '1.json', '1.xml.extra', '01.xml', '0.xml', undefined]) expect(Number.isNaN(parseSitemapChunk(value))).toBe(true)
  })
  it('reads one ten-page chunk with at most three concurrent operations', async () => {
    const calls: number[] = []; const concurrency = { current: 0, max: 0 }
    const urls = await collectSitemapChunk(apiFor(1000, calls, concurrency), 'topics', 2, 'https://quickquiz.com.br', 'pt-BR')
    expect(calls).toEqual([11, 12, 13, 14, 15, 16, 17, 18, 19, 20])
    expect(concurrency.max).toBeLessThanOrEqual(3)
    expect(urls).toHaveLength(10)
    expect(urls.every(url => !url.includes('empty'))).toBe(true)
  })
  it('fails explicitly rather than silently excluding resources beyond traversal capacity', async () => {
    await expect(collectSitemapChunk(apiFor(1001, [], { current: 0, max: 0 }), 'topics', 1, 'https://example.com', 'en-US')).rejects.toBeInstanceOf(SitemapCapacityError)
  })
  it('escapes XML and enforces document size limits', () => {
    expect(sitemapDocument(['https://example.com/?a=1&b=2'])).toContain('a=1&amp;b=2')
    expect(() => sitemapDocument(Array.from({ length: 50001 }, () => 'https://example.com'))).toThrow('Sitemap limit')
  })
})
