<script setup lang="ts">
import { ref, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';

// Monospace technical value + copy-to-clipboard (design/07 §3, design/03
// §5). Exception to the "primitives take copy as props" convention
// (see project_pulso_overlays_2_6's note on UiToast's `common.dismiss`):
// the copy/copied labels are identical at every call site (Phone Number
// ID, WABA ID, slugs, model ids…), so they live in `common.copy` /
// `common.copied` and are read via useI18n() internally rather than
// requiring every consumer to pass the same two strings.
const props = defineProps<{
  value: string;
}>();

const { t } = useI18n();

const copied = ref(false);
let resetTimer: ReturnType<typeof setTimeout> | undefined;

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.value);
  } catch {
    // Clipboard API unavailable (non-secure context, permission denied…).
    // Nothing sensible to recover to here — silently skip the confirmation.
    return;
  }

  copied.value = true;
  clearTimeout(resetTimer);
  resetTimer = setTimeout(() => {
    copied.value = false;
  }, 1500);
}

onBeforeUnmount(() => clearTimeout(resetTimer));
</script>

<template>
  <span class="tech-value">
    <span class="text-mono tech-value-text">{{ value }}</span>
    <button type="button" class="tech-value-copy" @click="copy">
      {{ copied ? t('common.copied') : t('common.copy') }}
    </button>
    <span class="sr-only" role="status" aria-live="polite">{{ copied ? t('common.copied') : '' }}</span>
  </span>
</template>

<style scoped>
.tech-value {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.tech-value-text {
  color: var(--text-mute);
  overflow-wrap: anywhere;
}

.tech-value-copy {
  flex-shrink: 0;
  font-size: 0.6875rem;
  font-weight: 600;
  color: var(--accent);
}

.tech-value-copy:hover {
  color: var(--accent-hover);
}
</style>
