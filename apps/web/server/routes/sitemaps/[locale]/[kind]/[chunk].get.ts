import { parseWebSettings } from '#shared/config'
import { createDiscoveryClient } from '../../../../../app/utils/discovery-api'
import { collectSitemapChunk, parseSitemapChunk, SitemapCapacityError, sitemapDocument, sitemapPagesPerChunk, sitemapMaxApiPages } from '../../../../utils/sitemap'

export default defineEventHandler(async event => {
  const settings = parseWebSettings(useRuntimeConfig(event))
  const locale = getRouterParam(event, 'locale') ?? ''
  const kind = getRouterParam(event, 'kind')
  const chunk = parseSitemapChunk(getRouterParam(event, 'chunk'))
  if (!settings.supportedLocales.includes(locale as 'pt-BR' | 'en-US') || !['themes', 'topics'].includes(kind ?? '') || !Number.isInteger(chunk) || chunk < 1 || chunk > sitemapMaxApiPages / sitemapPagesPerChunk) throw createError({ statusCode: 404 })
  setResponseHeader(event, 'Content-Type', 'application/xml; charset=utf-8')
  if (!settings.indexable) return sitemapDocument([])
  const api = createDiscoveryClient(settings.apiBaseUrl, locale, fetch, settings.apiTimeoutMs, AbortSignal.timeout(settings.apiTimeoutMs))
  try {
    return sitemapDocument(await collectSitemapChunk(api, kind as 'themes' | 'topics', chunk, settings.siteUrl, locale))
  } catch (error) {
    if (error instanceof SitemapCapacityError) throw createError({ statusCode: 503, statusMessage: 'Sitemap capacity exceeded', data: { code: 'sitemap_capacity_exceeded' } })
    throw createError({ statusCode: 503, statusMessage: 'Sitemap temporarily unavailable' })
  }
})
