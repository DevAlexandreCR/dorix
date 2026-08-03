<script setup lang="ts">
import { useId, useTemplateRef } from 'vue';

export interface UiTabItem {
  id: string;
  label: string;
  disabled?: boolean;
}

const props = defineProps<{
  tabs: UiTabItem[];
  modelValue: string;
  ariaLabel?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [string] }>();

const baseId = useId();
const tabButtons = useTemplateRef<HTMLButtonElement[]>('tabButtons');

function tabId(id: string): string {
  return `${baseId}-tab-${id}`;
}

function panelId(id: string): string {
  return `${baseId}-panel-${id}`;
}

function select(id: string): void {
  emit('update:modelValue', id);
}

function focusIndex(index: number): void {
  const buttons = tabButtons.value ?? [];
  const button = buttons[index];
  button?.focus();
}

function onKeydown(event: KeyboardEvent, index: number): void {
  const enabledIndexes = props.tabs
    .map((tab, i) => ({ tab, i }))
    .filter(({ tab }) => !tab.disabled)
    .map(({ i }) => i);

  if (enabledIndexes.length === 0) return;

  const currentPos = enabledIndexes.indexOf(index);

  let targetPos: number | null = null;
  if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
    targetPos = (currentPos + 1) % enabledIndexes.length;
  } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
    targetPos = (currentPos - 1 + enabledIndexes.length) % enabledIndexes.length;
  } else if (event.key === 'Home') {
    targetPos = 0;
  } else if (event.key === 'End') {
    targetPos = enabledIndexes.length - 1;
  }

  if (targetPos === null) return;

  event.preventDefault();
  const targetIndex = enabledIndexes[targetPos];
  focusIndex(targetIndex);
  select(props.tabs[targetIndex].id);
}
</script>

<template>
  <div class="ui-tabs">
    <div class="ui-tabs-list" role="tablist" :aria-label="ariaLabel">
      <button
        v-for="(tab, index) in tabs"
        :id="tabId(tab.id)"
        ref="tabButtons"
        :key="tab.id"
        type="button"
        role="tab"
        class="ui-tabs-tab"
        :class="{ 'ui-tabs-tab--active': tab.id === modelValue }"
        :aria-selected="tab.id === modelValue"
        :aria-controls="panelId(tab.id)"
        :tabindex="tab.id === modelValue ? 0 : -1"
        :disabled="tab.disabled"
        @click="select(tab.id)"
        @keydown="onKeydown($event, index)"
      >
        {{ tab.label }}
      </button>
    </div>
    <div
      v-for="tab in tabs"
      v-show="tab.id === modelValue"
      :id="panelId(tab.id)"
      :key="tab.id"
      class="ui-tabs-panel"
      role="tabpanel"
      :aria-labelledby="tabId(tab.id)"
      tabindex="0"
    >
      <slot :name="`panel-${tab.id}`" />
    </div>
  </div>
</template>

<style scoped>
.ui-tabs-list {
  display: inline-flex;
  gap: 2px;
  padding: 2px;
  background: var(--muted);
  border-radius: var(--radius-md);
  width: fit-content;
}

.ui-tabs-tab {
  min-height: 28px;
  padding: 0 var(--space-3);
  border-radius: var(--radius-sm);
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--text-mute);
  transition: background-color 150ms ease-out, color 150ms ease-out;
}

.ui-tabs-tab:hover:not(:disabled):not(.ui-tabs-tab--active) {
  color: var(--text);
}

.ui-tabs-tab--active {
  background: var(--surface);
  color: var(--text);
  box-shadow: var(--shadow-xs);
}

.ui-tabs-tab:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.ui-tabs-panel {
  margin-top: var(--space-4);
}

.ui-tabs-panel:focus {
  outline: none;
}
</style>
