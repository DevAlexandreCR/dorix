<script setup lang="ts">
import { useId } from 'vue';
import { useI18n } from 'vue-i18n';

// Organización↔línea scope selector (design/05 assistant/behavior|tools,
// design.md decision 6 — line scope is all-or-nothing, since the line
// endpoints only support a whole-row upsert/delete). Shared by every
// screen that offers a per-line override of an otherwise tenant-wide
// setting (task 4.1 assistant/behavior, task 4.2 assistant/tools).
//
// This is a CONTROLLED component, same convention as UiModal/SummaryCard:
// `scope` reflects the value the parent has actually committed to. It does
// NOT change on its own when the user picks a different option — instead it
// emits `request-switch` and lets the parent decide (design.md decision 6:
// "El ScopePicker emite `request-switch` antes de cambiar para interceptar
// formularios sucios"). If the parent doesn't update `scope` in response
// (e.g. it's showing a confirmation the user might cancel), the visible
// selection must NOT have already flipped — a plain `<select v-model>`
// can't do this (the browser updates its own display before Vue re-renders),
// so the change handler manually reverts the DOM value to the last
// confirmed `scope` and only the `scope` prop update (once the parent
// commits) is allowed to move the visible selection forward.
//
// "Ámbito" / "Organización" / the two hint sentences are identical at every
// call site (same exception class as TechValue's copy/copied labels), so
// they're owned here via i18n rather than repeated as props.
const props = defineProps<{
  scope: 'tenant' | number;
  lines: { id: number; label: string }[];
  disabled?: boolean;
}>();

const emit = defineEmits<{
  'request-switch': ['tenant' | number];
}>();

const { t } = useI18n();
const selectId = useId();

function scopeToValue(scope: 'tenant' | number): string {
  return scope === 'tenant' ? 'tenant' : String(scope);
}

function onChange(event: Event): void {
  const target = event.target as HTMLSelectElement;
  const requested = target.value === 'tenant' ? 'tenant' : Number(target.value);

  // Always revert the native selection first — only a `scope` prop change
  // coming back from the parent is allowed to move it.
  target.value = scopeToValue(props.scope);

  if (requested !== props.scope) {
    emit('request-switch', requested);
  }
}
</script>

<template>
  <div class="scope-picker">
    <label class="text-small scope-picker-label" :for="selectId">{{ t('admin.shared.scopeLabel') }}</label>
    <select
      :id="selectId"
      class="input-base scope-picker-select"
      :value="scopeToValue(scope)"
      :disabled="disabled"
      @change="onChange"
    >
      <option value="tenant">{{ t('admin.shared.scopeOrganization') }}</option>
      <option v-for="line in lines" :key="line.id" :value="String(line.id)">{{ line.label }}</option>
    </select>
    <p class="text-small scope-picker-hint">
      {{ scope === 'tenant' ? t('admin.shared.scopeOrgHint') : t('admin.shared.scopeLineHint') }}
    </p>
  </div>
</template>

<style scoped>
.scope-picker {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--bg);
  margin-bottom: var(--space-4);
}

.scope-picker-label {
  color: var(--text-mute);
  flex-shrink: 0;
}

.scope-picker-select {
  width: auto;
  min-width: 200px;
}

.scope-picker-hint {
  color: var(--text-mute);
  flex-basis: 100%;
}

@media (min-width: 640px) {
  .scope-picker-hint {
    flex-basis: auto;
    margin-left: auto;
  }
}
</style>
