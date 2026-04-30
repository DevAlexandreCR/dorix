import { computed } from 'vue';
import { useRoute } from 'vue-router';

export function useShellLayout() {
  const route = useRoute();

  return {
    activeSection: computed(() => route.meta.section as string | undefined),
    titleKey: computed(() => route.meta.titleKey as string | undefined),
  };
}
