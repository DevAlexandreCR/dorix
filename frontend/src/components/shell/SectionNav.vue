<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useNavigationAccess } from '../../composables/useNavigationAccess';
import { useTenantSelection } from '../../composables/useTenantSelection';

const { t } = useI18n();
const route = useRoute();
const { selectedMembership } = useTenantSelection();
const { canAccessAdmin, canAccessSandbox } = useNavigationAccess(selectedMembership);

const links = computed(() => [
  { name: 'operations', label: t('operations.tab'), visible: true, icon: 'operations' },
  { name: 'sandbox', label: t('sandbox.tab'), visible: canAccessSandbox.value, icon: 'sandbox' },
  { name: 'admin', label: t('admin.tab'), visible: canAccessAdmin.value, icon: 'admin' },
].filter((link) => link.visible));
</script>

<template>
  <nav class="rounded-[24px] border bg-[var(--surface)] p-3 shadow-[var(--shadow-panel)] lg:flex lg:h-full lg:flex-col" :style="{ borderColor: 'var(--border)' }">
    <div class="mb-3 hidden items-center justify-center lg:flex">
      <div class="flex h-12 w-12 items-center justify-center rounded-[18px] bg-[var(--surface-muted)] text-sm font-bold tracking-[0.16em] text-[var(--accent)]">
        DR
      </div>
    </div>

    <div class="flex gap-2 overflow-x-auto lg:flex-1 lg:flex-col lg:overflow-visible">
    <RouterLink
      v-for="link in links"
      :key="link.name"
      :to="{ name: link.name, query: route.query }"
      class="group relative flex min-h-14 min-w-[120px] items-center gap-3 rounded-[18px] border px-4 py-3 text-sm font-semibold transition sm:shrink-0 lg:min-w-0 lg:justify-center lg:px-0"
      :class="
        route.name === link.name
          ? 'border-[color:color-mix(in_srgb,var(--accent)_28%,var(--border))] bg-[var(--surface-muted)] text-[var(--accent)]'
          : 'text-[var(--text-muted)] hover:text-[var(--text)]'
      "
      :style="route.name === link.name ? undefined : { borderColor: 'var(--border)' }"
      :title="link.label"
    >
      <span
        v-if="route.name === link.name"
        class="absolute left-0 top-1/2 hidden h-8 w-1 -translate-y-1/2 rounded-r-full bg-[var(--accent)] lg:block"
      />

      <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[14px] border border-current/10 bg-white/0">
        <svg
          v-if="link.icon === 'operations'"
          class="h-4 w-4"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M4 7h16" />
          <path d="M4 12h10" />
          <path d="M4 17h7" />
          <path d="M18 10v8" />
          <path d="M14 14h8" />
        </svg>

        <svg
          v-else-if="link.icon === 'sandbox'"
          class="h-4 w-4"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M8 4h8" />
          <path d="M9 4v3l-4 7a4 4 0 0 0 3.47 6h7.06A4 4 0 0 0 19 14l-4-7V4" />
          <path d="M8 14h8" />
        </svg>

        <svg
          v-else
          class="h-4 w-4"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <rect x="4" y="4" width="7" height="7" rx="1.5" />
          <rect x="13" y="4" width="7" height="4" rx="1.5" />
          <rect x="13" y="10" width="7" height="10" rx="1.5" />
          <rect x="4" y="13" width="7" height="7" rx="1.5" />
        </svg>
      </span>

      <span class="truncate lg:sr-only">{{ link.label }}</span>
    </RouterLink>
    </div>
  </nav>
</template>
