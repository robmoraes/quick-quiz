<template>
  <div class="q-mb-md" role="group" :aria-label="t('themes.label')">
    <q-linear-progress
      v-if="loading"
      indeterminate
      color="cyan-3"
      class="q-mb-md"
      :aria-label="t('themes.loading')"
    />
    <q-select
      v-if="themes.length > 1"
      :model-value="selectedTheme"
      :options="themes"
      option-value="id"
      option-label="name"
      emit-value
      map-options
      filled
      :label="t('themes.label')"
      :disable="locked || loading"
      @update:model-value="emit('select', $event)"
    />
    <div v-else-if="themes[0]" class="text-subtitle1 text-cyan-3" aria-live="polite">
      {{ t('themes.selected', { name: themes[0].name }) }}
    </div>
    <div v-if="error" role="alert" class="error-line">
      {{ error }}
      <q-btn
        flat
        color="cyan-3"
        icon="refresh"
        :label="t('themes.retry')"
        :disable="locked || loading"
        @click="emit('retry')"
      />
    </div>
    <p v-else-if="!loading && !themes.length" role="status" class="text-grey-4">
      {{ t('themes.empty') }}
    </p>
    <div v-if="locked && !loading && themes.length > 1" class="text-caption text-grey-4 q-mt-xs">
      {{ t('themes.locked') }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { Theme } from 'src/services/api';

defineProps<{
  themes: Theme[];
  selectedTheme: string;
  loading: boolean;
  error: string;
  locked: boolean;
}>();
const emit = defineEmits<{ (event: 'select', id: string): void; (event: 'retry'): void }>();
const { t } = useI18n();
</script>
