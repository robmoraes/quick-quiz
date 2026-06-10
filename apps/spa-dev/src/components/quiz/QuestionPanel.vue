<template>
  <section class="question-panel">
    <div class="run-topline">
      <q-chip square color="black" text-color="amber-3">{{ selectedTopicLabel }}</q-chip>
      <q-chip square color="black" text-color="cyan-2">{{ selectedDifficultyLabel }}</q-chip>
    </div>

    <q-linear-progress
      size="10px"
      rounded
      color="amber-7"
      track-color="grey-9"
      :value="currentQuestion.current / currentQuestion.total"
    />

    <div class="question-meta">
      {{
        t('game.questionProgress', {
          current: currentQuestion.current,
          total: currentQuestion.total,
        })
      }}
    </div>

    <h2>{{ currentQuestion.prompt }}</h2>

    <div class="row q-col-gutter-sm q-mt-md">
      <div v-for="option in currentQuestion.options" :key="option.id" class="col-12">
        <q-btn
          no-caps
          unelevated
          class="option-button full-width"
          :class="{ 'option-button--locked': busy || feedback !== 'idle' }"
          :disable="busy || feedback !== 'idle'"
          @click="emit('answer', option.id)"
        >
          {{ option.text }}
        </q-btn>
      </div>
    </div>

    <div v-if="canEndSession" class="command-actions row q-col-gutter-sm">
      <div class="col-12">
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
import { useI18n } from 'vue-i18n';
import type { QuestionPanelEmit, QuestionPanelProps } from './contracts';

defineProps<QuestionPanelProps>();
const emit = defineEmits<QuestionPanelEmit>();
const { t } = useI18n();
</script>
