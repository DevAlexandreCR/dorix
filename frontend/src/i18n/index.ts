import { createI18n } from 'vue-i18n';
import { en } from './locales/en';
import { esCO } from './locales/es-CO';

export type AppLocale = 'es-CO' | 'en';

export type TranslationValues = Record<string, string | number | boolean | null | undefined>;

const messages = {
  'es-CO': esCO,
  en,
} as const;

export function normalizeLocale(value?: string | null): AppLocale {
  const normalized = (value ?? '').toLowerCase();

  if (normalized.startsWith('en')) {
    return 'en';
  }

  return 'es-CO';
}

function detectLocale(): AppLocale {
  if (typeof navigator === 'undefined') {
    return 'es-CO';
  }

  const candidates = [...navigator.languages, navigator.language];

  for (const candidate of candidates) {
    const locale = normalizeLocale(candidate);

    if (locale === 'en') {
      return 'en';
    }

    if (locale === 'es-CO') {
      return 'es-CO';
    }
  }

  return 'es-CO';
}

export const i18n = createI18n({
  legacy: false,
  locale: detectLocale(),
  fallbackLocale: 'en',
  messages,
});

export function currentLocale(): AppLocale {
  return normalizeLocale(i18n.global.locale.value);
}

export function translate(key: string, values: TranslationValues = {}): string {
  return i18n.global.t(key, values) as string;
}

export function translateCount(key: string, count: number, values: TranslationValues = {}): string {
  return i18n.global.t(key, {
    ...values,
    count,
  }) as string;
}
