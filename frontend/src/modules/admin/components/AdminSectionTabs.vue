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
  <div class="flex gap-2 overflow-x-auto rounded-[18px] border bg-[var(--surface-muted)] p-1.5" :style="{ borderColor: 'var(--border)' }">
    <button
      v-for="option in options"
      :key="option.key"
      class="shrink-0 rounded-[14px] px-4 py-2 text-sm font-semibold transition"
      :class="
        modelValue === option.key
          ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm'
          : 'text-[var(--text-muted)] hover:text-[var(--text)]'
      "
      type="button"
      @click="$emit('update:modelValue', option.key)"
    >
      {{ option.label }}
    </button>
  </div>
</template>
