<script setup lang="ts">
defineProps<{
  label: string;
}>();
</script>

<template>
  <div class="flex min-h-[180px] flex-col items-center justify-center gap-3 text-center">
    <div class="flex h-11 w-11 items-center justify-center rounded-md border bg-[var(--muted)]" :style="{ borderColor: 'var(--border)' }">
      <div class="h-5 w-5 animate-spin rounded-full border-2 border-[color:var(--border)] border-t-[color:var(--accent)]" />
    </div>
    <p class="text-small text-[var(--text-mute)]">{{ label }}</p>
  </div>
</template>
