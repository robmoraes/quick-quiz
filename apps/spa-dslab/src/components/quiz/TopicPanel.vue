<template>
  <section class="start-panel">
    <div class="row items-center q-mb-md">
      <BrandLogoPair />

      <div class="col text-h4 text-center">{{ t('beforeGame.startPanel.title') }}</div>

      <q-space />

      <q-btn
        flat
        round
        icon="help_outline"
        :aria-label="t('rules.button')"
        @click="emit('open-rules')"
      >
        <q-tooltip>{{ t('rules.button') }}</q-tooltip>
      </q-btn>
      <q-btn
        flat
        round
        icon="settings"
        :aria-label="t('settings.title')"
        @click="emit('open-settings')"
      />
    </div>

    <div class="selectors">
      <q-select
        v-model="selectedTopicModel"
        filled
        clearable
        emit-value
        fill-input
        hide-selected
        map-options
        use-input
        input-debounce="0"
        :label="t('form.topic')"
        :loading="loadingCatalog"
        :options="topicOptions"
        option-disable="disable"
        @clear="emit('clear-topic')"
        @filter="filterTopicOptions"
        @update:model-value="emit('topic-changed', $event)"
      />
    </div>

    <q-banner v-if="selectedTopicDescription" rounded class="topic-description q-mb-lg">
      <template #avatar>
        <q-icon name="info" color="cyan-3" size="28px" />
      </template>
      <div class="topic-description__content">
        <div class="topic-description__title">{{ t('topicInfo.title') }}</div>
        <div class="topic-description__text">{{ selectedTopicDescription }}</div>
      </div>
    </q-banner>

    <AvailabilityCounters
      :session-question-counter="sessionQuestionCounter"
      :selected-topic-question-counter="selectedTopicQuestionCounter"
      class="q-mb-lg"
    />

    <div v-if="errorMessage" class="error-line">{{ errorMessage }}</div>

    <div class="command-actions row q-col-gutter-sm">
      <div :class="canEndSession ? 'col-6' : 'col-12'">
        <q-btn
          unelevated
          color="amber-8"
          text-color="black"
          icon="arrow_forward"
          :label="nextActionLabel"
          :aria-label="t('actions.next')"
          :loading="busy"
          :disable="!canAdvance"
          class="full-width"
          @click="emit('next')"
        />
      </div>
      <div v-if="canEndSession" class="col-6">
        <q-btn
          flat
          color="white"
          icon="logout"
          :label="endSessionActionLabel"
          :aria-label="t('actions.endSession')"
          :disable="busy"
          class="full-width"
          @click="emit('end-session')"
        />
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AvailabilityCounters from './AvailabilityCounters.vue';
import BrandLogoPair from './BrandLogoPair.vue';
import type { TopicFilterUpdater, TopicPanelEmit, TopicPanelProps } from './contracts';

const props = defineProps<TopicPanelProps>();
const emit = defineEmits<TopicPanelEmit>();
const { t } = useI18n();

const selectedTopicModel = computed({
  get: () => props.selectedTopic,
  set: (value: string | null) => {
    emit('update:selectedTopic', value ?? '');
  },
});

function filterTopicOptions(inputValue: string, update: TopicFilterUpdater) {
  emit('filter-topic-options', inputValue, update);
}
</script>
