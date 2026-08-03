<script setup lang="ts">
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const route = useRoute();

interface NavItem {
  name: string;
  labelKey: string;
}

// Flat list (design/06: only 2 pages today, room left in the nav for
// /platform/health and /platform/usage later) — unlike AdminNav there is
// no grouping and no per-item permission filtering, since every current
// and planned platform route requires the same canManagePlatform.
const items: NavItem[] = [
  { name: 'platform.tenants', labelKey: 'platform.nav.tenants' },
  { name: 'platform.credentials', labelKey: 'platform.nav.credentials' },
];

function isActive(name: string): boolean {
  return route.name === name;
}
</script>

<template>
  <nav
    aria-label="Platform navigation"
    class="flex items-center gap-1 border-b border-[color:var(--border-st)] pb-2"
  >
    <RouterLink
      v-for="item in items"
      :key="item.name"
      :to="{ name: item.name, query: route.query }"
      class="rounded-md px-3 py-1.5 text-small font-semibold transition-colors duration-150 ease-out"
      :class="isActive(item.name)
        ? 'text-[var(--accent)] bg-[var(--muted)]'
        : 'text-[var(--text-mute)] hover:text-[var(--text)]'"
      :aria-current="isActive(item.name) ? 'page' : undefined"
    >
      {{ t(item.labelKey) }}
    </RouterLink>
  </nav>
</template>
