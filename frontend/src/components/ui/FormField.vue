<script setup lang="ts">
defineProps<{
  label: string;
  hint?: string;
  forId?: string;
}>();
</script>

<template>
  <label class="grid gap-2 text-sm" :for="forId">
    <span class="text-[12px] font-semibold text-[var(--text)]">{{ label }}</span>
    <slot />
    <span v-if="hint" class="text-[12px] leading-5 text-[var(--text-muted)]">{{ hint }}</span>
  </label>
</template>
