import { getActiveLocale } from 'src/i18n/locale';
import { quickQuizTheme } from 'src/services/theme-config';

export enum Difficulty {
  Easy = 1,
  Normal = 2,
  Hard = 3,
  Hardcore = 4,
}

export interface TopicOption {
  id: string;
  label: string;
  description?: string;
  weight: number;
  createdAt?: string;
  difficulties?: DifficultyInfo[];
}

export interface DifficultyInfo {
  id: Difficulty;
  optionCount: number;
  questionCount: number;
  hardcore: boolean;
}

export interface SessionDifficultyAvailability extends DifficultyInfo {
  availableQuestionCount: number;
  available: boolean;
}

export interface Catalog {
  theme: string;
  locale: string;
  fallbackLocale: string;
  topics: TopicOption[];
  difficulties: DifficultyInfo[];
}

export interface SessionDifficulties {
  theme: string;
  locale: string;
  topic: string;
  difficulties: SessionDifficultyAvailability[];
}

export interface SessionTopicAvailability extends TopicOption {
  questionCount: number;
  availableQuestionCount: number;
  available: boolean;
  difficulties?: SessionDifficultyAvailability[];
}

export interface SessionTopics {
  theme: string;
  locale: string;
  fallbackLocale: string;
  topics: SessionTopicAvailability[];
}

export interface QuizOption {
  id: string;
  text: string;
}

export interface PublicQuestion {
  id: string;
  prompt: string;
  options: QuizOption[];
  current: number;
  total: number;
}

export interface CreateRunResponse {
  runId: string;
  theme: string;
  locale: string;
  question: PublicQuestion;
}

export interface AnswerResponse {
  correct: boolean;
  finished: boolean;
  finishReason?: string;
  question?: PublicQuestion;
}

export interface RunResult {
  runId: string;
  theme: string;
  locale: string;
  topic: string;
  difficulty: Difficulty;
  finishReason: string;
  stats: {
    answered: number;
    correct: number;
    wrong: number;
    accuracyPercent: number;
  };
  answers: Array<{
    questionId: string;
    prompt: string;
    correct: boolean;
  }>;
}

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080';

export async function getCatalog(): Promise<Catalog> {
  return request<Catalog>('/api/catalog');
}

export async function getSessionTopics(): Promise<SessionTopics> {
  return request<SessionTopics>('/api/session/topics');
}

export async function getSessionDifficulties(topic: string): Promise<SessionDifficulties> {
  const params = new URLSearchParams({ topic });
  return request<SessionDifficulties>(`/api/session/difficulties?${params.toString()}`);
}

export async function createRun(topic: string, difficulty: Difficulty): Promise<CreateRunResponse> {
  return request<CreateRunResponse>('/api/runs', {
    method: 'POST',
    body: JSON.stringify({ topic, difficulty, locale: getLocale() }),
  });
}

export async function answerQuestion(
  runId: string,
  questionId: string,
  optionId: string,
): Promise<AnswerResponse> {
  return request<AnswerResponse>(`/api/runs/${runId}/answers`, {
    method: 'POST',
    body: JSON.stringify({ questionId, optionId }),
  });
}

export async function finishRun(runId: string): Promise<void> {
  await request(`/api/runs/${runId}/finish`, { method: 'POST' });
}

export async function getResult(runId: string): Promise<RunResult> {
  return request<RunResult>(`/api/runs/${runId}/result`);
}

export async function resetSession(): Promise<void> {
  await request('/api/session/reset', { method: 'POST' });
  rotateSessionId();
}

export function resetLocalSession(): void {
  rotateSessionId();
}

export function getSessionId(): string {
  const current = window.sessionStorage.getItem('quickquiz.sessionId');
  if (current) {
    return current;
  }

  const next = createSessionId();
  window.sessionStorage.setItem('quickquiz.sessionId', next);
  return next;
}

function rotateSessionId() {
  window.sessionStorage.setItem('quickquiz.sessionId', createSessionId());
}

function createSessionId() {
  if (window.crypto.randomUUID) {
    return window.crypto.randomUUID();
  }

  return `session_${Date.now()}_${Math.random().toString(16).slice(2)}`;
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    headers: {
      'Content-Type': 'application/json',
      'X-QuickQuiz-Session-ID': getSessionId(),
      'X-QuickQuiz-Locale': getLocale(),
      'X-QuickQuiz-Theme': quickQuizTheme,
      ...init.headers,
    },
  });

  if (!response.ok) {
    throw await apiError(response);
  }

  return response.json() as Promise<T>;
}

function getLocale() {
  return getActiveLocale();
}

async function apiError(response: Response) {
  const fallback = `HTTP ${response.status}`;

  try {
    const payload = (await response.json()) as { error?: { code?: string; message?: string } };
    const error = new Error(payload.error?.message || fallback);
    if (payload.error?.code) {
      Object.assign(error, { code: payload.error.code });
    }
    return error;
  } catch {
    return new Error(fallback);
  }
}
