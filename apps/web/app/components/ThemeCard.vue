<script setup lang="ts">
import type { DiscoveryTheme } from '../types/discovery'
const props = defineProps<{ theme: DiscoveryTheme; index?: number }>()
const { t, localPath, number, labelTag, countLabel } = useWebLocale()
const icons = ['i-lucide-orbit', 'i-lucide-braces', 'i-lucide-book-open', 'i-lucide-sprout', 'i-lucide-flask-conical', 'i-lucide-landmark']
const icon = computed(() => icons[(props.index ?? props.theme.id.length) % icons.length])
</script>
<template><article class="theme-card"><div class="card-top"><span class="subject-icon" :class="`subject-icon--${(index ?? theme.id.length) % 4}`"><UIcon :name="icon" /></span><span v-if="theme.tags[0]" class="card-category">{{ labelTag(theme.tags[0]) }}</span><UIcon name="i-lucide-arrow-up-right" class="card-arrow" /></div><NuxtLink :to="localPath(`/themes/${encodeURIComponent(theme.id)}`)" class="card-main-link"><h3>{{ theme.name }}</h3><p>{{ theme.description || t('themeIntro') }}</p></NuxtLink><div class="card-footer"><span>{{ number(theme.topicCount) }} {{ countLabel(theme.topicCount, 'topics') }}</span><span class="dot" /><span>{{ number(theme.questionCount) }} {{ countLabel(theme.questionCount, 'questions') }}</span><span v-if="!theme.playable" class="empty-badge">{{ t('comingSoon') }}</span></div></article></template>
