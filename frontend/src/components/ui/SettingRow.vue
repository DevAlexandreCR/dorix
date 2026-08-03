<script setup lang="ts">
// Setting row: label + effect sentence + control aligned right, with an
// optional nested config block revealed only when the caller says so
// (design/07 §3, design/05 assistant/tools). SettingRow doesn't know
// *why* the nested slot should show (a switch, a select…) — the caller
// owns that boolean and passes it via `nestedVisible`, keeping this
// component a dumb layout/disclosure shell around whatever control it's
// given via the `control` slot.
//
// Rows are meant to be stacked directly (no gap) inside a shared
// bordered container the call site provides (a SurfaceCard or similar) —
// each row draws its own bottom divider and the last one omits it, same
// pattern as design/mockups/admin-pulso.html's `.tech-row`.
withDefaults(
  defineProps<{
    label: string;
    help?: string;
    nestedVisible?: boolean;
    disabled?: boolean;
  }>(),
  {
    help: undefined,
    nestedVisible: false,
    disabled: false,
  },
);
</script>

<template>
  <div class="setting-row" :class="{ 'setting-row--disabled': disabled }">
    <div class="setting-row-main">
      <div class="setting-row-copy">
        <p class="text-body setting-row-label">{{ label }}</p>
        <p v-if="help" class="text-small setting-row-help">{{ help }}</p>
      </div>
      <div class="setting-row-control">
        <slot name="control" />
      </div>
    </div>
    <div v-if="nestedVisible && $slots.nested" class="setting-row-nested">
      <slot name="nested" />
    </div>
  </div>
</template>

<style scoped>
.setting-row {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
}

.setting-row:last-child {
  border-bottom: 0;
}

.setting-row--disabled {
  opacity: 0.6;
}

.setting-row-main {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.setting-row-copy {
  min-width: 0;
}

.setting-row-label {
  margin: 0;
  font-weight: 600;
}

.setting-row-help {
  margin: 3px 0 0;
  color: var(--text-mute);
}

.setting-row-control {
  flex-shrink: 0;
  padding-top: 1px;
}

.setting-row-nested {
  margin: 10px 0 0 0;
  padding: 10px 0 0 16px;
  border-top: 1px dashed var(--border);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
</style>
