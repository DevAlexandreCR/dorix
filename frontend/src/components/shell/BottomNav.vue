<script setup lang="ts">
import { computed, type Component } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { MessageSquare, FlaskConical, Settings } from 'lucide-vue-next';
import { useNavigationAccess } from '../../composables/useNavigationAccess';
import { useTenantSelection } from '../../composables/useTenantSelection';

const { t } = useI18n();
const route = useRoute();
const { selectedMembership } = useTenantSelection();
const { canAccessAdmin, canAccessSandbox } = useNavigationAccess(selectedMembership);

interface NavLink {
  name: string;
  label: string;
  visible: boolean;
  icon: Component;
}

const links = computed<NavLink[]>(() => [
  { name: 'operations', label: t('operations.tab'), visible: true, icon: MessageSquare },
  { name: 'sandbox', label: t('sandbox.tab'), visible: canAccessSandbox.value, icon: FlaskConical },
  { name: 'admin', label: t('admin.tab'), visible: canAccessAdmin.value, icon: Settings },
].filter((link) => link.visible));
</script>

<template>
  <nav
    class="lg:hidden fixed inset-x-0 bottom-0 z-40 flex h-14"
    :style="{ background: 'var(--surface)', borderTop: '1px solid var(--border)' }"
  >
    <RouterLink
      v-for="link in links"
      :key="link.name"
      :to="{ name: link.name, query: route.query }"
      :title="link.label"
      :aria-current="route.name === link.name ? 'page' : undefined"
      class="relative flex flex-1 flex-col items-center justify-center gap-0.5 transition-colors duration-150 ease-out"
      :style="route.name === link.name
        ? { color: 'var(--accent)' }
        : { color: 'var(--text-mute)' }"
      :class="route.name !== link.name ? 'hover:[color:var(--text)]' : ''"
    >
      <!-- Active top indicator bar -->
      <span
        v-if="route.name === link.name"
        class="absolute inset-x-0 top-0 h-0.5"
        :style="{ background: 'var(--accent)' }"
      />

      <component
        :is="link.icon"
        class="h-5 w-5"
        :stroke-width="1.75"
        aria-hidden="true"
      />
      <span class="text-micro">{{ link.label }}</span>
    </RouterLink>
  </nav>
</template>
