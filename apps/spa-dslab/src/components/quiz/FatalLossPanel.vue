<template>
  <section class="fatal-loss-panel">
    <div
      v-if="messageVisible"
      class="fatal-loss-panel__content fatal-loss-panel__content--visible"
    >
      <div class="fatal-loss-panel__eyebrow">{{ t('fatalLoss.eyebrow') }}</div>
      <h1>{{ t('fatalLoss.title') }}</h1>
      <p>{{ t('fatalLoss.message') }}</p>
      <q-btn
        unelevated
        color="red-10"
        text-color="white"
        icon="restart_alt"
        :label="t('actions.newSession')"
        @click="emit('new-session')"
      />
    </div>
  </section>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { FatalLossPanelEmit, FatalLossPanelProps } from './contracts';

defineProps<FatalLossPanelProps>();
const emit = defineEmits<FatalLossPanelEmit>();
const { t } = useI18n();
</script>
