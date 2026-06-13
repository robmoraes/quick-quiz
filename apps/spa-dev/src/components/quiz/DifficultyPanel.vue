<template>
  <section class="start-panel">
    <div class="brand-row">
      <div>
        <h1>{{ t('app.title') }}</h1>
        <p>{{ selectedTopicLabel }}</p>
      </div>
      <q-space />
      <q-btn
        flat
        round
        icon="settings"
        :aria-label="t('settings.title')"
        @click="emit('open-settings')"
      />
    </div>

    <div class="topic-line">
      <q-chip square color="black" text-color="amber-3">{{ selectedTopicLabel }}</q-chip>
      <AvailabilityCounters
        :session-question-counter="sessionQuestionCounter"
        :selected-topic-question-counter="selectedTopicQuestionCounter"
      />
      <q-space />
      <q-btn
        flat
        dense
        icon="arrow_back"
        :label="t('actions.changeTopic')"
        :disable="busy"
        @click="emit('change-topic')"
      />
    </div>

    <q-btn
      outline
      no-caps
      color="cyan-3"
      icon="article"
      :label="t('rules.button')"
      :aria-label="t('rules.button')"
      class="rules-button"
      @click="emit('open-rules')"
    />

    <div class="row q-col-gutter-sm q-mb-lg">
      <div v-for="option in difficultyOptions" :key="option.value" class="col-12 col-sm-3">
        <q-radio
          v-model="selectedDifficultyModel"
          :val="option.value"
          :label="option.label"
          :color="option.color"
          keep-color
          class="difficulty-option full-width"
          :class="option.class"
          :disable="option.disable"
          @update:model-value="emit('difficulty-changed')"
        />
      </div>
    </div>

    <q-banner
      v-if="selectedDifficultyInfo"
      class="difficulty-banner q-mb-lg"
      :class="selectedDifficultyClass"
    >
      <span class="difficulty-banner__severity">{{ selectedDifficultyPrefix }}:</span>
      <span class="difficulty-banner__message">{{ selectedDifficultyMessage }}</span>
    </q-banner>

    <q-banner v-else class="topic-description q-mb-lg">
      {{ t('errors.noDifficulties') }}
    </q-banner>

    <div v-if="errorMessage" class="error-line">{{ errorMessage }}</div>

    <div class="command-actions row q-col-gutter-sm">
      <div :class="canEndSession ? 'col-6' : 'col-12'">
        <q-btn
          unelevated
          color="amber-8"
          text-color="black"
          icon="play_arrow"
          :label="startRunActionLabel"
          :aria-label="t('actions.startRun')"
          :loading="busy"
          :disable="!canStart"
          class="full-width"
          @click="emit('start-run')"
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
import type { DifficultyPanelEmit, DifficultyPanelProps } from './contracts';

const props = defineProps<DifficultyPanelProps>();
const emit = defineEmits<DifficultyPanelEmit>();
const { t } = useI18n();

const selectedDifficultyModel = computed({
  get: () => props.selectedDifficulty,
  set: (value: DifficultyPanelProps['selectedDifficulty']) => {
    emit('update:selectedDifficulty', value);
  },
});
</script>
