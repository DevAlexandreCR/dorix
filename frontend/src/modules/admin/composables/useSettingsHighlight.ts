import { type Ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// Consumes `?highlight=<key>` after AdminNav's settings search navigates
// somewhere (task 4.9 / design.md decision 8): finds the element tagged
// `data-settings-key="<key>"` inside `containerRef` (the RouterView's
// wrapper in AdminLayout), scrolls it into view and flashes it, then
// strips the query param so back/forward/refresh doesn't replay it.
//
// The target card is often behind an async `overview` fetch (useAdminResource
// caches per session, but the very first admin load still awaits it), so
// this polls briefly for the element rather than assuming it exists as soon
// as the route changes.
const QUERY_KEY = 'highlight';
const FLASH_CLASS = 'settings-highlight-flash';
const FLASH_DURATION_MS = 900;
const MAX_ATTEMPTS = 40;
const RETRY_DELAY_MS = 50;

function prefersReducedMotion(): boolean {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function useSettingsHighlight(containerRef: Ref<HTMLElement | null>): void {
  const route = useRoute();
  const router = useRouter();

  function clearHighlightQuery(): void {
    const { [QUERY_KEY]: _removed, ...rest } = route.query;
    void router.replace({ path: route.path, query: rest });
  }

  function attempt(key: string, tries: number): void {
    const container = containerRef.value;
    const target = container?.querySelector<HTMLElement>(`[data-settings-key="${key}"]`) ?? null;

    if (target) {
      target.scrollIntoView({
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        block: 'center',
      });
      target.classList.remove(FLASH_CLASS);
      // Force reflow so the animation can retrigger even if the same
      // target was just highlighted a moment ago.
      void target.offsetWidth;
      target.classList.add(FLASH_CLASS);
      window.setTimeout(() => target.classList.remove(FLASH_CLASS), FLASH_DURATION_MS);
      clearHighlightQuery();
      return;
    }

    if (tries < MAX_ATTEMPTS) {
      window.setTimeout(() => attempt(key, tries + 1), RETRY_DELAY_MS);
    }
  }

  watch(
    () => route.query[QUERY_KEY],
    (value) => {
      if (typeof value === 'string' && value) {
        attempt(value, 0);
      }
    },
    { immediate: true, flush: 'post' },
  );
}
