<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
  canAccessAdmin: boolean;
  canAccessSandbox: boolean;
}>();

const { t } = useI18n();
const route = useRoute();

const links = computed(() => [
  { name: 'operations', label: t('operations.tab'), visible: true },
  { name: 'sandbox', label: t('sandbox.tab'), visible: props.canAccessSandbox },
  { name: 'admin', label: t('admin.tab'), visible: props.canAccessAdmin },
].filter((link) => link.visible));
</script>

<template>
  <nav class="flex flex-wrap gap-1.5 sm:flex-nowrap sm:overflow-x-auto">
    <RouterLink
      v-for="link in links"
      :key="link.name"
      :to="{ name: link.name, query: route.query }"
      class="rounded-full border px-3 py-2 text-sm font-medium transition sm:shrink-0"
      :class="route.name === link.name ? 'bg-[var(--surface-muted)] text-[var(--text)]' : 'text-[var(--text-muted)] hover:text-[var(--text)]'"
      :style="{ borderColor: 'var(--border)' }"
    >
      {{ link.label }}
    </RouterLink>
  </nav>
</template>
