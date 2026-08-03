<script setup lang="ts">
// Skeleton placeholder (design/07 §5: "Cargando: Skeleton con la forma
// del contenido, no spinner global"). `label` stays as the accessible
// name — announced via role="status"/aria-live so screen readers still
// get a loading message even though the visible content is now bars,
// not a spinner + text.
withDefaults(
  defineProps<{
    label: string;
    rows?: number;
  }>(),
  {
    rows: 3,
  },
);
</script>

<template>
  <div class="loading-skeleton" role="status" aria-live="polite">
    <span class="sr-only">{{ label }}</span>
    <div
      v-for="row in rows"
      :key="row"
      class="loading-skeleton-row"
      :style="{ width: row === rows ? '60%' : '100%' }"
      aria-hidden="true"
    />
  </div>
</template>

<style scoped>
.loading-skeleton {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 4px 0;
}

.loading-skeleton-row {
  height: 14px;
  border-radius: var(--radius-sm);
  background: linear-gradient(90deg, var(--muted) 25%, var(--surface) 50%, var(--muted) 75%);
  background-size: 200% 100%;
  animation: loading-skeleton-shimmer 1400ms ease-in-out infinite;
}

@keyframes loading-skeleton-shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}
</style>
