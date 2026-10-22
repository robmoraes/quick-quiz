<script setup lang="ts">
import { discoveryStatus } from '../../utils/page-error'
definePageMeta({ key: route => route.path })
const { locale, t, localPath, labelTag } = useWebLocale()
const api = useDiscovery()
const { data, error, status, refresh } = await useAsyncData(`home:${locale.value}`, async () => {
  const [themes, tags] = await Promise.all([api.themes({ pageSize: 12 }), api.tags('theme', { pageSize: 8 })])
  return { themes, tags }
}, { getCachedData: (key, app) => app.isHydrating ? app.payload.data[key] : undefined })
if (error.value && import.meta.server) throw createError({ statusCode: discoveryStatus(error.value), statusMessage: 'Discovery unavailable' })
usePageSeo(() => t('about'), () => t('homeDescription'), () => data.value?.themes.themes.some(theme => theme.playable) ?? false)
</script>

<template>
  <div class="home-page">
    <section class="hero page-shell">
      <div class="hero-copy"><p class="eyebrow"><span class="tiny-star">✳</span> {{ t('homeEyebrow') }}</p><h1>{{ t('homeTitle') }}</h1><p class="hero-description">{{ t('homeDescription') }}</p><form :action="localPath('/explore')" method="get" class="hero-search"><label class="sr-only" for="home-search">{{ t('searchLabel') }}</label><UIcon name="i-lucide-search" /><input id="home-search" name="q" type="search" :placeholder="t('searchPlaceholder')" maxlength="200"><button type="submit" :aria-label="t('searchAction')"><UIcon name="i-lucide-arrow-right" /></button></form><div class="hero-note"><span class="note-dots"><i /><i /><i /></span>{{ t('about') }}</div></div>
      <div class="hero-art" aria-hidden="true"><div class="orbit orbit-one" /><div class="orbit orbit-two" /><span class="art-spark">✳</span><div class="art-card art-card-back"><span>Q.</span><i /><i /><i /></div><div class="art-card art-card-front"><span class="art-card-label">QuickQuiz</span><strong>?</strong><div class="art-progress"><i /><i /><i /><i /></div><span class="art-check"><UIcon name="i-lucide-check" /></span></div><span class="art-small art-small-book"><UIcon name="i-lucide-book-open" /></span><span class="art-small art-small-atom"><UIcon name="i-lucide-orbit" /></span><span class="art-caption">Explore · Learn · Repeat</span></div>
    </section>
    <div class="page-shell">
      <DiscoveryState v-if="error" state="error" @retry="refresh()" />
      <DiscoveryState v-else-if="status === 'pending'" state="loading" />
      <template v-else-if="data">
        <section v-if="data.tags.tags.length" class="areas-section"><div class="section-heading"><div><h2>{{ t('areas') }}</h2><p>{{ t('areasDescription') }}</p></div><NuxtLink :to="localPath('/explore')" class="text-link">{{ t('viewAll') }} <UIcon name="i-lucide-arrow-up-right" /></NuxtLink></div><div class="area-chips"><NuxtLink v-for="tag in data.tags.tags" :key="tag.slug" :to="{ path: localPath('/explore'), query: { themeTag: tag.slug } }" class="area-chip"><span>✳</span>{{ labelTag(tag.slug) }}<UIcon name="i-lucide-arrow-up-right" /></NuxtLink></div></section>
        <section class="theme-section"><div class="section-heading"><div><p class="eyebrow">{{ t('themes') }}</p><h2>{{ t('themeCollection') }}</h2><p>{{ t('themeDescription') }}</p></div><NuxtLink :to="localPath('/explore')" class="text-link">{{ t('allThemes') }} <UIcon name="i-lucide-arrow-right" /></NuxtLink></div><div v-if="data.themes.themes.length" class="theme-grid"><ThemeCard v-for="(theme, index) in data.themes.themes" :key="theme.id" :theme="theme" :index="index" /></div><DiscoveryState v-else state="empty" /></section>
      </template>
      <section class="how-section"><div class="how-intro"><p class="eyebrow">QuickQuiz</p><h2>{{ t('whyTitle') }}</h2><p>{{ t('whyDescription') }}</p></div><ol class="how-steps"><li v-for="(step, index) in ['One', 'Two', 'Three'] as const" :key="step"><span>0{{ index + 1 }}</span><h3>{{ t(`step${step}`) }}</h3><p>{{ t(`step${step}Description`) }}</p></li></ol></section>
    </div>
  </div>
</template>
