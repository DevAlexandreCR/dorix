<script setup lang="ts">
defineProps<{
  title: string;
  description: string;
}>();
</script>

<template>
  <div class="flex min-h-[220px] flex-col items-center justify-center rounded-[20px] border px-6 py-10 text-center" :style="{ borderColor: 'var(--border)' }">
    <div class="mb-4 rounded-xl border px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--warning)]" :style="{ borderColor: 'var(--border)' }">
      Restricted
    </div>
    <h3 class="text-lg font-semibold">{{ title }}</h3>
    <p class="mt-2 max-w-lg text-sm leading-6 text-[var(--text-muted)]">
      {{ description }}
    </p>
  </div>
</template>
