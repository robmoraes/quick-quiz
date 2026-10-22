export type GameLocale = 'pt-BR' | 'en-US'
export type Difficulty = 1 | 2 | 3 | 4
export interface GameContext { sessionId: string; theme: string; locale: string }
export interface DifficultyAvailability {
  id: Difficulty
  optionCount: number
  questionCount: number
  availableQuestionCount: number
  available: boolean
  hardcore: boolean
}
export interface SessionTopic {
  id: string
  label: string
  description?: string
  questionCount: number
  availableQuestionCount: number
  available: boolean
  difficulties?: DifficultyAvailability[]
}
export interface PublicQuestion {
  id: string
  prompt: string
  options: Array<{ id: string; text: string }>
  current: number
  total: number
}
export interface RunCreated { runId: string; theme: string; locale: string; question: PublicQuestion }
export interface AnswerResponse {
  correct: boolean
  finished: boolean
  finishReason?: string
  question?: PublicQuestion
}
export interface RunState {
  runId: string
  theme: string
  locale: string
  topic: string
  difficulty: Difficulty
  status: 'active' | 'finished'
  finished: boolean
  finishReason?: string
  answered: number
  total: number
  question?: PublicQuestion
}
export interface GameStats { answered: number; correct: number; wrong: number; accuracyPercent: number }
export interface RunResult {
  runId: string
  theme: string
  locale: string
  topic: string
  difficulty: Difficulty
  finishReason: string
  stats: GameStats
  answers: Array<{ questionId: string; prompt: string; correct: boolean }>
}
export interface QuestionSolution {
  theme: string
  locale: string
  topic: string
  difficulty: Difficulty
  questionId: string
  explanation: string
  model?: string
  generatedAt?: string
  cached: boolean
}
export type GamePhase = 'welcome' | 'levels' | 'question' | 'runResult' | 'sessionResult'
  | 'uncertainStart' | 'uncertainAnswer' | 'expired' | 'fatalLoss' | 'closing'
export interface RunSummary {
  runId: string
  topic: string
  difficulty: Difficulty
  stats: GameStats
}
export interface PersistedGame extends GameContext {
  version: 1
  topic: string
  difficulty: Difficulty
  runId: string
  phase: GamePhase
  summaries: RunSummary[]
  totals: GameStats
  completedRuns: number
  lastRecordedRunId: string
  closeReason: 'manual' | 'exhausted' | null
}
export interface GameApi {
  difficulties(topic: string): Promise<{ theme: string; locale: string; topic: string; difficulties: DifficultyAvailability[] }>
  topics(): Promise<{ theme: string; locale: string; topics: SessionTopic[] }>
  create(topic: string, difficulty: Difficulty): Promise<RunCreated>
  answer(runId: string, questionId: string, optionId: string): Promise<AnswerResponse>
  state(runId: string): Promise<RunState>
  result(runId: string): Promise<RunResult>
  finish(runId: string): Promise<unknown>
  reset(): Promise<unknown>
  solution(runId: string, questionId: string): Promise<QuestionSolution>
}
