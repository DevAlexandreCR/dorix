<template>
  <div class="overflow-hidden rounded-[24px] border" :style="{ borderColor: 'var(--border)' }">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y text-sm" :style="{ borderColor: 'var(--border)' }">
        <slot />
      </table>
    </div>
  </div>
</template>
