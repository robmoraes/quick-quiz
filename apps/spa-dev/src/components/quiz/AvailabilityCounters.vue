<template>
  <div
    v-if="sessionQuestionCounter || selectedTopicQuestionCounter"
    class="availability-counters"
    aria-live="polite"
  >
    <q-chip
      v-if="sessionQuestionCounter"
      square
      dense
      icon="fact_check"
      color="black"
      text-color="cyan-2"
      class="availability-counter"
    >
      {{
        t('game.sessionAvailability', {
          available: sessionQuestionCounter.available,
          total: sessionQuestionCounter.total,
        })
      }}
    </q-chip>
    <q-chip
      v-if="selectedTopicQuestionCounter"
      square
      dense
      icon="topic"
      color="black"
      text-color="amber-3"
      class="availability-counter"
    >
      {{
        t('game.topicAvailability', {
          available: selectedTopicQuestionCounter.available,
          total: selectedTopicQuestionCounter.total,
        })
      }}
    </q-chip>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { AvailabilityCounter } from './contracts';

defineProps<{
  sessionQuestionCounter: AvailabilityCounter | null;
  selectedTopicQuestionCounter: AvailabilityCounter | null;
}>();

const { t } = useI18n();
</script>
