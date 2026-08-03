<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
  defineProps<{
    open: boolean;
    placement?: 'bottom-start' | 'bottom-end' | 'bottom-center' | 'bottom-stretch';
    panelClass?: string;
    role?: string;
    ariaLabel?: string;
    panelId?: string;
  }>(),
  {
    placement: 'bottom-start',
    panelClass: undefined,
    role: undefined,
    ariaLabel: undefined,
    panelId: undefined,
  },
);

const emit = defineEmits<{ 'update:open': [boolean] }>();

const rootRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function getFocusable(container: HTMLElement): HTMLElement[] {
  return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR));
}

function getTriggerElement(): HTMLElement | null {
  if (!rootRef.value) return null;
  return getFocusable(rootRef.value).find((el) => !panelRef.value?.contains(el)) ?? null;
}

function close(): void {
  if (props.open) {
    emit('update:open', false);
  }
}

function onPointerDown(event: PointerEvent): void {
  const target = event.target as Node | null;
  if (rootRef.value && target && !rootRef.value.contains(target)) {
    close();
  }
}

// Focus-trap: while open, Tab/Shift+Tab cycle within the panel; Escape
// dismisses and returns focus to the trigger (design/03 §5, decision 7).
function onKeyDown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    const trigger = getTriggerElement();
    close();
    trigger?.focus();
    return;
  }

  if (event.key === 'Tab' && panelRef.value) {
    const focusable = getFocusable(panelRef.value);
    if (focusable.length === 0) return;

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

function addListeners(): void {
  document.addEventListener('pointerdown', onPointerDown);
  document.addEventListener('keydown', onKeyDown);
}

function removeListeners(): void {
  document.removeEventListener('pointerdown', onPointerDown);
  document.removeEventListener('keydown', onKeyDown);
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      addListeners();
    } else {
      removeListeners();
    }
  },
  { immediate: true },
);

onBeforeUnmount(removeListeners);
</script>

<template>
  <div ref="rootRef" class="ui-popover">
    <slot name="trigger" />
    <Transition name="ui-popover-fade">
      <div
        v-if="open"
        :id="panelId"
        ref="panelRef"
        class="ui-popover-panel"
        :class="[`ui-popover-panel--${placement}`, panelClass]"
        :role="role"
        :aria-label="ariaLabel"
        tabindex="-1"
      >
        <slot />
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.ui-popover {
  position: relative;
}

.ui-popover-panel {
  position: absolute;
  top: calc(100% + 6px);
  border-radius: var(--radius-lg);
  border: 1px solid var(--border);
  background: var(--surface);
  box-shadow: var(--shadow-md);
  padding: 8px;
  z-index: 200;
}

.ui-popover-panel--bottom-start {
  left: 0;
}

.ui-popover-panel--bottom-end {
  right: 0;
}

.ui-popover-panel--bottom-center {
  left: 50%;
  transform: translateX(-50%);
}

.ui-popover-panel--bottom-stretch {
  left: 0;
  right: 0;
}

/* design/03 §4: drawers/popovers animate 160ms with an 8px translate;
   the global prefers-reduced-motion rule (style.css) zeroes this for
   users who opt out, no extra media query needed here. */
.ui-popover-fade-enter-active,
.ui-popover-fade-leave-active {
  transition: opacity 160ms ease-out, transform 160ms ease-out;
}

.ui-popover-fade-enter-from,
.ui-popover-fade-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

.ui-popover-panel--bottom-center.ui-popover-fade-enter-from,
.ui-popover-panel--bottom-center.ui-popover-fade-leave-to {
  transform: translate(-50%, -8px);
}
</style>
