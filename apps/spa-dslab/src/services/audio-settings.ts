const audioSettingsKey = 'quickquiz.audioSettings';

export interface AudioSettings {
  soundsEnabled: boolean;
  soundsVolume: number;
  musicEnabled: boolean;
  musicVolume: number;
}

export const defaultAudioSettings: AudioSettings = {
  soundsEnabled: true,
  soundsVolume: 0.8,
  musicEnabled: false,
  musicVolume: 0.5,
};

export function getAudioSettings(): AudioSettings {
  const stored = window.localStorage.getItem(audioSettingsKey);
  if (!stored) {
    return { ...defaultAudioSettings };
  }

  try {
    return normalizeAudioSettings(JSON.parse(stored) as Partial<AudioSettings>);
  } catch {
    return { ...defaultAudioSettings };
  }
}

export function setAudioSettings(settings: AudioSettings) {
  window.localStorage.setItem(audioSettingsKey, JSON.stringify(normalizeAudioSettings(settings)));
}

function normalizeAudioSettings(settings: Partial<AudioSettings>): AudioSettings {
  return {
    soundsEnabled:
      typeof settings.soundsEnabled === 'boolean'
        ? settings.soundsEnabled
        : defaultAudioSettings.soundsEnabled,
    soundsVolume: normalizeVolume(settings.soundsVolume, defaultAudioSettings.soundsVolume),
    musicEnabled: false,
    musicVolume: normalizeVolume(settings.musicVolume, defaultAudioSettings.musicVolume),
  };
}

function normalizeVolume(value: unknown, fallback: number) {
  return typeof value === 'number' && Number.isFinite(value)
    ? Math.min(1, Math.max(0, value))
    : fallback;
}
