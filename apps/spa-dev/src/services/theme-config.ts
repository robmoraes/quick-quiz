export const advertisingTheme = 'dev';

export function configuredThemeTag(): string {
  const value = window.__QUICKQUIZ_CONFIG__?.themeTag;
  if (value === undefined) return 'dev';
  if (
    typeof value !== 'string' ||
    value.length > 50 ||
    [...value].some((char) => char.charCodeAt(0) > 127)
  ) {
    throw new Error('Invalid theme tag configuration');
  }
  const tag = value.replace(/^[ \t\r\n\f\v]+|[ \t\r\n\f\v]+$/g, '').toLowerCase();
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(tag)) {
    throw new Error('Invalid theme tag configuration');
  }
  return tag;
}
