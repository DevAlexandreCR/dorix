<script setup lang="ts">
defineProps<{
  title: string;
  description: string;
}>();
</script>

<template>
  <div class="flex min-h-[220px] flex-col items-center justify-center rounded-lg border border-dashed px-6 py-10 text-center" :style="{ borderColor: 'var(--border)' }">
    <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-md bg-[var(--muted)] text-small font-bold text-[var(--accent)]">0</div>
    <h3 class="text-h3">{{ title }}</h3>
    <p class="mt-2 max-w-lg text-body text-[var(--text-mute)]">
      {{ description }}
    </p>
    <div v-if="$slots.default" class="mt-5">
      <slot />
    </div>
  </div>
</template>
