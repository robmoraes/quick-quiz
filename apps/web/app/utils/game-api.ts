import type { GameApi, GameContext } from '../types/game'

export class GameApiError extends Error {
  constructor(public status: number, public code: string) {
    super(code || `HTTP ${status}`)
    this.name = 'GameApiError'
  }
}

/** Browser-independent client. A session accessor is injected only by gameplay. */
export function createGameApi(options: {
  baseUrl: string
  context: () => GameContext
  fetch?: typeof globalThis.fetch
  timeoutMs?: number
}): GameApi {
  const fetcher = options.fetch ?? globalThis.fetch
  async function request<T>(path: string, body?: unknown): Promise<T> {
    const context = options.context()
    if (!context.sessionId || !context.theme) throw new Error('game_context_required')
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), options.timeoutMs ?? 15000)
    try {
      const response = await fetcher(`${options.baseUrl.replace(/\/$/, '')}${path}`, {
        method: body === undefined ? 'GET' : 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-QuickQuiz-Session-ID': context.sessionId,
          'X-QuickQuiz-Theme': context.theme,
          'X-QuickQuiz-Locale': context.locale,
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        signal: controller.signal,
        cache: 'no-store',
      })
      if (!response.ok) {
        const payload = await response.json().catch(() => ({})) as { error?: { code?: string } }
        throw new GameApiError(response.status, payload.error?.code ?? 'request_failed')
      }
      return await response.json() as T
    } finally { clearTimeout(timer) }
  }
  const run = (id: string) => `/api/runs/${encodeURIComponent(id)}`
  return {
    difficulties: topic => request(`/api/session/difficulties?${new URLSearchParams({ topic })}`),
    topics: () => request('/api/session/topics'),
    create: (topic, difficulty) => request('/api/runs', { topic, difficulty, locale: options.context().locale }),
    answer: (id, questionId, optionId) => request(`${run(id)}/answers`, { questionId, optionId }),
    state: id => request(`${run(id)}/state`),
    result: id => request(`${run(id)}/result`),
    finish: id => request(`${run(id)}/finish`, {}),
    reset: () => request('/api/session/reset', {}),
    solution: (id, questionId) => request(`${run(id)}/questions/${encodeURIComponent(questionId)}/solution`),
  }
}
