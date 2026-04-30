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
  <article
    class="rounded-[28px] border bg-[var(--surface)] shadow-[var(--shadow-panel)] backdrop-blur-sm"
    :class="paddingClass"
    :style="{ borderColor: 'var(--border)' }"
  >
    <slot />
  </article>
</template>
