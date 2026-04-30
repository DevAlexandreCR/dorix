export type ThemeMode = 'dark' | 'light';

export const THEME_STORAGE_KEY = 'dorix.theme';

function isThemeMode(value: string | null): value is ThemeMode {
  return value === 'dark' || value === 'light';
}

export function readStoredTheme(): ThemeMode | null {
  if (typeof window === 'undefined') {
    return null;
  }

  const value = window.localStorage.getItem(THEME_STORAGE_KEY);

  return isThemeMode(value) ? value : null;
}

export function resolveSystemTheme(): ThemeMode {
  if (typeof window === 'undefined') {
    return 'dark';
  }

  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function resolveInitialTheme(): ThemeMode {
  return readStoredTheme() ?? resolveSystemTheme();
}

export function applyTheme(theme: ThemeMode): void {
  if (typeof document === 'undefined') {
    return;
  }

  document.documentElement.dataset.theme = theme;
  document.documentElement.style.colorScheme = theme;
}

export function persistTheme(theme: ThemeMode): void {
  if (typeof window === 'undefined') {
    return;
  }

  window.localStorage.setItem(THEME_STORAGE_KEY, theme);
}

export function initTheme(): ThemeMode {
  const theme = resolveInitialTheme();
  applyTheme(theme);
  return theme;
}
