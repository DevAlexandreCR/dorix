<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
  message: string;
  tone?: 'danger' | 'info' | 'success' | 'warning';
}>();

const classes = computed(() => {
  switch (props.tone) {
    case 'success':
      return 'border-[color:color-mix(in_srgb,var(--success)_28%,var(--border))] bg-[color:color-mix(in_srgb,var(--success)_12%,transparent)] text-[color:var(--success)]';
    case 'warning':
      return 'border-[color:color-mix(in_srgb,var(--warning)_28%,var(--border))] bg-[color:color-mix(in_srgb,var(--warning)_12%,transparent)] text-[color:var(--warning)]';
    case 'danger':
      return 'border-[color:color-mix(in_srgb,var(--danger)_28%,var(--border))] bg-[color:color-mix(in_srgb,var(--danger)_12%,transparent)] text-[color:var(--danger)]';
    default:
      return 'border-[color:color-mix(in_srgb,var(--accent)_24%,var(--border))] bg-[color:color-mix(in_srgb,var(--accent)_10%,transparent)] text-[color:var(--text)]';
  }
});
</script>

<template>
  <p class="rounded-[18px] border px-4 py-3 text-sm leading-6" :class="classes">
    {{ message }}
  </p>
</template>
