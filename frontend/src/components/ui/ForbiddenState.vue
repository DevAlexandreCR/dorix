<script setup lang="ts">
defineProps<{
  title: string;
  description: string;
}>();
</script>

<template>
  <div class="flex min-h-[220px] flex-col items-center justify-center rounded-lg border px-6 py-10 text-center" :style="{ borderColor: 'var(--border)' }">
    <div class="mb-4 rounded-sm border px-3 py-1 text-micro uppercase tracking-[0.2em] text-[var(--warning)]" :style="{ borderColor: 'var(--border)' }">
      Restricted
    </div>
    <h3 class="text-h3">{{ title }}</h3>
    <p class="mt-2 max-w-lg text-body text-[var(--text-mute)]">
      {{ description }}
    </p>
  </div>
</template>
