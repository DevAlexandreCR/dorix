<script setup lang="ts">
import { ref, watch } from 'vue';
import UiPopover from './UiPopover.vue';

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
    open.value = false;
    return;
  }

  pinned.value = true;
  open.value = true;
}

// Outside-click and Escape (handled by UiPopover) both close by setting
// `open` to false — unpin whenever that happens so a later hover doesn't
// reopen a popover the user just dismissed.
watch(open, (isOpen) => {
  if (!isOpen) {
    pinned.value = false;
  }
});
</script>

<template>
  <UiPopover
    v-model:open="open"
    placement="bottom-center"
    panel-class="w-56"
    role="tooltip"
    @mouseenter="showFromHover"
    @mouseleave="hideFromHover"
  >
    <template #trigger>
      <button
        class="inline-flex h-5 w-5 items-center justify-center rounded-md border border-[color:var(--border)] text-[11px] font-semibold text-[var(--text-mute)] transition hover:text-[var(--text)]"
        type="button"
        :aria-expanded="open"
        :aria-label="props.label || props.content"
        @click.stop="togglePinned"
      >
        i
      </button>
    </template>

    <p class="text-left text-xs leading-5" :style="{ color: 'var(--text-mute)' }">
      {{ props.content }}
    </p>
  </UiPopover>
</template>
