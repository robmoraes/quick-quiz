import { computed, reactive } from 'vue'
import type {
  Difficulty, DifficultyAvailability, GameApi, GamePhase, GameStats,
  PersistedGame, PublicQuestion, QuestionSolution, RunResult, SessionTopic,
} from '../types/game'
import { GameApiError, createGameApi } from '../utils/game-api'

export const GAME_STORAGE_KEY = 'quickquiz.web.game.v1'
const phases: GamePhase[] = ['welcome', 'levels', 'question', 'runResult', 'sessionResult', 'uncertainStart', 'uncertainAnswer', 'expired', 'fatalLoss', 'closing']
const emptyStats = (): GameStats => ({ answered: 0, correct: 0, wrong: 0, accuracyPercent: 0 })
const initialContext = (): PersistedGame => ({ version: 1, sessionId: '', theme: '', locale: '', topic: '', difficulty: 1, runId: '', phase: 'welcome', summaries: [], totals: emptyStats(), completedRuns: 0, lastRecordedRunId: '', closeReason: null })
type StorageLike = Pick<Storage, 'getItem' | 'setItem'>

/** No browser globals are read until restore/prepare is explicitly called. */
export function useQuizGame(options: {
  baseUrl?: string
  api?: GameApi
  storage?: () => StorageLike
  uuid?: () => string
  excludedThemeIds?: readonly string[]
}) {
  const state = reactive({
    context: initialContext(),
    busy: false,
    restored: false,
    error: '',
    storageWarning: false,
    themeConflict: false,
    themeExcluded: false,
    question: null as PublicQuestion | null,
    result: null as RunResult | null,
    levels: [] as DifficultyAvailability[],
    topics: [] as SessionTopic[],
    feedback: '' as '' | 'correct' | 'wrong' | 'synchronized',
    solution: null as QuestionSolution | null,
    solutionQuestionId: '',
    solutionBusy: false,
    solutionError: '',
  })
  const api = options.api ?? createGameApi({ baseUrl: options.baseUrl ?? '', context: () => state.context })
  let generation = 0
  let solutionGeneration = 0
  let disposed = false
  const uuid = options.uuid ?? (() => globalThis.crypto.randomUUID())
  const storage = options.storage ?? (() => window.sessionStorage)
  const phase = computed(() => state.context.phase)
  const selectedLevel = computed(() => state.levels.find(level => level.id === state.context.difficulty))
  const topicLabel = computed(() => state.topics.find(topic => topic.id === state.context.topic)?.label ?? state.context.topic)

  function save() {
    try { storage().setItem(GAME_STORAGE_KEY, JSON.stringify(state.context)) }
    catch { state.storageWarning = true }
  }
  function active(token: number) { return !disposed && token === generation }
  function clearExplanation() {
    solutionGeneration++
    state.solution = null
    state.solutionQuestionId = ''
    state.solutionError = ''
    state.solutionBusy = false
  }
  function errorCode(error: unknown) {
    return error instanceof GameApiError ? error.code : 'connection_failed'
  }
  function expired(error: unknown) {
    if (errorCode(error) !== 'run_not_found') return false
    state.context = { ...initialContext(), theme: state.context.theme, topic: state.context.topic, locale: state.context.locale, phase: 'expired' }
    state.question = null
    state.result = null
    clearExplanation()
    state.error = 'expired'
    save()
    return true
  }
  async function execute(action: (token: number) => Promise<void>) {
    if (state.busy || disposed || state.themeExcluded) return
    state.busy = true
    state.error = ''
    const token = generation
    try { await action(token) }
    catch (error) { if (active(token) && !expired(error)) state.error = errorCode(error) }
    finally { if (active(token)) state.busy = false }
  }
  async function availability(token: number) {
    const [levels, topics] = await Promise.all([api.difficulties(state.context.topic), api.topics()])
    if (!active(token)) return
    state.levels = levels.difficulties
    state.topics = topics.topics
    if (!selectedLevel.value?.available) {
      state.context.difficulty = state.levels.find(level => level.available)?.id ?? 1
    }
    save()
  }
  async function restore() {
    if (state.restored || disposed) return
    state.restored = true
    try {
      const raw = storage().getItem(GAME_STORAGE_KEY)
      if (raw) {
        const saved: unknown = JSON.parse(raw)
        if (!validContext(saved)) throw new Error('invalid_context')
        state.context = saved
      }
    } catch { state.storageWarning = true }
    state.themeExcluded = options.excludedThemeIds?.includes(state.context.theme) ?? false
    if (state.themeExcluded) return
    // Mount/reload only reads known player state. It never creates or resets.
    if (!state.context.sessionId || ['sessionResult', 'fatalLoss', 'expired', 'uncertainStart', 'closing'].includes(phase.value)) return
    await execute(async token => {
      if (state.context.runId) {
        // Questions and full results are deliberately not persisted. Until a
        // known run is authoritatively read, expose a retryable recovery state
        // rather than a question/result screen with no content or controls.
        state.context.phase = 'uncertainAnswer'
        save()
        await reconcileInternal(token, false)
      } else await availability(token)
    })
  }
  async function prepare(theme: string, topic: string, locale: string, difficulty?: Difficulty) {
    if (!theme || !topic || state.busy || options.excludedThemeIds?.includes(theme)) return
    if (state.context.sessionId && state.context.theme !== theme) {
      state.themeConflict = true
      return
    }
    if (['uncertainStart', 'closing'].includes(phase.value) || (state.context.runId && ['question', 'uncertainAnswer'].includes(phase.value))) return
    await execute(async token => {
      if (!state.context.sessionId) {
        state.context = { ...initialContext(), sessionId: uuid(), theme, locale, topic, phase: 'levels' }
      } else {
        state.context.topic = topic
        state.context.locale = locale
        state.context.runId = ''
        state.context.phase = 'levels'
      }
      state.result = null
      state.question = null
      clearExplanation()
      state.themeConflict = false
      state.context.difficulty = difficulty ?? state.context.difficulty
      save()
      await availability(token)
    })
  }
  function chooseLevel(level: Difficulty) {
    if (state.busy || phase.value !== 'levels') return
    if (!state.levels.find(item => item.id === level)?.available) return
    state.context.difficulty = level
    save()
  }
  async function start(hardcoreConfirmed = false) {
    if (phase.value !== 'levels' || !selectedLevel.value?.available || (state.context.difficulty === 4 && !hardcoreConfirmed)) return
    await execute(async token => {
      // Persist uncertainty before the request: a reload must not silently retry.
      state.context.phase = 'uncertainStart'
      clearExplanation()
      save()
      try {
        const result = await api.create(state.context.topic, state.context.difficulty)
        if (!active(token)) return
        state.context.runId = result.runId
        state.context.locale = result.locale
        state.context.phase = 'question'
        state.question = result.question
        state.result = null
        state.feedback = ''
        save()
      } catch (error) {
        if (!active(token)) return
        if (error instanceof GameApiError && error.status >= 400 && error.status < 500) {
          state.context.phase = 'levels'
          save()
        }
        throw error
      }
    })
  }
  function recordResult(result: RunResult) {
    if (state.context.lastRecordedRunId === result.runId) return
    const totals = state.context.totals
    totals.answered += result.stats.answered
    totals.correct += result.stats.correct
    totals.wrong += result.stats.wrong
    totals.accuracyPercent = totals.answered ? Math.round(100 * totals.correct / totals.answered) : 0
    state.context.completedRuns++
    state.context.lastRecordedRunId = result.runId
    // No prompts, options, solutions or full answer history are persisted.
    state.context.summaries = [...state.context.summaries, { runId: result.runId, topic: result.topic, difficulty: result.difficulty, stats: result.stats }].slice(-50)
  }
  async function closeInternal(token: number, reason: 'manual' | 'exhausted') {
    state.context.closeReason = reason
    state.context.phase = 'closing'
    state.question = null
    clearExplanation()
    save()
    await api.reset()
    if (!active(token)) return
    state.context.sessionId = ''
    state.context.runId = ''
    state.context.phase = 'sessionResult'
    state.result = null
    save()
  }
  async function loadResult(token: number, allowAutoClose: boolean) {
    const result = await api.result(state.context.runId)
    if (!active(token)) return
    state.result = result
    state.question = null
    state.context.phase = 'runResult'
    recordResult(result)
    save()
    if (allowAutoClose) {
      const topics = await api.topics()
      if (!active(token)) return
      state.topics = topics.topics
      // The accepted final run jumps straight to the confirmed session result.
      if (!topics.topics.some(topic => topic.available)) await closeInternal(token, 'exhausted')
    }
  }
  async function reconcileInternal(token: number, allowAutoClose = true) {
    if (!state.context.runId) return
    const result = await api.state(state.context.runId)
    if (!active(token)) return
    if (result.theme !== state.context.theme || result.runId !== state.context.runId) throw new Error('invalid_run_state')
    state.context.topic = result.topic
    state.context.difficulty = result.difficulty
    state.context.locale = result.locale
    if (result.finished || result.status === 'finished') {
      await loadResult(token, allowAutoClose)
    } else if (result.question) {
      state.question = result.question
      state.context.phase = 'question'
      state.feedback = 'synchronized'
      save()
    } else {
      state.context.phase = 'uncertainAnswer'
      state.error = 'invalid_run_state'
      save()
    }
  }
  async function reconcile() {
    if (!state.context.runId || ['uncertainStart', 'closing', 'sessionResult'].includes(phase.value)) return
    await execute(token => reconcileInternal(token))
  }
  async function answer(optionId: string, questionId = state.question?.id) {
    if (phase.value !== 'question' || !state.question || !questionId || questionId !== state.question.id || !state.question.options.some(option => option.id === optionId)) return
    await execute(async token => {
      // Preserve the original question ID even if a response arrives late.
      state.context.phase = 'uncertainAnswer'
      save()
      try {
        const result = await api.answer(state.context.runId, questionId, optionId)
        if (!active(token)) return
        if (result.finishReason === 'hardcore_wrong_answer') {
          state.context = { ...initialContext(), theme: state.context.theme, topic: state.context.topic, locale: state.context.locale, phase: 'fatalLoss' }
          state.question = null
          state.result = null
          clearExplanation()
          save()
          return
        }
        state.feedback = result.correct ? 'correct' : 'wrong'
        if (result.finished) await loadResult(token, true)
        else if (result.question) {
          state.question = result.question
          state.context.phase = 'question'
          save()
        } else await reconcileInternal(token)
      } catch (error) {
        if (!active(token)) return
        // A confirmed result and an uncertain closure are not uncertain
        // answers. Preserve their distinct recovery paths and recorded totals.
        if (phase.value === 'closing' || phase.value === 'runResult') throw error
        if (expired(error)) return
        // Never repeat an uncertain submission, even when authoritative state
        // still points at the original question. The player must choose again.
        try { await reconcileInternal(token) }
        catch (reconcileError) {
          if (active(token) && !expired(reconcileError)) {
            state.context.phase = 'uncertainAnswer'
            state.error = 'answer_uncertain'
            save()
          }
        }
      }
    })
  }
  async function nextTopic(topic: string, locale: string) {
    if (!['runResult', 'levels'].includes(phase.value)) return
    await prepare(state.context.theme, topic, locale)
  }
  async function endSession(confirmed: boolean) {
    if (!confirmed || !state.context.sessionId || ['uncertainStart', 'uncertainAnswer', 'closing'].includes(phase.value)) return
    await execute(async token => {
      if (state.context.runId && phase.value === 'question') {
        state.context.phase = 'uncertainAnswer'
        save()
        await api.finish(state.context.runId)
        if (!active(token)) return
        await loadResult(token, false)
      }
      await closeInternal(token, 'manual')
    })
  }
  async function retryClose() {
    if (phase.value !== 'closing' || !state.context.sessionId) return
    await execute(token => closeInternal(token, state.context.closeReason ?? 'manual'))
  }
  async function resetAndPrepare(theme: string, topic: string, locale: string, confirmed: boolean) {
    if (!confirmed || state.busy) return
    let reset = false
    await execute(async token => {
      // Keep the previous identity until the server confirms reset. In the
      // unknown-start case there is intentionally no run lookup or POST retry.
      if (state.context.sessionId) await api.reset()
      if (!active(token)) return
      state.context = initialContext()
      state.question = null
      state.result = null
      clearExplanation()
      state.themeConflict = false
      save()
      reset = true
    })
    if (reset) await prepare(theme, topic, locale)
  }
  async function explanation(questionId: string) {
    if (state.solutionBusy || phase.value !== 'runResult' || !state.result?.answers.some(answer => answer.questionId === questionId && !answer.correct)) return
    const token = generation
    const solutionToken = ++solutionGeneration
    const runId = state.context.runId
    const current = () => active(token) && solutionToken === solutionGeneration && state.context.runId === runId && phase.value === 'runResult'
    state.solutionQuestionId = questionId
    state.solutionBusy = true
    state.solutionError = ''
    state.solution = null
    try {
      const solution = await api.solution(runId, questionId)
      if (current()) state.solution = solution
    } catch (error) { if (current()) state.solutionError = errorCode(error) }
    finally { if (current()) state.solutionBusy = false }
  }
  function dismissExplanation() {
    clearExplanation()
  }
  function dispose() { clearExplanation(); disposed = true; generation++; state.busy = false }
  return { state, phase, selectedLevel, topicLabel, restore, prepare, chooseLevel, start, answer, reconcile, nextTopic, endSession, retryClose, resetAndPrepare, explanation, dismissExplanation, dispose }
}

function validContext(value: unknown): value is PersistedGame {
  if (!value || typeof value !== 'object') return false
  const context = value as PersistedGame
  const strings = [context.sessionId, context.theme, context.locale, context.topic, context.runId, context.lastRecordedRunId]
  const validStats = (stats: GameStats) => stats && ['answered', 'correct', 'wrong', 'accuracyPercent'].every(key => Number.isFinite(stats[key as keyof GameStats]) && stats[key as keyof GameStats] >= 0)
  return context.version === 1 && strings.every(item => typeof item === 'string' && item.length <= 256)
    && phases.includes(context.phase) && [1, 2, 3, 4].includes(context.difficulty)
    && validStats(context.totals) && Number.isInteger(context.completedRuns) && context.completedRuns >= 0
    && [null, 'manual', 'exhausted'].includes(context.closeReason)
    && Array.isArray(context.summaries) && context.summaries.length <= 50
    && context.summaries.every(summary => typeof summary.runId === 'string' && typeof summary.topic === 'string' && [1, 2, 3, 4].includes(summary.difficulty) && validStats(summary.stats))
}
