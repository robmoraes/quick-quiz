import { describe, expect, it, vi } from 'vitest'
import { GAME_STORAGE_KEY, useQuizGame } from '../app/composables/useQuizGame'
import { createGameApi, GameApiError } from '../app/utils/game-api'
import type { GameApi, PublicQuestion, RunResult, RunState } from '../app/types/game'

const question = (id = 'q1', current = 1): PublicQuestion => ({ id, prompt: 'Synthetic prompt', options: [{ id: 'a', text: 'Option A' }, { id: 'b', text: 'Option B' }], current, total: 2 })
const runResult = (): RunResult => ({ runId: 'run1', theme: 'science', locale: 'en-US', topic: 'space', difficulty: 1, finishReason: 'max_questions_reached', stats: { answered: 2, correct: 1, wrong: 1, accuracyPercent: 50 }, answers: [{ questionId: 'q1', prompt: 'Synthetic prompt', correct: false }] })
const runState = (id = 'q1'): RunState => ({ runId: 'run1', theme: 'science', locale: 'en-US', topic: 'space', difficulty: 1, status: 'active', finished: false, answered: id === 'q1' ? 0 : 1, total: 2, question: question(id, id === 'q1' ? 1 : 2) })
function fixture(overrides: Partial<GameApi> = {}) {
  const data = new Map<string, string>()
  const storage = { getItem: (key: string) => data.get(key) ?? null, setItem: (key: string, value: string) => { data.set(key, value) } }
  const api: GameApi = {
    difficulties: vi.fn(async () => ({ theme: 'science', locale: 'en-US', topic: 'space', difficulties: [1, 4].map(id => ({ id: id as 1 | 4, questionCount: 4, availableQuestionCount: 4, available: true, optionCount: 2, hardcore: id === 4 })) })),
    topics: vi.fn(async () => ({ theme: 'science', locale: 'en-US', topics: [{ id: 'space', label: 'Space', questionCount: 4, availableQuestionCount: 2, available: true }] })),
    create: vi.fn(async () => ({ runId: 'run1', theme: 'science', locale: 'en-US', question: question() })),
    answer: vi.fn(async () => ({ correct: true, finished: false, question: question('q2', 2) })),
    state: vi.fn(async () => runState()),
    result: vi.fn(async () => runResult()),
    finish: vi.fn(async () => ({ finished: true })),
    reset: vi.fn(async () => ({ reset: true })),
    solution: vi.fn(async () => ({ theme: 'science', locale: 'en-US', topic: 'space', difficulty: 1, questionId: 'q1', explanation: 'Synthetic explanation', cached: true })),
    ...overrides,
  }
  let identities = 0
  const options = { api, storage: () => storage, uuid: () => `session-${++identities}` }
  return { game: useQuizGame(options), api, data, options }
}
async function started(overrides: Partial<GameApi> = {}) {
  const item = fixture(overrides)
  await item.game.prepare('science', 'space', 'en-US')
  await item.game.start()
  return item
}
function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(done => { resolve = done })
  return { promise, resolve }
}

