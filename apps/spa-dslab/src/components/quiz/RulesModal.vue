<template>
  <div v-if="modelValue" class="rules-modal" role="dialog" aria-modal="true">
    <section class="rules-modal__panel">
      <div class="rules-modal__topline">
        <h2>{{ t('rules.title') }}</h2>
        <q-space />
        <q-btn
          flat
          round
          icon="close"
          :aria-label="t('rules.close')"
          @click="emit('update:modelValue', false)"
        />
      </div>
      <pre class="rules-modal__document">{{ documentText }}</pre>
    </section>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { RulesModalEmit, RulesModalProps } from './contracts';

defineProps<RulesModalProps>();
const emit = defineEmits<RulesModalEmit>();
const { t } = useI18n();
</script>
