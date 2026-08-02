<script setup lang="ts">
defineProps<{
  label: string;
  hint?: string;
  forId?: string;
}>();
</script>

<template>
  <label class="grid gap-2" :for="forId">
    <span class="text-small font-semibold text-[var(--text)]">{{ label }}</span>
    <slot />
    <span v-if="hint" class="text-small text-[var(--text-soft)]">{{ hint }}</span>
  </label>
</template>
