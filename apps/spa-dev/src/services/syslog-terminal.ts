import type { SessionEvent, SessionEventSeverity } from './session-events';

export const syslogTerminalIntroLines = ['Quick Quiz Dev Terminal', 'Ready.'];
export const syslogPrompt = 'run$quiz:~$';
export const syslogTailCommand =
  "sudo tail -f /var/log/syslog | grep -i 'quickquiz-frontend' | ccze -A";

export type SyslogTokenRole =
  | 'timestamp'
  | 'host'
  | 'app'
  | 'separator'
  | 'severity'
  | 'event'
  | 'key'
  | 'value'
  | 'message';

export interface SyslogToken {
  role: SyslogTokenRole;
  text: string;
  severity?: SessionEventSeverity;
}

export function formatSyslogEventParts(event: SessionEvent): SyslogToken[] {
  return [
    { role: 'timestamp', text: `${event.timestamp} ` },
    { role: 'host', text: `${event.host} ` },
    { role: 'app', text: event.app },
    { role: 'separator', text: ': ' },
    { role: 'severity', text: `${event.severity} `, severity: event.severity },
    { role: 'event', text: `${event.event} ` },
    { role: 'key', text: 'session=' },
    { role: 'value', text: `${abbreviateLogValue(event.sessionId)} ` },
    { role: 'key', text: 'run=' },
    { role: 'value', text: `${abbreviateLogValue(event.runId ?? '-')} ` },
    { role: 'message', text: event.message },
  ];
}

function abbreviateLogValue(value: string) {
  return value.length > 11 ? `${value.slice(0, 4)}...${value.slice(-4)}` : value;
}
