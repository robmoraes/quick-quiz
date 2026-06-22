<template>
  <a
    v-if="visible"
    class="layout-github-star-invite"
    :href="githubRepositoryUrl"
    target="_blank"
    rel="noopener noreferrer"
    @click="handleClick"
  >
    <q-icon class="layout-github-star-invite__icon" name="star" size="18px" />
    <span>{{ t('advertising.githubStarBannerText') }}</span>
  </a>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  githubRepositoryUrl,
  markGithubStarInviteClicked,
  shouldShowGithubStarInvite,
} from 'src/services/github-star-invite';

const { t } = useI18n();
const visible = ref(shouldShowGithubStarInvite());

function handleClick() {
  markGithubStarInviteClicked();
  visible.value = false;
}
</script>
