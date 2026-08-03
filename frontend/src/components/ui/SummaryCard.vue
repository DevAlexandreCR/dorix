<script setup lang="ts">
import { ref, useId } from 'vue';
import UiButton from './UiButton.vue';

// "Resumen primero" pattern card (design/07 §1, design/03 §5): a
// collapsed summary that expands into its own editor with its own
// save/cancel — never a shared page-level "Guardar" button.
//
// `open` is a controlled v-model rather than internal state: the Edit
// button flips it to true, Cancel flips it back to false and emits
// `cancel`. `save` only emits — it does NOT auto-close (same convention
// as UiModal's `confirm`: the caller closes via `open` once its async
// mutation actually resolves, which is what `saving` is for: it shows
// the Save button's spinner and disables Cancel without blocking
// anything else).
//
// The "flash sutil" on save (design/03 §4) is deliberately NOT tied to
// `open` transitioning true→false, because Cancel also does that and
// canceling didn't change any state worth flashing. Instead `flash()` is
// exposed so the caller triggers it explicitly, only after a successful
// save (typically right when it also sets `open = false`).
const props = withDefaults(
  defineProps<{
    open: boolean;
    title: string;
    help?: string;
    editLabel: string;
    cancelLabel: string;
    saveLabel: string;
    saving?: boolean;
    disabled?: boolean;
  }>(),
  {
    help: undefined,
    saving: false,
    disabled: false,
  },
);

const emit = defineEmits<{
  'update:open': [boolean];
  save: [];
  cancel: [];
}>();

const titleId = useId();
const flashing = ref(false);

function startEdit(): void {
  emit('update:open', true);
}

function cancel(): void {
  emit('update:open', false);
  emit('cancel');
}

function save(): void {
  emit('save');
}

function flash(): void {
  flashing.value = false;
  requestAnimationFrame(() => {
    flashing.value = true;
  });
}

defineExpose({ flash });
</script>

<template>
  <article
    class="summary-card"
    :class="{ 'summary-card--flash': flashing }"
    role="group"
    :aria-labelledby="titleId"
    @animationend="flashing = false"
  >
    <div class="summary-card-row">
      <div class="summary-card-body">
        <p :id="titleId" class="text-h3 summary-card-title">{{ title }}</p>
        <p class="summary-card-state">
          <slot name="state" />
        </p>
        <p v-if="help" class="text-small summary-card-help">{{ help }}</p>
      </div>
      <UiButton
        v-if="!open"
        variant="ghost"
        size="sm"
        class="summary-card-edit"
        :disabled="disabled"
        @click="startEdit"
      >
        {{ editLabel }}
      </UiButton>
    </div>
    <div v-if="open" class="summary-card-editor">
      <slot />
      <div class="summary-card-actions">
        <UiButton variant="secondary" size="sm" :disabled="saving" @click="cancel">
          {{ cancelLabel }}
        </UiButton>
        <UiButton variant="primary" size="sm" :loading="saving" @click="save">
          {{ saveLabel }}
        </UiButton>
      </div>
    </div>
  </article>
</template>

<style scoped>
.summary-card {
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  padding: 14px 16px;
}

.summary-card--flash {
  animation: summary-card-flash 600ms ease-out;
}

@keyframes summary-card-flash {
  0% {
    background: var(--accent-subtle);
  }
  100% {
    background: var(--surface);
  }
}

.summary-card-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.summary-card-body {
  min-width: 0;
  flex: 1;
}

.summary-card-title {
  margin: 0 0 2px;
  color: var(--text-mute);
}

.summary-card-state {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 0.875rem;
  line-height: 1.25rem;
  font-weight: 550;
}

.summary-card-help {
  margin: 3px 0 0;
  color: var(--text-mute);
  max-width: 62ch;
}

.summary-card-edit {
  flex-shrink: 0;
}

.summary-card-editor {
  margin-top: 12px;
  border-top: 1px solid var(--border);
  padding-top: 12px;
}

.summary-card-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 12px;
}
</style>
