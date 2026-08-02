<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
  message: string;
  tone?: 'danger' | 'info' | 'success' | 'warning';
}>();

const classes = computed(() => {
  switch (props.tone) {
    case 'success':
      return 'inline-alert--success';
    case 'warning':
      return 'inline-alert--warning';
    case 'danger':
      return 'inline-alert--danger';
    default:
      return 'inline-alert--info';
  }
});
</script>

<template>
  <p class="inline-alert rounded-md px-4 py-3 text-sm leading-6" :class="classes">
    {{ message }}
  </p>
</template>

<style scoped>
.inline-alert {
  border: none;
  border-left: 1px solid;
}

.inline-alert--success {
  background: color-mix(in srgb, var(--success) 12%, var(--surface));
  border-left-color: var(--success);
  color: var(--text);
}

.inline-alert--warning {
  background: color-mix(in srgb, var(--warning) 12%, var(--surface));
  border-left-color: var(--warning);
  color: var(--text);
}

.inline-alert--danger {
  background: color-mix(in srgb, var(--danger) 12%, var(--surface));
  border-left-color: var(--danger);
  color: var(--text);
}

.inline-alert--info {
  background: color-mix(in srgb, var(--info) 12%, var(--surface));
  border-left-color: var(--info);
  color: var(--text);
}
</style>
