<template>
  <section class="ad-interstitial-panel">
    <div class="ad-interstitial-panel__topline">
      <div>
        <div class="ad-interstitial-panel__eyebrow">{{ t('advertising.eyebrow') }}</div>
        <h1>{{ t('advertising.title') }}</h1>
      </div>
      <q-space />
      <q-btn flat round icon="close" :aria-label="t('advertising.close')" @click="emit('close')" />
    </div>

    <div class="ad-interstitial-panel__slot">
      <a
        class="github-star-invite"
        :href="githubRepositoryUrl"
        target="_blank"
        rel="noopener noreferrer"
        @click="handleGithubStarClick"
      >
        <q-icon class="github-star-invite__icon" name="star" size="42px" />
        <div class="github-star-invite__copy">
          <strong>{{ t('advertising.githubStarTitle') }}</strong>
          <span>{{ t('advertising.githubStarMessage') }}</span>
        </div>
        <span class="github-star-invite__action">{{ t('advertising.githubStarAction') }}</span>
      </a>
    </div>
  </section>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { RunAdPanelEmit } from './contracts';
import { githubRepositoryUrl, markGithubStarInviteClicked } from 'src/services/github-star-invite';

const emit = defineEmits<RunAdPanelEmit>();
const { t } = useI18n();

function handleGithubStarClick() {
  markGithubStarInviteClicked();
  emit('close');
}
</script>
