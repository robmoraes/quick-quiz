<script setup lang="ts">
import type { NuxtError } from '#app'
defineProps<{ error: NuxtError }>()
const { t, localPath } = useWebLocale()
const route = useRoute()
useHead({ meta: [{ name: 'robots', content: 'noindex,follow' }] })
</script>
<template><NuxtLayout><section class="page-shell error-page"><p class="eyebrow">QuickQuiz · {{ error.statusCode }}</p><h1>{{ error.statusCode === 404 ? t('notFound') : t('unavailable') }}</h1><p class="muted">{{ t('failedDescription') }}</p><div class="button-row"><button class="primary-button" @click="clearError({ redirect: route.fullPath })">{{ t('retry') }}</button><NuxtLink :to="localPath('/')" class="secondary-button" @click.prevent="clearError({ redirect: localPath('/') })">{{ t('backHome') }}</NuxtLink></div></section></NuxtLayout></template>
