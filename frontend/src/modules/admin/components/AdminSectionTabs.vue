<script setup lang="ts">
defineProps<{
  modelValue: string;
  options: Array<{
    key: string;
    label: string;
  }>;
}>();

defineEmits<{
  'update:modelValue': [value: string];
}>();
</script>

<template>
  <div class="flex gap-2 overflow-x-auto pb-1">
    <button
      v-for="option in options"
      :key="option.key"
      class="shrink-0 rounded-full border px-4 py-2 text-sm transition"
      :class="modelValue === option.key ? 'bg-[var(--surface-muted)] text-[var(--text)]' : 'text-[var(--text-muted)] hover:text-[var(--text)]'"
      :style="{ borderColor: 'var(--border)' }"
      type="button"
      @click="$emit('update:modelValue', option.key)"
    >
      {{ option.label }}
    </button>
  </div>
</template>
