import { describe, expect, it } from 'vitest'
import { parseWebSettings } from './config'

const config = { apiBaseUrl: 'http://api:8080', apiTimeoutMs: '5000', public: { apiBaseUrl: 'http://localhost:18086', siteUrl: 'http://localhost:18085', defaultLocale: 'pt-BR', supportedLocales: 'en-US,pt-BR', indexable: 'false' } }
describe('runtime configuration boundary', () => {
  it('keeps server and browser origins separate and normalizes runtime booleans', () => {
    expect(parseWebSettings(config)).toMatchObject({ apiBaseUrl: 'http://api:8080', browserApiBaseUrl: 'http://localhost:18086', indexable: false, defaultLocale: 'pt-BR' })
  })
  it.each(['file:///etc/passwd', 'https://user:password@example.com', 'https://example.com/private', 'http://example.com?secret=x'])('rejects unsafe origins without reflecting values: %s', value => {
    expect(() => parseWebSettings({ ...config, apiBaseUrl: value })).toThrow('Invalid Web configuration: apiBaseUrl')
  })
  it('rejects unsupported or duplicate locales and ambiguous switches', () => {
    expect(() => parseWebSettings({ ...config, public: { ...config.public, supportedLocales: 'pt-BR,fr-FR' } })).toThrow('supportedLocales')
    expect(() => parseWebSettings({ ...config, public: { ...config.public, supportedLocales: 'pt-BR,pt-BR' } })).toThrow('supportedLocales')
    expect(() => parseWebSettings({ ...config, public: { ...config.public, indexable: 'yes' } })).toThrow('indexable')
    expect(() => parseWebSettings({ ...config, apiTimeoutMs: '0' })).toThrow('apiTimeoutMs')
  })
})
