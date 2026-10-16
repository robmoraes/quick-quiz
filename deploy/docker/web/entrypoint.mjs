import { resolveConfigFiles } from './config-files.mjs'

// Resolve once before Nitro reads runtime configuration. A selected file never
// falls back to the plain variable on failure, and values never enter logs.
try {
  resolveConfigFiles()
  await import('./.output/server/index.mjs')
} catch (error) {
  // Plugin errors identify a configuration key; avoid dumping runtime config.
  const message = error instanceof Error && error.message.startsWith('Invalid Web configuration:')
    ? error.message : 'Web startup failed'
  console.error(message)
  process.exit(1)
}
