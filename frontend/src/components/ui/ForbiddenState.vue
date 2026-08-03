<script setup lang="ts">
import { useI18n } from 'vue-i18n';

defineProps<{
  title: string;
  description: string;
}>();

// The chip text is identical at every call site ("Restricted"/"Restringido"),
// so it is sourced internally rather than repeated as a prop by every
// caller — same exception as TechValue's copy/copied labels and
// DangerZone's default title (see project_pulso_patterns_2_7 memory).
const { t } = useI18n();
</script>

<template>
  <div class="forbidden-state flex min-h-[220px] flex-col items-center justify-center px-6 py-10 text-center">
    <div class="forbidden-state-chip mb-4 text-micro uppercase text-[var(--warning)]">
      {{ t('states.restrictedChip') }}
    </div>
    <h3 class="text-h3">{{ title }}</h3>
    <p class="mt-2 max-w-lg text-body text-[var(--text-mute)]">
      {{ description }}
    </p>
  </div>
</template>

<style scoped>
.forbidden-state {
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.forbidden-state-chip {
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 4px 12px;
}
</style>
