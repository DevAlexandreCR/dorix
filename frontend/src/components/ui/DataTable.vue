<template>
  <div class="overflow-hidden rounded-[20px] border bg-[var(--surface)]" :style="{ borderColor: 'var(--border)' }">
    <div class="overflow-x-auto">
      <table class="table-dashstack min-w-full divide-y text-sm" :style="{ borderColor: 'var(--border)' }">
        <slot />
      </table>
    </div>
  </div>
</template>
