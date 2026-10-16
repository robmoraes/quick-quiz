import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: { environment: 'node', include: ['app/**/*.test.ts', 'shared/**/*.test.ts', 'server/**/*.test.ts', 'tests/**/*.test.ts'] },
})
