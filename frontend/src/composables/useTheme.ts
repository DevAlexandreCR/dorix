import { computed, ref } from 'vue';
import {
  applyTheme,
  persistTheme,
  resolveInitialTheme,
  type ThemeMode,
} from '../app/providers/theme';

const theme = ref<ThemeMode>(resolveInitialTheme());
applyTheme(theme.value);

function setTheme(value: ThemeMode): void {
  theme.value = value;
  applyTheme(value);
  persistTheme(value);
}

export function useTheme() {
  return {
    isDark: computed(() => theme.value === 'dark'),
    setTheme,
    theme: computed(() => theme.value),
    toggleTheme: () => setTheme(theme.value === 'dark' ? 'light' : 'dark'),
  };
}
