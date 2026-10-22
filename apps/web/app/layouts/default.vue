<script setup lang="ts">
const route = useRoute()
const { locale, supportedLocales, localPath, t } = useWebLocale()
const alternate = computed(() => supportedLocales.filter(value => value !== locale.value))
const languagePath = (value: string) => ({ path: route.path.replace(/^\/(pt-BR|en-US)/, `/${value}`), query: route.query })
</script>

<template>
  <div class="site-layout">
    <a class="skip-link" href="#main-content">{{ t('skip') }}</a>
    <header class="site-header">
      <NuxtLink :to="localPath('/')" class="brand" aria-label="QuickQuiz"><span class="brand-mark">Q<span /></span><span>Quick<span class="brand-light">Quiz</span></span></NuxtLink>
      <nav :aria-label="t('discover')" class="header-nav">
        <NuxtLink :to="localPath('/explore')" class="header-explore">{{ t('discover') }} <UIcon name="i-lucide-arrow-up-right" /></NuxtLink>
        <span class="header-divider" />
        <NuxtLink v-for="value in alternate" :key="value" :to="languagePath(value)" :aria-label="`${t('language')}: ${value === 'pt-BR' ? 'Português' : 'English'}`" class="language-link">{{ value === 'pt-BR' ? 'PT' : 'EN' }} <UIcon name="i-lucide-globe-2" /></NuxtLink>
      </nav>
    </header>
    <main id="main-content" tabindex="-1"><slot /></main>
    <footer class="site-footer"><NuxtLink :to="localPath('/')" class="footer-brand">QuickQuiz<span>© {{ new Date().getUTCFullYear() }}</span></NuxtLink><p>{{ t('footer') }}</p><span>{{ t('footerNote') }}</span></footer>
  </div>
</template>
