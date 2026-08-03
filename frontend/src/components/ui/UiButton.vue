<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
    size?: 'sm' | 'md';
    type?: 'button' | 'submit' | 'reset';
    loading?: boolean;
    disabled?: boolean;
  }>(),
  {
    variant: 'secondary',
    size: 'md',
    type: 'button',
    loading: false,
    disabled: false,
  },
);

const isDisabled = computed(() => props.disabled || props.loading);
</script>

<template>
  <button
    :type="type"
    :class="[`btn-${variant}`, size === 'sm' ? 'btn-sm' : null]"
    :disabled="isDisabled"
    :aria-busy="loading ? 'true' : undefined"
  >
    <span v-if="loading" class="btn-spinner" aria-hidden="true" />
    <slot v-else name="icon" />
    <slot />
  </button>
</template>
