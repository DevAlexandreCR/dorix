import { createI18n } from 'vue-i18n';
import {
  DEFAULT_LOCALE,
  normalizeLocale,
  readStoredLocale,
  type AppLocale,
} from '../app/providers/locale';
import { en } from './locales/en';
import { esCO } from './locales/es-CO';

export type TranslationValues = Record<string, string | number | boolean | null | undefined>;

const messages = {
  'es-CO': esCO,
  en,
} as const;

function resolveInitialLocale(): AppLocale {
  return readStoredLocale() ?? DEFAULT_LOCALE;
}

export const i18n = createI18n({
  legacy: false,
  locale: resolveInitialLocale(),
  fallbackLocale: DEFAULT_LOCALE,
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
