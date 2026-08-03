<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{
    padding?: 'sm' | 'md' | 'lg';
  }>(),
  {
    padding: 'md',
  },
);

const paddingClass = computed(() => {
  switch (props.padding) {
    case 'sm':
      return 'p-4';
    case 'lg':
      return 'p-6 lg:p-7';
    default:
      return 'p-5 lg:p-6';
  }
});
</script>

<template>
  <article class="surface-card" :class="paddingClass">
    <slot />
  </article>
</template>

<style scoped>
.surface-card {
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: var(--shadow-xs);
}
</style>
