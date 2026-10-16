<script setup lang="ts">
import type { DiscoveryTopic } from '../types/discovery'
defineProps<{ topic: DiscoveryTopic }>()
const { t, localPath, number, labelTag, countLabel } = useWebLocale()
</script>
<template><article class="topic-card"><NuxtLink :to="localPath(`/themes/${encodeURIComponent(topic.theme.id)}`)" class="topic-parent">{{ topic.theme.name }}</NuxtLink><NuxtLink :to="localPath(`/themes/${encodeURIComponent(topic.theme.id)}/topics/${encodeURIComponent(topic.id)}`)" class="card-main-link"><h3>{{ topic.label }}</h3><p>{{ topic.description || t('topicEyebrow') }}</p></NuxtLink><div class="topic-tags"><NuxtLink v-for="tag in topic.tags.slice(0, 3)" :key="tag" :to="{ path: localPath('/explore'), query: { kind: 'topics', topicTag: tag } }" class="tag-chip">{{ labelTag(tag) }}</NuxtLink></div><div class="card-footer"><span>{{ number(topic.questionCount) }} {{ countLabel(topic.questionCount, 'questions') }}</span><span v-if="!topic.playable" class="empty-badge">{{ t('comingSoon') }}</span><UIcon name="i-lucide-arrow-up-right" class="ml-auto" /></div></article></template>
