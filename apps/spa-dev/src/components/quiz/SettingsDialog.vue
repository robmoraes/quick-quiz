<template>
  <q-dialog v-model="dialogModel" @before-show="emit('before-show')">
    <q-card style="width: 100%; max-width: 420px">
      <q-card-section class="row items-center">
        <div class="text-h6">{{ t('settings.title') }}</div>
        <q-space />
        <q-btn v-close-popup flat round dense icon="close" :aria-label="t('settings.close')" />
      </q-card-section>

      <q-card-section class="settings-stack">
        <q-select
          v-model="selectedLocalePreferenceModel"
          standout
          emit-value
          map-options
          :label="t('settings.locale')"
          :options="localePreferenceOptions"
        />

        <div class="settings-audio">
          <div class="settings-group__title">{{ t('settings.audio.title') }}</div>

          <div class="row items-center q-col-gutter-sm settings-audio__row">
            <div class="col-4 settings-audio__label">{{ t('settings.audio.music') }}</div>
            <div class="col">
              <q-slider
                v-model="selectedMusicVolumePercentModel"
                :min="0"
                :max="100"
                :step="5"
                color="amber-8"
                :aria-label="t('settings.audio.musicVolume')"
                disable
              />
            </div>
            <div class="col-auto">
              <q-toggle
                v-model="musicEnabledModel"
                color="amber-8"
                :aria-label="t('settings.audio.enableMusic')"
                disable
              />
            </div>
          </div>

          <div class="row items-center q-col-gutter-sm settings-audio__row">
            <div class="col-4 settings-audio__label">{{ t('settings.audio.soundEffects') }}</div>
            <div class="col">
              <q-slider
                v-model="selectedSoundsVolumePercentModel"
                :min="0"
                :max="100"
                :step="5"
                color="amber-8"
                :aria-label="t('settings.audio.soundsVolume')"
                :disable="!selectedAudioSettings.soundsEnabled"
              />
            </div>
            <div class="col-auto">
              <q-toggle
                v-model="soundsEnabledModel"
                color="amber-8"
                :aria-label="t('settings.audio.enableSounds')"
              />
            </div>
          </div>
        </div>
      </q-card-section>

      <q-card-actions align="right">
        <q-btn flat :label="t('settings.cancel')" v-close-popup />
        <q-btn
          unelevated
          color="amber-8"
          text-color="black"
          :label="t('settings.save')"
          @click="emit('save')"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { SettingsDialogEmit, SettingsDialogProps } from './contracts';

const props = defineProps<SettingsDialogProps>();
const emit = defineEmits<SettingsDialogEmit>();
const { t } = useI18n();

const dialogModel = computed({
  get: () => props.modelValue,
  set: (value: boolean) => {
    emit('update:modelValue', value);
  },
});

const selectedLocalePreferenceModel = computed({
  get: () => props.selectedLocalePreference,
  set: (value: SettingsDialogProps['selectedLocalePreference']) => {
    emit('update:selectedLocalePreference', value);
  },
});

const selectedMusicVolumePercentModel = computed({
  get: () => props.selectedMusicVolumePercent,
  set: (value: number) => {
    emit('update:selectedMusicVolumePercent', value);
  },
});

const selectedSoundsVolumePercentModel = computed({
  get: () => props.selectedSoundsVolumePercent,
  set: (value: number) => {
    emit('update:selectedSoundsVolumePercent', value);
  },
});

const musicEnabledModel = computed({
  get: () => props.selectedAudioSettings.musicEnabled,
  set: (value: boolean) => {
    emit('update:selectedAudioSettings', {
      ...props.selectedAudioSettings,
      musicEnabled: value,
    });
  },
});

const soundsEnabledModel = computed({
  get: () => props.selectedAudioSettings.soundsEnabled,
  set: (value: boolean) => {
    emit('update:selectedAudioSettings', {
      ...props.selectedAudioSettings,
      soundsEnabled: value,
    });
  },
});
</script>
