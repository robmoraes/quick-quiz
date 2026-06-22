const devtoolsWelcomeStyle = [
  'color: #fbbf24',
  'background: #020617',
  'border: 1px solid #38bdf8',
  'border-radius: 6px',
  'font: 700 13px monospace',
  'padding: 6px 10px',
].join(';');

export function printDevtoolsWelcome(message: string) {
  console.info(`%c${message}`, devtoolsWelcomeStyle);
}
