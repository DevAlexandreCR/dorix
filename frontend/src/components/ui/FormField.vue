<script setup lang="ts">
import { computed } from 'vue';

// Legacy wrapper for raw `<input>`/`<select>` markup that predates the
// UiInput/UiSelect/etc. primitives (those own their own error message +
// aria-describedby wiring internally — see project_pulso_primitives_2_4
// memory — so this wrapper is only needed around plain elements or to
// supply the label when composing with a primitive).
//
// `for`/`id` association only happens when the caller passes a real
// `forId` that matches an `id` on their own control (same contract the
// prop already had) — this intentionally does NOT fall back to an
// auto-generated id for `for`, because a `<label for="...">` pointing at
// a non-existent id breaks the browser's implicit
// label-wraps-control association that today's uncustomized call sites
// rely on (no current call site passes `forId`). `errorId` is exposed
// through the default slot so a call site that does pass `forId` can
// also wire `:aria-describedby="errorId"` on its own control.
const props = withDefaults(
  defineProps<{
    label: string;
    hint?: string;
    forId?: string;
    error?: string;
  }>(),
  {
    hint: undefined,
    forId: undefined,
    error: undefined,
  },
);

const errorId = computed(() => (props.forId ? `${props.forId}-error` : undefined));
</script>

<template>
  <label class="grid gap-2" :for="forId">
    <span class="text-small font-semibold text-[var(--text)]">{{ label }}</span>
    <slot :error-id="errorId" />
    <span v-if="error" :id="errorId" class="text-small text-[var(--danger)]">{{ error }}</span>
    <span v-else-if="hint" class="text-small text-[var(--text-soft)]">{{ hint }}</span>
  </label>
</template>
