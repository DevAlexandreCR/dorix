<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
  defineProps<{
    content: string;
    label?: string;
  }>(),
  {
    label: undefined,
  },
);

const open = ref(false);
const pinned = ref(false);
const root = ref<HTMLElement | null>(null);

function supportsHover(): boolean {
  return typeof window !== 'undefined' && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
}

function showFromHover(): void {
  if (!pinned.value && supportsHover()) {
    open.value = true;
  }
}

function hideFromHover(): void {
  if (!pinned.value && supportsHover()) {
    open.value = false;
  }
}

function togglePinned(): void {
  if (pinned.value) {
    pinned.value = false;
    open.value = false;
    return;
  }

  pinned.value = true;
  open.value = true;
}

function closePopover(): void {
  pinned.value = false;
  open.value = false;
}

function handleDocumentClick(event: MouseEvent): void {
  if (!root.value) {
    return;
  }

  if (event.target instanceof Node && !root.value.contains(event.target)) {
    closePopover();
  }
}

function handleEscape(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    closePopover();
  }
}

onMounted(() => {
  document.addEventListener('click', handleDocumentClick);
  document.addEventListener('keydown', handleEscape);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', handleDocumentClick);
  document.removeEventListener('keydown', handleEscape);
});
</script>

<template>
  <span
    ref="root"
    class="relative inline-flex"
    @mouseenter="showFromHover"
    @mouseleave="hideFromHover"
  >
    <button
      class="inline-flex h-5 w-5 items-center justify-center rounded-md border text-[11px] font-semibold text-[var(--text-muted)] transition hover:text-[var(--text)]"
      :style="{ borderColor: 'var(--border)' }"
      type="button"
      :aria-expanded="open"
      :aria-label="props.label || props.content"
      @click.stop="togglePinned"
    >
      i
    </button>

    <div
      v-if="open"
      class="absolute left-1/2 top-full z-30 mt-2 w-56 -translate-x-1/2 rounded-[18px] border bg-[var(--surface)] p-3 text-left text-xs leading-5 text-[var(--text-muted)] shadow-[var(--shadow-panel)]"
      :style="{ borderColor: 'var(--border)' }"
      role="tooltip"
    >
      {{ props.content }}
    </div>
  </span>
</template>
