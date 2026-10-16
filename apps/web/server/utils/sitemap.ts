import type { createDiscoveryClient } from '../../app/utils/discovery-api'

export const sitemapPageSize = 100
export const sitemapPagesPerChunk = 10
export const sitemapMaxApiPages = 1000

export class SitemapCapacityError extends Error {}

export function parseSitemapChunk(value: string | undefined): number {
  const match = value?.match(/^([1-9][0-9]*)\.xml$/)
  return match ? Number(match[1]) : Number.NaN
}

export async function collectSitemapChunk(api: ReturnType<typeof createDiscoveryClient>, kind: 'themes' | 'topics', chunk: number, siteUrl: string, locale: string): Promise<string[]> {
  if (!Number.isInteger(chunk) || chunk < 1 || chunk > sitemapMaxApiPages / sitemapPagesPerChunk) throw new Error('Invalid sitemap chunk')
  const firstPage = (chunk - 1) * sitemapPagesPerChunk + 1
  const read = async (page: number) => {
    const response = kind === 'themes' ? await api.themes({ page, pageSize: sitemapPageSize }) : await api.topics({ page, pageSize: sitemapPageSize })
    if (response.pagination.totalPages > sitemapMaxApiPages) throw new SitemapCapacityError('Sitemap discovery capacity exceeded')
    const urls = 'themes' in response
      ? response.themes.filter(theme => theme.playable).map(theme => `${siteUrl}/${locale}/themes/${encodeURIComponent(theme.id)}`)
      : response.topics.filter(topic => topic.playable).map(topic => `${siteUrl}/${locale}/themes/${encodeURIComponent(topic.theme.id)}/topics/${encodeURIComponent(topic.id)}`)
    if (page === 1 && 'themes' in response && response.themes.slice(0, 12).some(theme => theme.playable)) urls.unshift(`${siteUrl}/${locale}/`)
    return { urls, totalPages: response.pagination.totalPages }
  }
  const first = await read(firstPage)
  const urls = [...first.urls]
  const endPage = Math.min(firstPage + sitemapPagesPerChunk - 1, first.totalPages)
  for (let page = firstPage + 1; page <= endPage; page += 3) {
    const pages = Array.from({ length: Math.min(3, endPage - page + 1) }, (_, offset) => page + offset)
    const results = await Promise.all(pages.map(read))
    for (const result of results) urls.push(...result.urls)
  }
  return urls
}

export function escapeXml(value: string): string {
  return value.replace(/[&<>"']/g, value => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[value]!)
}
export function sitemapDocument(urls: string[]): string {
  if (urls.length > 50000) throw new Error('Sitemap limit exceeded')
  return `<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${urls.map(url => `<url><loc>${escapeXml(url)}</loc></url>`).join('')}</urlset>`
}
export function sitemapIndex(urls: string[]): string {
  return `<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${urls.map(url => `<sitemap><loc>${escapeXml(url)}</loc></sitemap>`).join('')}</sitemapindex>`
}
