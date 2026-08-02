<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{
    label: string;
    status?: string;
    tone?: 'accent' | 'danger' | 'neutral' | 'success' | 'warning';
    large?: boolean;
  }>(),
  {
    status: undefined,
    tone: undefined,
    large: false,
  },
);

const tone = computed(() => {
  if (props.tone) {
    return props.tone;
  }

  switch (props.status) {
    case 'BOT_ACTIVE':
      return 'success';
    case 'WAITING_CUSTOMER':
      return 'warning';
    case 'HUMAN_HANDOFF':
      return 'accent';
    case 'ERROR':
      return 'danger';
    default:
      return 'neutral';
  }
});

const classes = computed(() => {
  const size = props.large ? 'px-3.5 py-1.5 text-xs' : 'px-2.5 py-1 text-[10px]';

  switch (tone.value) {
    case 'success':
      return `${size} border-[color:color-mix(in_srgb,var(--success)_28%,var(--border))] bg-[color:color-mix(in_srgb,var(--success)_12%,var(--surface))] text-[color:var(--success)]`;
    case 'warning':
      return `${size} border-[color:color-mix(in_srgb,var(--warning)_30%,var(--border))] bg-[color:color-mix(in_srgb,var(--warning)_12%,var(--surface))] text-[color:var(--warning)]`;
    case 'danger':
      return `${size} border-[color:color-mix(in_srgb,var(--danger)_28%,var(--border))] bg-[color:color-mix(in_srgb,var(--danger)_12%,var(--surface))] text-[color:var(--danger)]`;
    case 'accent':
      return `${size} border-[color:color-mix(in_srgb,var(--accent)_30%,var(--border))] bg-[color:color-mix(in_srgb,var(--accent)_12%,var(--surface))] text-[color:var(--accent)]`;
    default:
      return `${size} border-[color:var(--border)] bg-[color:var(--muted)] text-[color:var(--text-mute)]`;
  }
});
</script>

<template>
  <span class="inline-flex rounded-sm border font-semibold uppercase tracking-[0.12em]" :class="classes">
    {{ label }}
  </span>
</template>
