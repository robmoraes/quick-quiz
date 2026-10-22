<script setup lang="ts">
import { discoveryQuery, readExploreQuery } from '../../utils/explore-query'
import { discoveryStatus } from '../../utils/page-error'
import type { DiscoveryTheme, DiscoveryTopic } from '../../types/discovery'
definePageMeta({ key: route => route.fullPath })
const route = useRoute()
const { t, localPath, locale, labelTag, number } = useWebLocale()
const api = useDiscovery()
let filters: ReturnType<typeof readExploreQuery>
try { filters = readExploreQuery(route.query) } catch { throw createError({ statusCode: 400, statusMessage: 'Invalid discovery query' }) }
const draft = reactive({ q: filters.q ?? '', theme: filters.theme ?? '', themeTag: filters.themeTag ?? '', topicTag: filters.topicTag ?? '', difficulty: filters.difficulty ? String(filters.difficulty) : '' })
const { data, error, status, refresh } = await useAsyncData(`explore:${locale.value}:${JSON.stringify(filters)}`, async () => {
  const query = discoveryQuery(filters)
  const results = await (
    filters.kind === 'themes' ? api.themes(query) : api.topics(query)
  )
  return { results }
}, { getCachedData: (key, app) => app.isHydrating ? app.payload.data[key] : undefined })
if (error.value && import.meta.server) throw createError({ statusCode: discoveryStatus(error.value), statusMessage: 'Discovery unavailable' })
const themes = computed(() => data.value && 'themes' in data.value.results ? data.value.results.themes as DiscoveryTheme[] : [])
const topics = computed(() => data.value && 'topics' in data.value.results ? data.value.results.topics as DiscoveryTopic[] : [])
const activeFilters = computed(() => Object.entries(draft).filter(([, value]) => value))
const routeForKind = (kind: string) => ({ path: route.path, query: { ...route.query, kind, theme: kind === 'themes' ? undefined : filters.theme, page: undefined } })
async function applyFilters() {
  const query: Record<string, string> = { kind: draft.theme ? 'topics' : filters.kind }
  for (const [key, value] of Object.entries(draft)) if (value.trim()) query[key] = value.trim()
  await navigateTo({ path: route.path, query })
}
const levelKeys = ['easy', 'normal', 'hard', 'hardcore'] as const
usePageSeo(() => t('discover'), () => t('exploreDescription'), true, false)
</script>

<template>
  <div class="page-shell explore-page"><header class="page-heading"><p class="eyebrow">{{ t('discover') }}</p><h1>{{ t('exploreTitle') }}</h1><p>{{ t('exploreDescription') }}</p></header>
    <div class="explore-layout"><aside class="filter-sidebar"><h2><UIcon name="i-lucide-sliders-horizontal" />{{ t('filters') }}</h2><form method="get" :action="route.path" @submit.prevent="applyFilters"><input type="hidden" name="kind" :value="filters.kind"><label for="filter-search">{{ t('search') }}</label><input id="filter-search" v-model="draft.q" name="q" type="search" maxlength="200" :placeholder="t('searchPlaceholder')"><DiscoveryPicker v-model="draft.themeTag" kind="themeTag" :label="t('themeTag')" :query="{ ...discoveryQuery(filters), q: filters.kind === 'themes' ? filters.q : undefined }" :omit-search-context="filters.kind === 'topics' && !!filters.q" /><DiscoveryPicker v-model="draft.topicTag" kind="topicTag" :label="t('topicTag')" :query="{ ...discoveryQuery(filters), q: filters.kind === 'topics' ? filters.q : undefined }" :omit-search-context="filters.kind === 'themes' && !!filters.q" /><DiscoveryPicker v-model="draft.theme" kind="theme" :label="t('theme')" /><label for="filter-level">{{ t('difficulty') }}</label><select id="filter-level" v-model="draft.difficulty" name="difficulty"><option value="">{{ t('any') }}</option><option v-for="(key, index) in levelKeys" :key="key" :value="String(index + 1)">{{ t(key) }}</option></select><button class="primary-button" type="submit">{{ t('applyFilters') }}<UIcon name="i-lucide-arrow-right" /></button><NuxtLink :to="localPath('/explore')" class="filter-clear">{{ t('clearFilters') }}</NuxtLink></form></aside>
      <section class="explore-results"><div class="results-toolbar"><nav class="result-tabs" :aria-label="t('results')"><NuxtLink :to="routeForKind('themes')" :aria-current="filters.kind === 'themes' ? 'page' : undefined">{{ t('themes') }}</NuxtLink><NuxtLink :to="routeForKind('topics')" :aria-current="filters.kind === 'topics' ? 'page' : undefined">{{ t('topics') }}</NuxtLink></nav><span v-if="data" role="status">{{ number(data.results.pagination.total) }} {{ t('results') }}</span></div><div v-if="activeFilters.length" class="active-filters"><span v-for="[key, value] in activeFilters" :key="key" class="tag-chip">{{ key === 'themeTag' || key === 'topicTag' ? labelTag(value) : value }}</span></div><DiscoveryState v-if="error" state="error" @retry="refresh()" /><DiscoveryState v-else-if="status === 'pending'" state="loading" /><template v-else-if="data"><div v-if="themes.length || topics.length" class="theme-grid theme-grid--explore"><ThemeCard v-for="(theme, index) in themes" :key="theme.id" :theme="theme" :index="index" /><TopicCard v-for="topic in topics" :key="`${topic.theme.id}/${topic.id}`" :topic="topic" /></div><DiscoveryState v-else state="no-matches" /><DiscoveryPagination :pagination="data.results.pagination" /></template></section>
    </div>
  </div>
</template>
