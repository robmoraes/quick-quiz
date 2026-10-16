import { parseWebSettings } from '#shared/config'

export default defineEventHandler(event => {
  const settings = parseWebSettings(useRuntimeConfig(event))
  setResponseHeader(event, 'Content-Type', 'text/plain; charset=utf-8')
  return settings.indexable ? `User-agent: *\nAllow: /\nSitemap: ${settings.siteUrl}/sitemap.xml\n` : 'User-agent: *\nDisallow: /\n'
})
