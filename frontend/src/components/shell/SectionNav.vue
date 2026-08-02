<script setup lang="ts">
import { computed, ref, onMounted, watch, type Component } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { MessageSquare, FlaskConical, Settings, PanelLeftClose, PanelLeftOpen } from 'lucide-vue-next';
import { useNavigationAccess } from '../../composables/useNavigationAccess';
import { useTenantSelection } from '../../composables/useTenantSelection';

const STORAGE_KEY = 'dorix.shell.sideNav';

const { t } = useI18n();
const route = useRoute();
const { selectedMembership } = useTenantSelection();
const { canAccessAdmin, canAccessSandbox } = useNavigationAccess(selectedMembership);

const collapsed = ref(false);

onMounted(() => {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored) {
      const parsed = JSON.parse(stored) as { collapsed?: boolean };
      collapsed.value = parsed.collapsed ?? false;
    }
  } catch {
    collapsed.value = false;
  }
});

watch(collapsed, (val: boolean) => {
  localStorage.setItem(STORAGE_KEY, JSON.stringify({ collapsed: val }));
});

function toggleCollapsed() {
  collapsed.value = !collapsed.value;
}

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
    class="hidden lg:flex flex-col border-r h-full overflow-hidden transition-[width] duration-200 ease-out shrink-0"
    :class="collapsed ? 'w-16' : 'w-[220px]'"
    :style="{ borderColor: 'var(--border)', background: 'var(--surface)' }"
  >
    <!-- Logo mark -->
    <div class="flex items-center px-3 py-4" :class="collapsed ? 'justify-center' : 'justify-start gap-3'">
      <div
        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-sm font-bold tracking-[0.16em]"
        :style="{ background: 'var(--muted)', color: 'var(--accent)' }"
      >
        DR
      </div>
      <span
        v-if="!collapsed"
        class="text-small font-semibold truncate"
        :style="{ color: 'var(--text-soft)' }"
      >
        Dorix
      </span>
    </div>

    <!-- Navigation links -->
    <div class="flex flex-1 flex-col gap-1 px-2 overflow-y-auto">
      <RouterLink
        v-for="link in links"
        :key="link.name"
        :to="{ name: link.name, query: route.query }"
        class="group relative flex items-center gap-3 rounded-md border px-2 py-2.5 transition-colors duration-150 ease-out"
        :class="[
          collapsed ? 'justify-center' : 'justify-start',
          route.name === link.name
            ? 'border-[color:color-mix(in_srgb,var(--accent)_28%,var(--border))] text-[var(--accent)]'
            : 'text-[var(--text-mute)] hover:text-[var(--text)] border-transparent hover:border-[color:var(--border)]',
        ]"
        :style="route.name === link.name ? { background: 'var(--muted)' } : undefined"
        :title="collapsed ? link.label : undefined"
      >
        <!-- Active indicator bar -->
        <span
          v-if="route.name === link.name"
          class="absolute left-0 top-1/2 h-6 w-1 -translate-y-1/2 rounded-r-sm"
          :style="{ background: 'var(--accent)' }"
        />

        <!-- Icon container -->
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md">
          <component :is="link.icon" class="h-5 w-5" :stroke-width="1.75" aria-hidden="true" />
        </span>

        <!-- Label — hidden in collapsed mode (sr-only for accessibility) -->
        <span
          v-if="!collapsed"
          class="truncate text-small font-semibold"
        >
          {{ link.label }}
        </span>
        <span v-else class="sr-only">{{ link.label }}</span>
      </RouterLink>
    </div>

    <!-- Collapse toggle — visible only at lg (hidden at xl+) -->
    <div class="flex items-center px-2 py-3 lg:flex xl:hidden">
      <button
        type="button"
        class="flex h-8 w-8 items-center justify-center rounded-md border transition-colors duration-150 ease-out"
        :style="{ borderColor: 'var(--border)', color: 'var(--text-mute)' }"
        :title="collapsed ? t('shell.expandSidebar') : t('shell.collapseSidebar')"
        @click="toggleCollapsed"
      >
        <component
          :is="collapsed ? PanelLeftOpen : PanelLeftClose"
          class="h-4 w-4"
          :stroke-width="1.75"
          aria-hidden="true"
        />
      </button>
    </div>
  </nav>
</template>
