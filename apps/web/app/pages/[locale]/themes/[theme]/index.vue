<script setup lang="ts">
import { discoveryStatus } from '../../../../utils/page-error'
definePageMeta({ key: route => route.fullPath })
const route = useRoute()
const { t, locale, localPath, labelTag, number, countLabel } = useWebLocale()
const api = useDiscovery()
const id = String(route.params.theme)
const page = Number(route.query.page ?? 1)
if (!Number.isInteger(page) || page < 1 || page > 1000 || Array.isArray(route.query.page)) throw createError({ statusCode: 400, statusMessage: 'Invalid page' })
const { data, error, status, refresh } = await useAsyncData(`theme:${locale.value}:${id}:${page}`, async () => {
  const [detail, topics] = await Promise.all([api.theme(id), api.topics({ theme: id, page })])
  return { theme: detail.theme, topics }
}, { getCachedData: (key, app) => app.isHydrating ? app.payload.data[key] : undefined })
if (error.value && import.meta.server) throw createError({ statusCode: discoveryStatus(error.value), statusMessage: discoveryStatus(error.value) === 404 ? 'Theme not found' : 'Discovery unavailable' })
usePageSeo(() => data.value?.theme.name ?? t('theme'), () => data.value?.theme.description || t('themeIntro'), () => data.value?.theme.playable ?? false)
</script>
<template><div class="page-shell detail-page"><nav class="breadcrumbs" :aria-label="t('discover')"><NuxtLink :to="localPath('/')">QuickQuiz</NuxtLink><span>/</span><NuxtLink :to="localPath('/explore')">{{ t('themes') }}</NuxtLink><template v-if="data"><span>/</span><span>{{ data.theme.name }}</span></template></nav><DiscoveryState v-if="error" state="error" @retry="refresh()" /><DiscoveryState v-else-if="status === 'pending'" state="loading" /><template v-else-if="data"><header class="detail-hero"><div><p class="eyebrow">{{ t('theme') }}</p><h1>{{ data.theme.name }}</h1><p class="detail-description">{{ data.theme.description || t('themeIntro') }}</p><div class="detail-tags"><NuxtLink v-for="tag in data.theme.tags" :key="tag" :to="{ path: localPath('/explore'), query: { themeTag: tag } }" class="tag-chip">{{ labelTag(tag) }} <UIcon name="i-lucide-arrow-up-right" /></NuxtLink></div></div><div class="detail-stat"><strong>{{ number(data.theme.questionCount) }}</strong><span>{{ countLabel(data.theme.questionCount, 'questions') }}</span><small>{{ number(data.theme.topicCount) }} {{ countLabel(data.theme.topicCount, 'topics') }}</small></div></header><section class="theme-section"><div class="section-heading"><div><h2>{{ t('chooseTopic') }}</h2><p>{{ t('themeIntro') }}</p></div></div><div v-if="data.topics.topics.length" class="theme-grid"><TopicCard v-for="topic in data.topics.topics" :key="topic.id" :topic="topic" /></div><DiscoveryState v-if="!data.theme.playable" state="empty" /><DiscoveryPagination :pagination="data.topics.pagination" /></section></template></div></template>
