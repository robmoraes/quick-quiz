import { randomUUID } from 'node:crypto'
import { getRequestHeader, getResponseStatus, setResponseHeader } from 'h3'

export default defineNitroPlugin(app => {
  app.hooks.hook('request', event => {
    const supplied = getRequestHeader(event, 'x-request-id')
    event.context.requestId = supplied && /^[A-Za-z0-9._-]{1,80}$/.test(supplied) ? supplied : randomUUID()
    event.context.startedAt = performance.now()
    setResponseHeader(event, 'x-request-id', event.context.requestId)
  })
  app.hooks.hook('afterResponse', event => {
    const path = event.path.split('?')[0] ?? '/'
    if (path.startsWith('/_nuxt/') || path === '/healthz') return
    console.info(JSON.stringify({ event: 'web.request', method: event.method, status: getResponseStatus(event), durationMs: Math.round(performance.now() - Number(event.context.startedAt)), requestId: event.context.requestId }))
  })
})
