import { fileURLToPath } from 'node:url'

export default defineNuxtConfig({
  compatibilityDate: '2026-10-11',
  modules: ['@nuxt/ui', '@nuxt/eslint'],
  css: ['@fontsource-variable/manrope', '~/assets/css/main.css'],
  devtools: { enabled: false },
  ui: { fonts: false, colorMode: false },
  runtimeConfig: {
    apiBaseUrl: 'http://api:8080',
    apiTimeoutMs: 5000,
    public: {
      apiBaseUrl: 'http://localhost:18080',
      siteUrl: 'http://localhost:18085',
      defaultLocale: 'pt-BR',
      supportedLocales: 'en-US,pt-BR',
      indexable: false,
    },
  },
  app: {
    head: {
      title: 'QuickQuiz',
      meta: [{ name: 'theme-color', content: '#f7f8f5' }],
      link: [{ rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' }],
    },
    pageTransition: false,
  },
  nitro: { preset: 'node-server' },
  hooks: {
    'nitro:config'(config) {
      const existing = config.errorHandler
      config.errorHandler = [fileURLToPath(new URL('./server/error-handler.ts', import.meta.url)), ...(Array.isArray(existing) ? existing : existing ? [existing] : [])]
    },
  },
  routeRules: { '/**': { headers: { 'cache-control': 'no-store' } } },
  typescript: { strict: true },
  eslint: { config: { stylistic: false } },
})
