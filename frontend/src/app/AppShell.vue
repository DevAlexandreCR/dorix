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
    class="relative min-h-dvh overflow-x-hidden"
    :class="lockViewport ? 'xl:h-dvh xl:overflow-hidden' : 'xl:overflow-x-hidden'"
  >
    <div class="pointer-events-none absolute inset-0 opacity-90">
      <div class="absolute -left-32 -top-24 h-[28rem] w-[28rem] rounded-full bg-[color:color-mix(in_srgb,var(--accent)_12%,transparent)] blur-[120px]" />
      <div class="absolute -right-24 top-0 h-[30rem] w-[30rem] rounded-full bg-[rgba(67,113,255,0.12)] blur-[140px]" />
      <div class="absolute bottom-[-8rem] left-1/4 h-[24rem] w-[24rem] rounded-full bg-[rgba(67,113,255,0.08)] blur-[130px]" />
    </div>

    <div
      class="relative mx-auto flex min-h-dvh w-full max-w-[1500px] flex-col px-3 py-3 sm:px-6 sm:py-4 lg:px-8"
      :class="lockViewport ? 'xl:h-dvh xl:overflow-hidden' : ''"
    >
      <slot />
    </div>
  </div>
</template>
