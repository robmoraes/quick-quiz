import { ref } from 'vue';
import { getAds, type Ad } from 'src/services/api';

const regularAds = ref<Ad[]>([]);
const emphasisAds = ref<Ad[]>([]);
const selectedTopic = ref('');
let loaded = false;
let loadingPromise: Promise<void> | null = null;

export function useLayoutAds() {
  return {
    regularAds,
    emphasisAds,
    ensureLayoutAds,
    refreshLayoutAds,
    setLayoutAdsTopic,
  };
}

async function ensureLayoutAds(): Promise<void> {
  if (loaded) {
    return;
  }
  await refreshLayoutAds();
}

async function refreshLayoutAds(): Promise<void> {
  if (loadingPromise) {
    return loadingPromise;
  }

  const requestedTopic = selectedTopic.value;
  loadingPromise = getAds(3, 2, requestedTopic)
    .then((response) => {
      if (requestedTopic !== selectedTopic.value) {
        return;
      }
      regularAds.value = response.ads;
      emphasisAds.value = response.emphasis ?? [];
      loaded = true;
    })
    .catch(() => {
      if (requestedTopic !== selectedTopic.value) {
        return;
      }
      regularAds.value = [];
      emphasisAds.value = [];
      loaded = true;
    })
    .finally(() => {
      loadingPromise = null;
      if (!loaded) {
        void refreshLayoutAds();
      }
    });

  await loadingPromise;
}

function setLayoutAdsTopic(topic: string): void {
  const nextTopic = topic.trim();
  if (selectedTopic.value === nextTopic) {
    return;
  }
  selectedTopic.value = nextTopic;
  loaded = false;
}
