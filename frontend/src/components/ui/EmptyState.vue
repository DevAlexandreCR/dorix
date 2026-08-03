<script setup lang="ts">
import { Inbox } from 'lucide-vue-next';

defineProps<{
  title: string;
  description: string;
}>();
</script>

<template>
  <div class="empty-state flex min-h-[220px] flex-col items-center justify-center px-6 py-10 text-center">
    <div class="empty-state-icon mb-4 flex h-11 w-11 items-center justify-center text-[var(--accent)]">
      <Inbox class="h-5 w-5" :stroke-width="1.75" aria-hidden="true" />
    </div>
    <h3 class="text-h3">{{ title }}</h3>
    <p class="mt-2 max-w-lg text-body text-[var(--text-mute)]">
      {{ description }}
    </p>
    <div v-if="$slots.default" class="mt-5">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.empty-state {
  border: 1px dashed var(--border);
  border-radius: var(--radius-lg);
}

.empty-state-icon {
  border-radius: var(--radius-md);
  background: var(--muted);
}
</style>
