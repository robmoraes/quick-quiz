import { parseWebSettings } from '#shared/config'

export default defineEventHandler(event => {
  setResponseHeader(event, 'Cache-Control', 'no-store')
  setResponseHeader(event, 'X-Content-Type-Options', 'nosniff')
  setResponseHeader(event, 'Referrer-Policy', 'strict-origin-when-cross-origin')
  const settings = parseWebSettings(useRuntimeConfig(event))
  const path = event.path.split('?')[0] ?? '/'
  if (!settings.indexable || /\/(?:play|results)(?:\/|$)/.test(path)) setResponseHeader(event, 'X-Robots-Tag', 'noindex, nofollow')
})
