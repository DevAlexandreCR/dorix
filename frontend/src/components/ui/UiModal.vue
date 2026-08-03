<script setup lang="ts">
import { useId, ref } from 'vue';
import { useModalBehavior } from '../../composables/useModalBehavior';
import UiButton from './UiButton.vue';

const props = withDefaults(
  defineProps<{
    open: boolean;
    title: string;
    message?: string;
    confirmLabel: string;
    cancelLabel: string;
    danger?: boolean;
    confirmLoading?: boolean;
  }>(),
  {
    message: undefined,
    danger: false,
    confirmLoading: false,
  },
);

const emit = defineEmits<{
  'update:open': [boolean];
  confirm: [];
  cancel: [];
}>();

const titleId = useId();
const panelRef = ref<HTMLElement | null>(null);

function close(): void {
  emit('update:open', false);
  emit('cancel');
}

function confirm(): void {
  emit('confirm');
}

useModalBehavior({
  isOpen: () => props.open,
  panelRef,
  onClose: close,
});
</script>

<template>
  <Teleport to="body">
    <Transition name="ui-modal-fade">
      <div v-if="open" class="ui-modal-overlay" @click="close" />
    </Transition>
    <Transition name="ui-modal-pop">
      <div
        v-if="open"
        ref="panelRef"
        class="ui-modal"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        tabindex="-1"
      >
        <div class="ui-modal-body">
          <h3 :id="titleId" class="text-h1">{{ title }}</h3>
          <p v-if="message" class="text-body ui-modal-message">{{ message }}</p>
          <slot />
        </div>
        <div class="ui-modal-footer">
          <UiButton variant="secondary" :disabled="confirmLoading" @click="close">
            {{ cancelLabel }}
          </UiButton>
          <UiButton
            :variant="danger ? 'danger' : 'primary'"
            :loading="confirmLoading"
            @click="confirm"
          >
            {{ confirmLabel }}
          </UiButton>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.ui-modal-overlay {
  position: fixed;
  inset: 0;
  background: var(--overlay);
  z-index: 300;
}

.ui-modal {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: min(420px, calc(100vw - 32px));
  max-height: calc(100vh - 64px);
  overflow-y: auto;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-md);
  z-index: 301;
  outline: none;
}

.ui-modal-body {
  padding: var(--space-5);
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.ui-modal-body h3 {
  margin: 0;
}

.ui-modal-message {
  color: var(--text-soft);
  margin: 0;
}

.ui-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
  padding: 0 var(--space-5) var(--space-5);
}

/* design/03 §4: overlays animate 160ms; the global prefers-reduced-motion
   rule (style.css) zeroes this for users who opt out. */
.ui-modal-fade-enter-active,
.ui-modal-fade-leave-active {
  transition: opacity 160ms ease-out;
}

.ui-modal-fade-enter-from,
.ui-modal-fade-leave-to {
  opacity: 0;
}

.ui-modal-pop-enter-active,
.ui-modal-pop-leave-active {
  transition: opacity 160ms ease-out, transform 160ms ease-out;
}

.ui-modal-pop-enter-from,
.ui-modal-pop-leave-to {
  opacity: 0;
  transform: translate(-50%, calc(-50% + 8px));
}
</style>
