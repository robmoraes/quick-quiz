import { readFileSync } from 'node:fs'
import { TextDecoder } from 'node:util'

const keys = [
  'NUXT_API_BASE_URL', 'NUXT_API_TIMEOUT_MS', 'NUXT_PUBLIC_API_BASE_URL',
  'NUXT_PUBLIC_SITE_URL', 'NUXT_PUBLIC_DEFAULT_LOCALE',
  'NUXT_PUBLIC_SUPPORTED_LOCALES', 'NUXT_PUBLIC_INDEXABLE',
]

export function resolveConfigFiles(env = process.env) {
  for (const key of keys) {
    const path = env[`${key}__FILE`]
    if (path === undefined || path === '') continue
    try {
      const value = new TextDecoder('utf-8', { fatal: true }).decode(readFileSync(path)).trim()
      if (!value || value.includes('\0')) throw new Error('empty or invalid')
      env[key] = value
    } catch {
      throw new Error(`Invalid Web configuration: ${key}__FILE`)
    }
  }
}
