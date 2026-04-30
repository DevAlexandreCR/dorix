<script setup lang="ts">
defineProps<{
  label: string;
  hint?: string;
  forId?: string;
}>();
</script>

<template>
  <label class="grid gap-2 text-sm" :for="forId">
    <span class="font-medium text-[var(--text)]">{{ label }}</span>
    <slot />
    <span v-if="hint" class="text-xs text-[var(--text-muted)]">{{ hint }}</span>
  </label>
</template>
