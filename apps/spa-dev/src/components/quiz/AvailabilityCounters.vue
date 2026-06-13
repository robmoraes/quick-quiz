<template>
  <div v-if="sessionQuestionCounter || selectedTopicQuestionCounter" class="row">
    <q-card v-if="sessionQuestionCounter" dark bordered class="col q-mr-sm">
      <q-card-section class="text-center">
        <div class="text-h6">{{ t('game.availabilityCards.sessionLabel') }}</div>
        <div class="text-subtitle2">
          {{ counterLabel(sessionQuestionCounter) }}
        </div>
      </q-card-section>
      <q-separator dark inset />
      <q-card-section class="text-center text-h4">
        {{ counterValue(sessionQuestionCounter) }}
      </q-card-section>
    </q-card>
    <q-card v-if="selectedTopicQuestionCounter" dark bordered class="col">
      <q-card-section class="text-center">
        <div class="text-h6">{{ t('game.availabilityCards.topicLabel') }}</div>
        {{ counterLabel(selectedTopicQuestionCounter) }}
      </q-card-section>
      <q-separator dark inset />
      <q-card-section class="text-center text-h4">
        {{ counterValue(selectedTopicQuestionCounter) }}
      </q-card-section>
    </q-card>
  </div>
</template>

<script setup lang="ts">
// import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { AvailabilityCounter } from './contracts';

defineProps<{
  sessionQuestionCounter: AvailabilityCounter | null;
  selectedTopicQuestionCounter: AvailabilityCounter | null;
}>();

const { t } = useI18n();

// const sessionTooltip = computed(() => counterTooltip('session', props.sessionQuestionCounter));
// const topicTooltip = computed(() => counterTooltip('topic', props.selectedTopicQuestionCounter));

function counterValue(counter: AvailabilityCounter) {
  const answered = counter.total - counter.available;
  return counter.active ? `${answered}/${counter.total}` : `${counter.total}`;
}

function counterLabel(counter: AvailabilityCounter) {
  if (counter.active) {
    return `${t('game.availabilityCards.answeredLabel')}/${t('game.availabilityCards.totalLabel')}`;
  }
  return t('game.availabilityCards.totalLabel');
}

// function counterTooltip(type: 'session' | 'topic', counter: AvailabilityCounter | null) {
//   if (!counter) {
//     return '';
//   }

//   const key = counter.active ? 'activeTooltip' : 'inactiveTooltip';
//   return t(`game.availabilityCards.${type}.${key}`, {
//     available: counter.available,
//     total: counter.total,
//   });
// }
</script>
