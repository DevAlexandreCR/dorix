import { nextTick, onBeforeUnmount, watch, type Ref } from 'vue';

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function getFocusable(container: HTMLElement): HTMLElement[] {
  return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR));
}

/**
 * Shared dialog-surface behavior for UiDrawer/UiModal (design/03 §5,
 * ui-design-system spec "Drawer con teclado"): Escape-to-close,
 * Tab/Shift+Tab focus trap, initial focus on open, focus returned to the
 * triggering element on close, and a document body scroll lock.
 *
 * Backdrop-click-to-close is intentionally NOT handled here — it is a
 * single `@click` on the overlay element, trivial enough to stay inline
 * in each component's template.
 */
export function useModalBehavior(options: {
  isOpen: () => boolean;
  panelRef: Ref<HTMLElement | null>;
  onClose: () => void;
}): void {
  let previousActiveElement: HTMLElement | null = null;
  let previousBodyOverflow = '';

  function focusPanel(): void {
    const panel = options.panelRef.value;
    if (!panel) return;
    const focusable = getFocusable(panel);
    (focusable[0] ?? panel).focus();
  }

  function onKeyDown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      options.onClose();
      return;
    }

    if (event.key === 'Tab' && options.panelRef.value) {
      const focusable = getFocusable(options.panelRef.value);
      if (focusable.length === 0) {
        event.preventDefault();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  }

  function lockScroll(): void {
    previousBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }

  function unlockScroll(): void {
    document.body.style.overflow = previousBodyOverflow;
  }

  function addListeners(): void {
    document.addEventListener('keydown', onKeyDown);
  }

  function removeListeners(): void {
    document.removeEventListener('keydown', onKeyDown);
  }

  watch(
    () => options.isOpen(),
    (isOpen) => {
      if (isOpen) {
        previousActiveElement = document.activeElement as HTMLElement | null;
        lockScroll();
        addListeners();
        void nextTick(focusPanel);
      } else {
        removeListeners();
        unlockScroll();
        previousActiveElement?.focus();
        previousActiveElement = null;
      }
    },
    { immediate: true },
  );

  onBeforeUnmount(() => {
    removeListeners();
    if (options.isOpen()) {
      unlockScroll();
    }
  });
}
