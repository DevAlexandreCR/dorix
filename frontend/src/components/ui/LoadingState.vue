<script setup lang="ts">
defineProps<{
  label: string;
}>();
</script>

<template>
  <div class="flex min-h-[180px] flex-col items-center justify-center gap-3 text-center">
    <div class="h-10 w-10 animate-spin rounded-full border-2 border-[color:var(--border)] border-t-[color:var(--accent)]" />
    <p class="text-sm text-[var(--text-muted)]">{{ label }}</p>
  </div>
</template>
