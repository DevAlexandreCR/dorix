<script setup lang="ts">
withDefaults(
  defineProps<{
    lockViewport?: boolean;
  }>(),
  {
    lockViewport: false,
  },
);
</script>

<template>
  <div
    class="min-h-dvh overflow-x-hidden bg-[var(--background)] text-[var(--text)]"
    :class="lockViewport ? 'xl:h-dvh xl:overflow-hidden' : 'xl:overflow-x-hidden'"
  >
    <div
      class="mx-auto flex min-h-dvh w-full max-w-[1600px] flex-col px-3 py-3 sm:px-5 sm:py-5 lg:px-6"
      :class="lockViewport ? 'xl:h-dvh xl:overflow-hidden' : ''"
    >
      <slot />
    </div>
  </div>
</template>