describe('isolated Web gameplay', () => {
  it('does not access storage, sessions or API during SSR construction or empty restoration', async () => {
    const item = fixture()
    expect(item.game.state.context.sessionId).toBe('')
    expect(item.data.size).toBe(0)
    await item.game.restore()
    for (const operation of Object.values(item.api)) expect(operation).not.toHaveBeenCalled()
  })

  it('creates player identity only on prepare and respects authoritative level availability', async () => {
    const item = fixture()
    await item.game.prepare('science', 'space', 'pt-BR', 4)
    expect(item.game.state.context.sessionId).toBe('session-1')
    expect(item.game.state.context.locale).toBe('pt-BR')
    expect(item.game.selectedLevel.value?.id).toBe(4)
    expect(item.api.create).not.toHaveBeenCalled()
    expect(item.game.topicLabel.value).toBe('Space')
  })

  it('requires explicit hardcore confirmation', async () => {
    const item = fixture()
    await item.game.prepare('science', 'space', 'en-US', 4)
    await item.game.start()
    expect(item.api.create).not.toHaveBeenCalled()
    await item.game.start(true)
    expect(item.api.create).toHaveBeenCalledExactlyOnceWith('space', 4)
  })

  it('does not duplicate starts or answers while a request is pending', async () => {
    const pending = deferred<{ runId: string; theme: string; locale: string; question: PublicQuestion }>()
    const item = fixture({ create: vi.fn(() => pending.promise) })
    await item.game.prepare('science', 'space', 'en-US')
    const first = item.game.start()
    await item.game.start()
    expect(item.api.create).toHaveBeenCalledTimes(1)
    pending.resolve({ runId: 'run1', theme: 'science', locale: 'en-US', question: question() })
    await first
    const answer = item.game.answer('a')
    await item.game.answer('a')
    await answer
    expect(item.api.answer).toHaveBeenCalledTimes(1)
  })

  it('retains unknown creation outcome across refresh without retry or player lookup', async () => {
    const item = fixture({ create: vi.fn(async () => { throw new TypeError('lost response') }) })
    await item.game.prepare('science', 'space', 'en-US')
    await item.game.start()
    expect(item.game.phase.value).toBe('uncertainStart')
    expect(item.game.state.context.runId).toBe('')
    await item.game.prepare('science', 'space', 'en-US')
    await item.game.start()
    const restored = useQuizGame(item.options)
    await restored.restore()
    expect(restored.phase.value).toBe('uncertainStart')
    expect(restored.state.context.sessionId).toBe('session-1')
    expect(item.api.create).toHaveBeenCalledTimes(1)
    expect(item.api.state).not.toHaveBeenCalled()
  })

  it('rotates an unknown-start identity only after confirmed server reset', async () => {
    const pendingReset = deferred<unknown>()
    const item = fixture({ create: vi.fn(async () => { throw new TypeError('lost response') }), reset: vi.fn(() => pendingReset.promise) })
    await item.game.prepare('science', 'space', 'en-US')
    await item.game.start()
    await item.game.resetAndPrepare('science', 'space', 'en-US', false)
    expect(item.api.reset).not.toHaveBeenCalled()
    const reset = item.game.resetAndPrepare('science', 'space', 'en-US', true)
    expect(item.game.state.context.sessionId).toBe('session-1')
    pendingReset.resolve({ reset: true })
    await reset
    expect(item.game.state.context.sessionId).toBe('session-2')
    expect(item.game.phase.value).toBe('levels')
    expect(item.api.create).toHaveBeenCalledTimes(1)
  })

  it('keeps unknown-start context when reset fails', async () => {
    const item = fixture({ create: vi.fn(async () => { throw new TypeError('lost response') }), reset: vi.fn(async () => { throw new TypeError('offline') }) })
    await item.game.prepare('science', 'space', 'en-US')
    await item.game.start()
    await item.game.resetAndPrepare('science', 'space', 'en-US', true)
    expect(item.game.state.context.sessionId).toBe('session-1')
    expect(item.game.phase.value).toBe('uncertainStart')
  })

  it('treats an explicit no-questions rejection as a safe level-selection failure', async () => {
    const item = fixture({ create: vi.fn(async () => { throw new GameApiError(409, 'no_questions_left') }) })
    await item.game.prepare('science', 'space', 'en-US')
    await item.game.start()
    expect(item.game.phase.value).toBe('levels')
    expect(item.game.state.error).toBe('no_questions_left')
  })

  it('reconciles an uncertain answer without resubmitting its option to the next question', async () => {
    const item = await started({ answer: vi.fn(async () => { throw new TypeError('lost response') }), state: vi.fn(async () => runState('q2')) })
    await item.game.answer('a', 'q1')
    expect(item.api.answer).toHaveBeenCalledExactlyOnceWith('run1', 'q1', 'a')
    expect(item.game.state.question?.id).toBe('q2')
    await item.game.answer('a', 'q1')
    expect(item.api.answer).toHaveBeenCalledTimes(1)
    expect(item.game.phase.value).toBe('question')
  })

  it('requires reconciliation before another answer when both submission and state fail', async () => {
    const item = await started({ answer: vi.fn(async () => { throw new TypeError('offline') }), state: vi.fn(async () => { throw new TypeError('offline') }) })
    await item.game.answer('a')
    expect(item.game.phase.value).toBe('uncertainAnswer')
    await item.game.answer('b')
    expect(item.api.answer).toHaveBeenCalledTimes(1)
  })

  it('restores a known run using its authoritative question instead of persisted answer data', async () => {
    const item = await started({ state: vi.fn(async () => runState('q2')) })
    const saved = JSON.parse(item.data.get(GAME_STORAGE_KEY)!)
    expect(saved.question).toBeUndefined()
    expect(JSON.stringify(saved)).not.toContain('Synthetic prompt')
    const restored = useQuizGame(item.options)
    await restored.restore()
    expect(restored.state.question?.id).toBe('q2')
    expect(restored.state.context.runId).toBe('run1')
    expect(item.api.create).toHaveBeenCalledTimes(1)
    expect(item.api.reset).not.toHaveBeenCalled()
  })

  it('offers read-only reconciliation after a known-run restore fails offline or with 503', async () => {
    for (const failure of [new TypeError('offline'), new GameApiError(503, 'storage_unavailable')]) {
      const item = await started({ state: vi.fn(async () => { throw failure }) })
      const restored = useQuizGame(item.options)
      await restored.restore()
      expect(restored.phase.value).toBe('uncertainAnswer')
      expect(restored.state.context.runId).toBe('run1')
      expect(restored.state.context.sessionId).toBe('session-1')
      expect(restored.state.question).toBeNull()
      expect(restored.state.result).toBeNull()
      expect(item.api.state).toHaveBeenCalledExactlyOnceWith('run1')
      vi.mocked(item.api.state).mockResolvedValueOnce(runState('q2'))
      await restored.reconcile()
      expect(restored.phase.value).toBe('question')
      expect(restored.state.question?.id).toBe('q2')
      expect(item.api.create).toHaveBeenCalledTimes(1)
      expect(item.api.answer).not.toHaveBeenCalled()
      expect(item.api.finish).not.toHaveBeenCalled()
      expect(item.api.reset).not.toHaveBeenCalled()
    }
  })

  it('keeps a finished run recoverable when its result cannot be read during reload', async () => {
    const item = await started({ state: vi.fn(async () => ({ ...runState(), finished: true, status: 'finished', question: undefined })), result: vi.fn(async () => { throw new GameApiError(503, 'storage_unavailable') }) })
    const restored = useQuizGame(item.options)
    await restored.restore()
    expect(restored.phase.value).toBe('uncertainAnswer')
    expect(restored.state.context.runId).toBe('run1')
    expect(restored.state.context.sessionId).toBe('session-1')
    expect(restored.state.result).toBeNull()
    vi.mocked(item.api.result).mockResolvedValueOnce(runResult())
    await restored.reconcile()
    expect(restored.phase.value).toBe('runResult')
    expect(restored.state.result?.runId).toBe('run1')
    expect(restored.state.context.completedRuns).toBe(1)
    expect(item.api.create).toHaveBeenCalledTimes(1)
    expect(item.api.answer).not.toHaveBeenCalled()
    expect(item.api.finish).not.toHaveBeenCalled()
    expect(item.api.reset).not.toHaveBeenCalled()
  })

  it('keeps a session bound to its theme and captured run locale while browsing another locale', async () => {
    const item = await started()
    await item.game.prepare('literature', 'poetry', 'pt-BR')
    expect(item.game.state.themeConflict).toBe(true)
    expect(item.game.state.context.theme).toBe('science')
    expect(item.game.state.context.locale).toBe('en-US')
    await item.game.prepare('science', 'space', 'pt-BR')
    expect(item.game.state.context.locale).toBe('en-US')
    expect(item.game.state.context.runId).toBe('run1')
  })

  it('clears invalid session progress on expiry and offers a new identity through explicit recovery', async () => {
    const item = await started({ state: vi.fn(async () => { throw new GameApiError(404, 'run_not_found') }) })
    await item.game.reconcile()
    expect(item.game.phase.value).toBe('expired')
    expect(item.game.state.context.runId).toBe('')
    expect(item.game.state.context.sessionId).toBe('')
    expect(item.game.state.context.completedRuns).toBe(0)
    await item.game.resetAndPrepare('science', 'space', 'pt-BR', true)
    expect(item.game.state.context.sessionId).toBe('session-2')
  })

  it('honors fatal hardcore loss without querying a deleted run result', async () => {
    const item = await started({ answer: vi.fn(async () => ({ correct: false, finished: true, finishReason: 'hardcore_wrong_answer' })) })
    await item.game.answer('b')
    expect(item.game.phase.value).toBe('fatalLoss')
    expect(item.game.state.context.totals.answered).toBe(0)
    expect(item.game.state.context.sessionId).toBe('')
    expect(item.api.result).not.toHaveBeenCalled()
  })

  it('automatically closes exhausted sessions and skips the last-run result screen', async () => {
    const item = await started({ answer: vi.fn(async () => ({ correct: false, finished: true })) })
    vi.mocked(item.api.topics).mockResolvedValueOnce({ theme: 'science', locale: 'en-US', topics: [] })
    await item.game.answer('b')
    expect(item.game.phase.value).toBe('sessionResult')
    expect(item.game.state.context.closeReason).toBe('exhausted')
    expect(item.game.state.context.totals).toEqual(runResult().stats)
    expect(item.game.state.context.completedRuns).toBe(1)
    expect(item.api.reset).toHaveBeenCalledTimes(1)
    expect(item.game.state.context.sessionId).toBe('')
    const restored = useQuizGame(item.options)
    await restored.restore()
    expect(restored.phase.value).toBe('sessionResult')
    expect(restored.state.context.totals.answered).toBe(2)
  })

  it('preserves confirmed totals when automatic closure is uncertain and retries reset only', async () => {
    const item = await started({ answer: vi.fn(async () => ({ correct: true, finished: true })), reset: vi.fn(async () => { throw new TypeError('offline') }) })
    vi.mocked(item.api.topics).mockResolvedValueOnce({ theme: 'science', locale: 'en-US', topics: [] })
    await item.game.answer('a')
    expect(item.game.phase.value).toBe('closing')
    expect(item.game.state.context.sessionId).toBe('session-1')
    expect(item.game.state.context.completedRuns).toBe(1)
    vi.mocked(item.api.reset).mockResolvedValueOnce({ reset: true })
    await item.game.retryClose()
    expect(item.game.phase.value).toBe('sessionResult')
    expect(item.game.state.context.completedRuns).toBe(1)
    expect(item.api.result).toHaveBeenCalledTimes(1)
  })

  it('shows confirmed run results, supports wrong-answer explanations and counts each run once', async () => {
    const item = await started({ answer: vi.fn(async () => ({ correct: false, finished: true })), state: vi.fn(async () => ({ ...runState(), finished: true, status: 'finished', question: undefined })) })
    await item.game.answer('b')
    expect(item.game.phase.value).toBe('runResult')
    await item.game.reconcile()
    expect(item.game.state.context.completedRuns).toBe(1)
    await item.game.explanation('not-in-result')
    expect(item.api.solution).not.toHaveBeenCalled()
    await item.game.explanation('q1')
    expect(item.game.state.solution?.explanation).toBe('Synthetic explanation')
    expect(JSON.stringify(JSON.parse(item.data.get(GAME_STORAGE_KEY)!))).not.toContain('Synthetic')
  })

  it('clears explanations between runs and discards late solution success and failure', async () => {
    const pending = deferred<Awaited<ReturnType<GameApi['solution']>>>()
    const item = await started({ answer: vi.fn(async () => ({ correct: false, finished: true })) })
    await item.game.answer('b')
    await item.game.explanation('q1')
    expect(item.game.state.solutionQuestionId).toBe('q1')
    expect(item.game.state.solution).not.toBeNull()
    await item.game.nextTopic('space', 'en-US')
    expect(item.game.state.solutionQuestionId).toBe('')
    expect(item.game.state.solution).toBeNull()
    vi.mocked(item.api.create).mockResolvedValueOnce({ runId: 'run2', theme: 'science', locale: 'en-US', question: question() })
    vi.mocked(item.api.result).mockResolvedValueOnce({ ...runResult(), runId: 'run2' })
    await item.game.start()
    await item.game.answer('b')
    expect(item.game.state.solutionQuestionId).toBe('')
    vi.mocked(item.api.solution).mockImplementationOnce(() => pending.promise)
    const request = item.game.explanation('q1')
    expect(item.game.state.solutionBusy).toBe(true)
    await item.game.nextTopic('space', 'pt-BR')
    expect(item.game.state.solutionBusy).toBe(false)
    pending.resolve({ theme: 'science', locale: 'en-US', topic: 'space', difficulty: 1, questionId: 'q1', explanation: 'Late old solution', cached: false })
    await request
    expect(item.game.state.solution).toBeNull()
    expect(item.game.state.solutionQuestionId).toBe('')
    expect(item.game.state.solutionError).toBe('')

    vi.mocked(item.api.create).mockResolvedValueOnce({ runId: 'run3', theme: 'science', locale: 'en-US', question: question() })
    vi.mocked(item.api.result).mockResolvedValueOnce({ ...runResult(), runId: 'run3' })
    await item.game.start()
    await item.game.answer('b')
    let reject!: (reason?: unknown) => void
    vi.mocked(item.api.solution).mockImplementationOnce(() => new Promise((_resolve, fail) => { reject = fail }))
    const failed = item.game.explanation('q1')
    await item.game.nextTopic('space', 'en-US')
    reject(new GameApiError(503, 'solution_unavailable'))
    await failed
    expect(item.game.state.solutionError).toBe('')
    expect(item.game.state.solutionBusy).toBe(false)
  })

  it('bounds saved run summaries while retaining all confirmed aggregate counts', async () => {
    const item = fixture({ answer: vi.fn(async () => ({ correct: false, finished: true })) })
    for (let index = 1; index <= 52; index++) {
      vi.mocked(item.api.create).mockResolvedValueOnce({ runId: `run-${index}`, theme: 'science', locale: 'en-US', question: question() })
      vi.mocked(item.api.result).mockResolvedValueOnce({ ...runResult(), runId: `run-${index}` })
      await item.game.prepare('science', 'space', 'en-US')
      await item.game.start()
      await item.game.answer('b')
    }
    const saved = JSON.parse(item.data.get(GAME_STORAGE_KEY)!)
    expect(saved.summaries).toHaveLength(50)
    expect(saved.summaries[0].runId).toBe('run-3')
    expect(saved.completedRuns).toBe(52)
    expect(saved.totals).toEqual({ answered: 104, correct: 52, wrong: 52, accuracyPercent: 50 })
    expect(JSON.stringify(saved)).not.toContain('Synthetic prompt')
  })

  it('requires confirmed manual closure and fetches the final partial result before reset', async () => {
    const item = await started()
    await item.game.endSession(false)
    expect(item.api.finish).not.toHaveBeenCalled()
    await item.game.endSession(true)
    expect(item.api.finish).toHaveBeenCalledExactlyOnceWith('run1')
    expect(item.api.result).toHaveBeenCalledExactlyOnceWith('run1')
    expect(item.api.reset).toHaveBeenCalledTimes(1)
    expect(item.game.phase.value).toBe('sessionResult')
  })

  it('rejects stale responses after disposal while retaining creation uncertainty for recovery', async () => {
    const pending = deferred<{ runId: string; theme: string; locale: string; question: PublicQuestion }>()
    const item = fixture({ create: vi.fn(() => pending.promise) })
    await item.game.prepare('science', 'space', 'en-US')
    const request = item.game.start()
    item.game.dispose()
    pending.resolve({ runId: 'run1', theme: 'science', locale: 'en-US', question: question() })
    await request
    expect(item.game.state.context.runId).toBe('')
    expect(JSON.parse(item.data.get(GAME_STORAGE_KEY)!).phase).toBe('uncertainStart')
  })

  it('explicitly reports unavailable browser storage without claiming refresh recovery', async () => {
    const item = fixture()
    const game = useQuizGame({ api: item.api, storage: () => { throw new Error('blocked') }, uuid: () => 'memory-only' })
    await game.restore()
    await game.prepare('science', 'space', 'en-US')
    expect(game.state.storageWarning).toBe(true)
    await game.start()
    expect(game.phase.value).toBe('question')
  })
})

