<template>
  <div class="brand-logo-pair" :class="`brand-logo-pair--${variant}`">
    <img :src="dslabLogoUrl" alt="DSLab" class="brand-logo-pair__dslab" />
    <img :src="quickQuizLogoUrl" :alt="t('app.title')" class="brand-logo-pair__quickquiz" />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import dslabLogoUrl from 'src/assets/dslab-logo.png';
import quickQuizLogoUrl from 'src/assets/logo-large.svg';

withDefaults(
  defineProps<{
    variant?: 'header' | 'welcome';
  }>(),
  {
    variant: 'header',
  },
);

const { t } = useI18n();
</script>
