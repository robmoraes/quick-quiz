<script setup lang="ts">
import type { DiscoveryPagination } from '../types/discovery'
defineProps<{ pagination: DiscoveryPagination; query?: Record<string, string | number | undefined> }>()
const route = useRoute()
const { t, number } = useWebLocale()
</script>
<template><nav v-if="pagination.totalPages > 1" :aria-label="t('page')" class="pagination"><NuxtLink v-if="pagination.page > 1" :to="{ path: route.path, query: { ...route.query, ...query, page: pagination.page - 1 } }" class="secondary-button"><UIcon name="i-lucide-arrow-left" /> {{ t('previous') }}</NuxtLink><span v-else /><p>{{ t('page') }} {{ number(pagination.page) }} {{ t('of') }} {{ number(pagination.totalPages) }}</p><NuxtLink v-if="pagination.page < Math.min(pagination.totalPages, 1000)" :to="{ path: route.path, query: { ...route.query, ...query, page: pagination.page + 1 } }" class="secondary-button">{{ t('next') }} <UIcon name="i-lucide-arrow-right" /></NuxtLink><span v-else /></nav></template>