describe('gameplay HTTP boundary', () => {
  it('injects theme/session/locale per request, encodes identities and submits original question ID', async () => {
    const fetcher = vi.fn<typeof globalThis.fetch>(async () => new Response(JSON.stringify({ correct: true, finished: false, question: question('q2') }), { status: 200 }))
    const context = { theme: 'science', sessionId: 'tab-session', locale: 'pt-BR' }
    const client = createGameApi({ baseUrl: 'http://api.example/', context: () => context, fetch: fetcher })
    await client.answer('run/1', 'q1', 'b')
    expect(fetcher.mock.calls[0]?.[0]).toBe('http://api.example/api/runs/run%2F1/answers')
    const init = fetcher.mock.calls[0]?.[1] as RequestInit
    expect(init.headers).toMatchObject({ 'X-QuickQuiz-Session-ID': 'tab-session', 'X-QuickQuiz-Theme': 'science', 'X-QuickQuiz-Locale': 'pt-BR' })
    expect(JSON.parse(String(init.body))).toEqual({ questionId: 'q1', optionId: 'b' })
    expect(init.cache).toBe('no-store')
    expect(init).not.toHaveProperty('credentials')
  })

  it('does not access context or fetch at module/client construction', () => {
    const context = vi.fn(() => ({ sessionId: '', theme: '', locale: '' }))
    const fetcher = vi.fn()
    createGameApi({ baseUrl: 'http://api.example', context, fetch: fetcher })
    expect(context).not.toHaveBeenCalled()
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('preserves machine error codes without disclosing upstream message contents', async () => {
    const client = createGameApi({ baseUrl: 'http://api.example', context: () => ({ sessionId: 'session', theme: 'science', locale: 'en-US' }), fetch: vi.fn(async () => new Response(JSON.stringify({ error: { code: 'solution_forbidden', message: 'Internal detail' } }), { status: 403 })) })
    await expect(client.solution('run1', 'q1')).rejects.toMatchObject({ status: 403, code: 'solution_forbidden', message: 'solution_forbidden' })
  })
})
