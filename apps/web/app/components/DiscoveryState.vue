<script setup lang="ts">
defineProps<{ state: 'error' | 'empty' | 'no-matches' | 'loading' }>()
defineEmits<{ retry: [] }>()
const { t, localPath } = useWebLocale()
</script>
<template><section class="discovery-state" :role="state === 'error' ? 'alert' : 'status'"><UIcon :name="state === 'error' ? 'i-lucide-cloud-off' : state === 'loading' ? 'i-lucide-loader-circle' : 'i-lucide-sparkles'" :class="{ spinning: state === 'loading' }" /><h2>{{ state === 'error' ? t('failedTitle') : state === 'loading' ? t('loading') : state === 'empty' ? t('emptyTitle') : t('noMatches') }}</h2><p v-if="state !== 'loading'">{{ state === 'error' ? t('failedDescription') : state === 'empty' ? t('emptyDescription') : t('noMatchesDescription') }}</p><button v-if="state === 'error'" class="secondary-button" @click="$emit('retry')">{{ t('retry') }}</button><NuxtLink v-else-if="state === 'no-matches'" :to="localPath('/explore')" class="secondary-button">{{ t('clearFilters') }}</NuxtLink></section></template>
