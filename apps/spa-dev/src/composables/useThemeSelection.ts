import { computed, ref, type Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getThemes, type Theme } from 'src/services/api';
import { configuredThemeTag } from 'src/services/theme-config';

export function useThemeSelection(selectedTheme: Ref<string>, locked: Ref<boolean>) {
  const { t } = useI18n();
  const themes = ref<Theme[]>([]);
  const loadingThemes = ref(false);
  const themeError = ref('');
  const selectedThemeMetadata = computed(() =>
    themes.value.find((theme) => theme.id === selectedTheme.value),
  );

  async function loadThemes(): Promise<boolean> {
    if (locked.value || loadingThemes.value) return false;
    loadingThemes.value = true;
    themeError.value = '';
    let tag: string;
    try {
      tag = configuredThemeTag();
    } catch {
      themeError.value = t('themes.configurationError');
      loadingThemes.value = false;
      return false;
    }
    try {
      // The server filters too. Never offer a theme outside the site's scope,
      // even if a stale proxy returns a broader discovery response.
      themes.value = (await getThemes(tag)).filter(
        (theme) => theme.active && theme.tags.includes(tag),
      );
      selectedTheme.value = themes.value.some((theme) => theme.id === 'dev')
        ? 'dev'
        : (themes.value[0]?.id ?? '');
      return selectedTheme.value !== '';
    } catch {
      themes.value = [];
      selectedTheme.value = '';
      themeError.value = t('themes.loadError');
      return false;
    } finally {
      loadingThemes.value = false;
    }
  }

  function selectTheme(id: string): boolean {
    if (
      locked.value ||
      loadingThemes.value ||
      id === selectedTheme.value ||
      !themes.value.some((theme) => theme.id === id)
    ) {
      return false;
    }
    selectedTheme.value = id;
    return true;
  }

  return { themes, loadingThemes, themeError, selectedThemeMetadata, loadThemes, selectTheme };
}
