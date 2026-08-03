<script setup lang="ts">
// Connection status indicator (design/07 §, design/03 §5). `--live` is
// reserved exclusively for "connected" per the ui-design-system spec
// ("Verde reservado a estado de conexión") — `live: false` renders a
// muted outline dot instead of falling back to any other semantic
// color, matching design/mockups/admin-pulso.html's `.pill-paused`.
// The label is always visible text (never color-only status).
withDefaults(
  defineProps<{
    label: string;
    live?: boolean;
  }>(),
  {
    live: true,
  },
);
</script>

<template>
  <span class="live-dot" :class="{ 'live-dot--muted': !live }">
    <span class="live-dot-mark" aria-hidden="true" />
    {{ label }}
  </span>
</template>

<style scoped>
.live-dot {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 20px;
  padding: 2px 10px 2px 8px;
  border-radius: 999px;
  font-size: 0.78125rem;
  font-weight: 600;
  color: var(--live);
  background: var(--live-subtle);
}

.live-dot-mark {
  width: 7px;
  height: 7px;
  border-radius: 999px;
  background: var(--live);
  flex-shrink: 0;
}

.live-dot--muted {
  color: var(--text-mute);
  background: var(--muted);
}

.live-dot--muted .live-dot-mark {
  background: transparent;
  border: 1.5px solid var(--text-mute);
}
</style>
