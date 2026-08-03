<script setup lang="ts">
// Inheritance state chip (design/07 §2, design/03 §5, design.md decision 6).
// Line scope is all-or-nothing everywhere except Modelo (the only field
// that is genuinely nullable in the backend, resolved via `model_source`)
// — this chip is used only where a field-level "inherited vs customized"
// claim actually has backend truth behind it (currently: Modelo in
// assistant/behavior). Copy is entirely caller-supplied (varies by screen:
// "Heredado" vs "Heredado de la organización", etc.) per the "primitives
// take copy as props" convention.
withDefaults(
  defineProps<{
    customized: boolean;
    inheritedLabel: string;
    customizedLabel: string;
    restoreLabel?: string;
  }>(),
  {
    restoreLabel: undefined,
  },
);

const emit = defineEmits<{
  restore: [];
}>();
</script>

<template>
  <span class="inheritance-chip-group">
    <span class="inheritance-chip" :class="customized ? 'inheritance-chip--custom' : 'inheritance-chip--inherit'">
      {{ customized ? customizedLabel : inheritedLabel }}
    </span>
    <button
      v-if="customized && restoreLabel"
      type="button"
      class="inheritance-chip-restore"
      @click="emit('restore')"
    >
      {{ restoreLabel }}
    </button>
  </span>
</template>

<style scoped>
.inheritance-chip-group {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.inheritance-chip {
  display: inline-flex;
  align-items: center;
  height: 20px;
  padding: 0 8px;
  border-radius: 999px;
  font-size: 0.6875rem;
  font-weight: 600;
  line-height: 1;
}

.inheritance-chip--inherit {
  background: var(--muted);
  color: var(--text-mute);
  border: 1px dashed var(--border-st);
}

.inheritance-chip--custom {
  background: var(--accent-subtle);
  color: var(--accent);
}

.inheritance-chip-restore {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--accent);
}

.inheritance-chip-restore:hover {
  color: var(--accent-hover);
}
</style>
