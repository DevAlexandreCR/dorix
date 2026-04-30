<script setup lang="ts">
defineProps<{
  title: string;
  description: string;
}>();
</script>

<template>
  <div class="flex min-h-[240px] flex-col items-center justify-center rounded-[24px] border border-dashed px-6 py-10 text-center" :style="{ borderColor: 'var(--border)' }">
    <div class="mb-4 h-10 w-10 rounded-full bg-[color:color-mix(in_srgb,var(--accent)_16%,transparent)]" />
    <h3 class="text-lg font-semibold">{{ title }}</h3>
    <p class="mt-2 max-w-lg text-sm text-[var(--text-muted)]">
      {{ description }}
    </p>
    <div v-if="$slots.default" class="mt-5">
      <slot />
    </div>
  </div>
</template>
