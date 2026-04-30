<script setup lang="ts">
import { useLocale } from '../../composables/useLocale';

const { locale, setLocale } = useLocale();
</script>

<template>
  <div class="inline-flex rounded-full border p-1" :style="{ borderColor: 'var(--border)' }">
    <button
      class="rounded-full px-3 py-1.5 text-xs font-semibold tracking-[0.16em]"
      :class="locale === 'es-CO' ? 'bg-[var(--surface-muted)] text-[var(--text)]' : 'text-[var(--text-muted)]'"
      type="button"
      @click="setLocale('es-CO')"
    >
      ES
    </button>
    <button
      class="rounded-full px-3 py-1.5 text-xs font-semibold tracking-[0.16em]"
      :class="locale === 'en' ? 'bg-[var(--surface-muted)] text-[var(--text)]' : 'text-[var(--text-muted)]'"
      type="button"
      @click="setLocale('en')"
    >
      EN
    </button>
  </div>
</template>
