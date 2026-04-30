export type AppLocale = 'es-CO' | 'en';

export const DEFAULT_LOCALE: AppLocale = 'es-CO';
export const LOCALE_STORAGE_KEY = 'dorix.locale';

export function normalizeLocale(value?: string | null): AppLocale {
  const normalized = (value ?? '').toLowerCase();

  if (normalized.startsWith('en')) {
    return 'en';
  }

  return DEFAULT_LOCALE;
}

export function readStoredLocale(): AppLocale | null {
  if (typeof window === 'undefined') {
    return null;
  }

  const value = window.localStorage.getItem(LOCALE_STORAGE_KEY);

  return value ? normalizeLocale(value) : null;
}

export function writeStoredLocale(locale: AppLocale): void {
  if (typeof window === 'undefined') {
    return;
  }

  window.localStorage.setItem(LOCALE_STORAGE_KEY, locale);
}
