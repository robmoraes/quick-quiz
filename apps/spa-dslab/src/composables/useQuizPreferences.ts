import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  getActiveLocale,
  getLocalePreference,
  setLocalePreference,
  type LocalePreference,
} from 'src/i18n/locale';
import {
  getAudioSettings,
  setAudioSettings,
  type AudioSettings,
} from 'src/services/audio-settings';
import { printDevtoolsWelcome } from 'src/services/devtools-console';
import type { LocalePreferenceOption } from 'src/components/quiz/contracts';

export function useQuizPreferences() {
  const { t, locale } = useI18n();

  const settingsOpen = ref(false);
  const selectedLocalePreference = ref<LocalePreference>(getLocalePreference());
  const selectedAudioSettings = ref<AudioSettings>(getAudioSettings());

  const localePreferenceOptions = computed<LocalePreferenceOption[]>(() => [
    { label: t('settings.locales.browser'), value: 'browser' },
    { label: t('settings.locales.ptBR'), value: 'pt-BR' },
    { label: t('settings.locales.enUS'), value: 'en-US' },
  ]);

  const selectedSoundsVolumePercent = computed({
    get: () => Math.round(selectedAudioSettings.value.soundsVolume * 100),
    set: (value: number) => {
      selectedAudioSettings.value = {
        ...selectedAudioSettings.value,
        soundsVolume: volumePercentToRatio(value),
      };
    },
  });

  const selectedMusicVolumePercent = computed({
    get: () => Math.round(selectedAudioSettings.value.musicVolume * 100),
    set: (value: number) => {
      selectedAudioSettings.value = {
        ...selectedAudioSettings.value,
        musicVolume: volumePercentToRatio(value),
      };
    },
  });

  function saveSettings() {
    const previousLocale = locale.value;
    setLocalePreference(selectedLocalePreference.value);
    setAudioSettings(selectedAudioSettings.value);
    const nextLocale = getActiveLocale();
    locale.value = nextLocale;
    if (previousLocale !== nextLocale) {
      printDevtoolsWelcome(t('devtools.welcome'));
    }
    settingsOpen.value = false;
  }

  function loadSettingsDraft() {
    selectedLocalePreference.value = getLocalePreference();
    selectedAudioSettings.value = getAudioSettings();
  }

  return {
    locale,
    settingsOpen,
    selectedLocalePreference,
    selectedAudioSettings,
    localePreferenceOptions,
    selectedSoundsVolumePercent,
    selectedMusicVolumePercent,
    saveSettings,
    loadSettingsDraft,
  };
}

function volumePercentToRatio(value: number) {
  return Math.min(1, Math.max(0, value / 100));
}
