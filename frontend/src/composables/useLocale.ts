import { computed } from 'vue';
import {
  DEFAULT_LOCALE,
  type AppLocale,
  writeStoredLocale,
} from '../app/providers/locale';
import { i18n } from '../i18n';

function setLocale(locale: AppLocale): void {
  i18n.global.locale.value = locale;
  writeStoredLocale(locale);
}

export function useLocale() {
  return {
    locale: computed(() => i18n.global.locale.value as AppLocale),
    setLocale,
    toggleLocale: () =>
      setLocale(i18n.global.locale.value === DEFAULT_LOCALE ? 'en' : DEFAULT_LOCALE),
  };
}
