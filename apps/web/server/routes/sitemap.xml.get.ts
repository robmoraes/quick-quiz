import { parseWebSettings } from '#shared/config'
import { createDiscoveryClient } from '../../app/utils/discovery-api'
import { SitemapCapacityError, sitemapIndex, sitemapMaxApiPages, sitemapPagesPerChunk, sitemapPageSize } from '../utils/sitemap'

export default defineEventHandler(async event => {
  const settings = parseWebSettings(useRuntimeConfig(event))
  setResponseHeader(event, 'Content-Type', 'application/xml; charset=utf-8')
  if (!settings.indexable) return sitemapIndex([])
  try {
    const api = createDiscoveryClient(settings.apiBaseUrl, settings.defaultLocale, fetch, settings.apiTimeoutMs, AbortSignal.timeout(settings.apiTimeoutMs))
    const [themes, topics] = await Promise.all([api.themes({ pageSize: sitemapPageSize }), api.topics({ pageSize: sitemapPageSize })])
    const urls: string[] = []
    for (const [kind, totalPages] of [['themes', themes.pagination.totalPages], ['topics', topics.pagination.totalPages]] as const) {
      if (totalPages > sitemapMaxApiPages) throw new SitemapCapacityError('Sitemap discovery capacity exceeded')
      const count = Math.max(1, Math.ceil(totalPages / sitemapPagesPerChunk))
      for (const locale of settings.supportedLocales) for (let chunk = 1; chunk <= count; chunk++) urls.push(`${settings.siteUrl}/sitemaps/${locale}/${kind}/${chunk}.xml`)
    }
    return sitemapIndex(urls)
  } catch (error) {
    if (error instanceof SitemapCapacityError) throw createError({ statusCode: 503, statusMessage: 'Sitemap capacity exceeded', data: { code: 'sitemap_capacity_exceeded' } })
    throw createError({ statusCode: 503, statusMessage: 'Sitemap temporarily unavailable' })
  }
})
