import { getRequestHeader, send, setResponseHeader, setResponseStatus } from 'h3'

export default defineNitroErrorHandler((error, event) => {
  // Preserve Nuxt's localized HTML error page. JSON errors need an explicit
  // handler because Nitro's default handler replaces no-store on every 404.
  if (getRequestHeader(event, 'accept')?.includes('text/html')) return
  const status = error.statusCode ?? 500
  const message = status === 404 ? 'Not found' : status === 400 ? 'Invalid request' : status === 503 ? 'Service unavailable' : 'Server error'
  const data = error.data as { code?: unknown } | undefined
  const code = data?.code === 'sitemap_capacity_exceeded' ? data.code : undefined
  setResponseStatus(event, status)
  setResponseHeader(event, 'Cache-Control', 'no-store')
  setResponseHeader(event, 'X-Content-Type-Options', 'nosniff')
  return send(event, JSON.stringify({ error: true, statusCode: status, message, ...(code ? { data: { code } } : {}) }), 'application/json; charset=utf-8')
})
