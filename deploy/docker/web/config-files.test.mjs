import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { resolveConfigFiles } from './config-files.mjs'

test('selected files override plain config; other environment stays untouched', () => {
  const dir = mkdtempSync(join(tmpdir(), 'qq-web-config-'))
  try {
    const path = join(dir, 'origin')
    writeFileSync(path, 'http://api:8080\n')
    const env = { NUXT_API_BASE_URL: 'http://old:8080', NUXT_API_BASE_URL__FILE: path, OTHER__FILE: '/missing' }
    resolveConfigFiles(env)
    assert.equal(env.NUXT_API_BASE_URL, 'http://api:8080')
    assert.equal(env.OTHER__FILE, '/missing')
    const exclusions = join(dir, 'exclusions')
    writeFileSync(exclusions, 'dev,dslab\n')
    const excludedEnv = { NUXT_PUBLIC_EXCLUDED_THEME_IDS: 'other', NUXT_PUBLIC_EXCLUDED_THEME_IDS__FILE: exclusions }
    resolveConfigFiles(excludedEnv)
    assert.equal(excludedEnv.NUXT_PUBLIC_EXCLUDED_THEME_IDS, 'dev,dslab')
    assert.doesNotThrow(() => resolveConfigFiles({ NUXT_API_BASE_URL__FILE: '' }))
  } finally { rmSync(dir, { recursive: true, force: true }) }
})

test('unreadable, empty, NUL and invalid UTF-8 files fail without falling back or leaking values', () => {
  const dir = mkdtempSync(join(tmpdir(), 'qq-web-config-'))
  try {
    const path = join(dir, 'config')
    const env = { NUXT_API_BASE_URL: 'private-plain-value', NUXT_API_BASE_URL__FILE: path }
    for (const bytes of [null, '\n', 'private-file-value\0', Buffer.from([0xff])]) {
      if (bytes !== null) writeFileSync(path, bytes)
      assert.throws(() => resolveConfigFiles(env), { message: 'Invalid Web configuration: NUXT_API_BASE_URL__FILE' })
      assert.equal(env.NUXT_API_BASE_URL, 'private-plain-value')
    }
  } finally { rmSync(dir, { recursive: true, force: true }) }
})
