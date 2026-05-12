<script setup lang="ts">
import { useLocale } from '../../composables/useLocale';

const { locale, setLocale } = useLocale();
</script>

<template>
  <div class="inline-flex rounded-xl border bg-[var(--surface-muted)] p-1" :style="{ borderColor: 'var(--border)' }">
    <button
      class="rounded-lg px-3 py-1.5 text-xs font-semibold tracking-[0.14em]"
      :class="locale === 'es-CO' ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm' : 'text-[var(--text-muted)]'"
      type="button"
      @click="setLocale('es-CO')"
    >
      ES
    </button>
    <button
      class="rounded-lg px-3 py-1.5 text-xs font-semibold tracking-[0.14em]"
      :class="locale === 'en' ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm' : 'text-[var(--text-muted)]'"
      type="button"
      @click="setLocale('en')"
    >
      EN
    </button>
  </div>
</template>
