import { ref } from 'vue';

export type SessionEventSeverity = 'info' | 'warn' | 'error' | 'critical';

export type SessionEventName =
  | 'session.initialized'
  | 'session.topic_selected'
  | 'session.difficulty_selected'
  | 'session.fatal_hardcore_loss'
  | 'session.result_requested'
  | 'run.started'
  | 'run.question_answered'
  | 'run.completed'
  | 'run.abandoned'
  | 'availability.topics_refreshed'
  | 'availability.difficulties_refreshed';

export interface SessionEvent {
  id: string;
  timestamp: string;
  host: string;
  app: 'quickquiz-frontend';
  severity: SessionEventSeverity;
  event: SessionEventName;
  sessionId: string;
  runId?: string;
  message: string;
  fields: Record<string, string | number | boolean | null | undefined>;
}

export type SessionEventInput = Omit<SessionEvent, 'id' | 'timestamp' | 'host' | 'app'>;

export const sessionEventLog = ref<SessionEvent[]>([]);

export function createSessionEvent(input: SessionEventInput): SessionEvent {
  return {
    ...input,
    id: createEventId(),
    timestamp: new Date().toISOString(),
    host: window.location.host || 'local',
    app: 'quickquiz-frontend',
  };
}

export function cloneSessionEvents(events: SessionEvent[]) {
  return events.map((event) => ({
    ...event,
    fields: { ...event.fields },
  }));
}

export function appendSessionEvent(input: SessionEventInput) {
  sessionEventLog.value = [...sessionEventLog.value, createSessionEvent(input)];
}

export function clearSessionEventLog() {
  sessionEventLog.value = [];
}

function createEventId() {
  if (window.crypto.randomUUID) {
    return window.crypto.randomUUID();
  }

  return `evt_${Date.now()}_${Math.random().toString(16).slice(2)}`;
}
