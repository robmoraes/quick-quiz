<template>
  <div class="layout-ads" :class="`layout-ads--${placement}`">
    <a
      v-for="ad in ads"
      :key="ad.id"
      class="layout-ad"
      :href="ad.uri"
      target="_blank"
      rel="noreferrer sponsored"
      :aria-label="ad.description"
    >
      <img class="layout-ad__image" :src="ad.image" alt="" loading="lazy" decoding="async" />
      <span class="layout-ad__text">{{ ad.description }}</span>
    </a>

    <LayoutGithubStarInvite v-if="ads.length === 0 && placement === 'top'" />
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue';
import LayoutGithubStarInvite from 'src/components/quiz/LayoutGithubStarInvite.vue';
import { useLayoutAds } from 'src/composables/useLayoutAds';
import { onAdRefresh } from 'src/services/ad-refresh';

const props = withDefaults(
  defineProps<{
    placement?: 'top' | 'side' | 'result' | 'emphasis';
  }>(),
  {
    placement: 'top',
  },
);

const { regularAds, emphasisAds, ensureLayoutAds, refreshLayoutAds } = useLayoutAds();
const ads = computed(() => {
  if (props.placement === 'emphasis') {
    return emphasisAds.value;
  }
  if (props.placement === 'result') {
    return regularAds.value.slice(0, 1);
  }
  return regularAds.value;
});
let stopAdRefresh: (() => void) | null = null;

onMounted(() => {
  void ensureLayoutAds();
  stopAdRefresh = onAdRefresh(() => {
    void refreshLayoutAds();
  });
});

onBeforeUnmount(() => {
  stopAdRefresh?.();
  stopAdRefresh = null;
});
</script>
