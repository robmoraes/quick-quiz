<template>
  <section class="welcome-panel column flex-center text-center q-pa-xl">
    <q-img
      :src="logoUrl"
      :alt="t('app.title')"
      fit="contain"
      no-spinner
      class="welcome-panel__logo"
    />

    <div class="welcome-panel__terminal q-mt-xl" aria-live="polite">
      <span>{{ animatedText }}</span>
      <span class="welcome-panel__cursor" aria-hidden="true">_</span>
    </div>

    <q-btn
      v-show="actionVisible"
      unelevated
      color="amber-8"
      text-color="black"
      icon="terminal"
      label="bash qq/deploy.sh"
      class="q-mt-xl"
      @click="emit('advance')"
    />
  </section>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import logoUrl from 'src/assets/logo-large.svg';

type WelcomePanelEmit = {
  (event: 'advance'): void;
};

const emit = defineEmits<WelcomePanelEmit>();
const { t } = useI18n();

const frames = [
  { text: '> Q', delay: 560 },
  { text: '> Qu', delay: 120 },
  { text: '> Qui', delay: 120 },
  { text: '> Quic', delay: 120 },
  { text: '> Quick', delay: 500 },
  { text: '> Quic', delay: 120 },
  { text: '> Qui', delay: 280 },
  { text: '> Quiz', delay: 500 },
  { text: '> Qui', delay: 140 },
  { text: '> Quic', delay: 120 },
  { text: '> Quick', delay: 120 },
  { text: '> Quick ', delay: 120 },
  { text: '> Quick Q', delay: 95 },
  { text: '> Quick Qu', delay: 95 },
  { text: '> Quick Qui', delay: 95 },
  { text: '> Quick Quiz', delay: 95 },
  { text: '> Quick Quiz ', delay: 95 },
  { text: '> Quick Quiz D', delay: 95 },
  { text: '> Quick Quiz De', delay: 95 },
  { text: '> Quick Quiz Dev', delay: 0 },
] as const;

const animatedText = ref<string>(frames[0].text);
const actionVisible = ref(false);
let animationTimer: number | undefined;

onMounted(() => {
  if (prefersReducedMotion()) {
    animatedText.value = frames[frames.length - 1]?.text ?? frames[0].text;
    actionVisible.value = true;
    return;
  }

  scheduleFrame(1);
});

onUnmounted(() => {
  if (animationTimer !== undefined) {
    window.clearTimeout(animationTimer);
  }
});

function scheduleFrame(frameIndex: number) {
  const currentFrame = frames[frameIndex];
  const previousDelay = frames[frameIndex - 1]?.delay ?? 0;

  if (!currentFrame) {
    actionVisible.value = true;
    return;
  }

  animationTimer = window.setTimeout(() => {
    animatedText.value = currentFrame.text;

    if (frameIndex === frames.length - 1) {
      actionVisible.value = true;
      return;
    }

    scheduleFrame(frameIndex + 1);
  }, previousDelay);
}

function prefersReducedMotion() {
  if (typeof window === 'undefined') {
    return true;
  }

  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}
</script>
