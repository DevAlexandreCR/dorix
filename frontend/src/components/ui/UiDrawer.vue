<script setup lang="ts">
import { useId, ref } from 'vue';
import { useModalBehavior } from '../../composables/useModalBehavior';

const props = defineProps<{
  open: boolean;
  title: string;
  closeLabel: string;
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

const titleId = useId();
const panelRef = ref<HTMLElement | null>(null);

function close(): void {
  emit('update:open', false);
}

useModalBehavior({
  isOpen: () => props.open,
  panelRef,
  onClose: close,
});
</script>

<template>
  <Teleport to="body">
    <Transition name="ui-drawer-overlay-fade">
      <div v-if="open" class="ui-drawer-overlay" @click="close" />
    </Transition>
    <Transition name="ui-drawer-slide">
      <aside
        v-if="open"
        ref="panelRef"
        class="ui-drawer"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        tabindex="-1"
      >
        <div class="ui-drawer-head">
          <h3 :id="titleId" class="text-h1">{{ title }}</h3>
          <button type="button" class="ui-drawer-close" :aria-label="closeLabel" @click="close">
            <svg viewBox="0 0 16 16" width="16" height="16" fill="none" aria-hidden="true">
              <path
                d="M3 3L13 13M13 3L3 13"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
              />
            </svg>
          </button>
        </div>
        <div class="ui-drawer-body">
          <slot />
        </div>
        <div v-if="$slots.footer" class="ui-drawer-footer">
          <slot name="footer" />
        </div>
      </aside>
    </Transition>
  </Teleport>
</template>

<style scoped>
.ui-drawer-overlay {
  position: fixed;
  inset: 0;
  background: var(--overlay);
  z-index: 300;
}

.ui-drawer {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  width: 100vw;
  background: var(--surface);
  border-left: 1px solid var(--border);
  box-shadow: var(--shadow-md);
  z-index: 301;
  display: flex;
  flex-direction: column;
  outline: none;
}

@media (min-width: 1024px) {
  .ui-drawer {
    width: 480px;
  }
}

.ui-drawer-head {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-5);
  border-bottom: 1px solid var(--border);
}

.ui-drawer-head h3 {
  margin: 0;
}

.ui-drawer-close {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: var(--radius-md);
  color: var(--text-mute);
  transition: background-color 150ms ease-out, color 150ms ease-out;
}

.ui-drawer-close:hover {
  background: var(--muted);
  color: var(--text);
}

.ui-drawer-body {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-4) var(--space-5);
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.ui-drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-5);
  border-top: 1px solid var(--border);
}

/* design/03 §4: drawers animate 160ms with an 8px translate; the global
   prefers-reduced-motion rule (style.css) zeroes this for users who opt
   out, no extra media query needed here. */
.ui-drawer-slide-enter-active,
.ui-drawer-slide-leave-active {
  transition: transform 160ms ease-out, opacity 160ms ease-out;
}

.ui-drawer-slide-enter-from,
.ui-drawer-slide-leave-to {
  transform: translateX(16px);
  opacity: 0;
}

.ui-drawer-overlay-fade-enter-active,
.ui-drawer-overlay-fade-leave-active {
  transition: opacity 160ms ease-out;
}

.ui-drawer-overlay-fade-enter-from,
.ui-drawer-overlay-fade-leave-to {
  opacity: 0;
}
</style>
