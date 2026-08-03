<script setup lang="ts">
import { useLocale } from '../../composables/useLocale';

const { locale, setLocale } = useLocale();
</script>

<template>
  <div class="inline-flex rounded-xl border border-[color:var(--border)] bg-[var(--muted)] p-1">
    <button
      class="rounded-lg px-3 py-1.5 text-xs font-semibold tracking-[0.14em]"
      :class="locale === 'es-CO' ? 'bg-[var(--surface)] text-[var(--text)] shadow-[var(--shadow-xs)]' : 'text-[var(--text-mute)]'"
      type="button"
      @click="setLocale('es-CO')"
    >
      ES
    </button>
    <button
      class="rounded-lg px-3 py-1.5 text-xs font-semibold tracking-[0.14em]"
      :class="locale === 'en' ? 'bg-[var(--surface)] text-[var(--text)] shadow-[var(--shadow-xs)]' : 'text-[var(--text-mute)]'"
      type="button"
      @click="setLocale('en')"
    >
      EN
    </button>
  </div>
</template>
