<script setup lang="ts">
import { computed, ref, onMounted, watch, type Component } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { MessageSquare, FlaskConical, Settings, ShieldCheck, PanelLeftClose, PanelLeftOpen } from 'lucide-vue-next';
import { useNavigationAccess } from '../../composables/useNavigationAccess';
import { useTenantSelection } from '../../composables/useTenantSelection';

const STORAGE_KEY = 'dorix.shell.sideNav';

const { t } = useI18n();
const route = useRoute();
const { selectedMembership } = useTenantSelection();
const { canAccessAdmin, canAccessSandbox, canManagePlatform } = useNavigationAccess(selectedMembership);

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

// Platform (design/04 §1, design/06): separated under a divider with its
// own eyebrow — never rendered at all without canManagePlatform, not just
// disabled. Active-state uses a path prefix (not `route.name === 'platform'`)
// since the leaf route is always a named child (`platform.tenants` /
// `platform.credentials`), never the parent route's own name.
const isPlatformActive = computed(() => route.path.startsWith('/platform'));
</script>

<template>
  <nav
    class="hidden lg:flex flex-col border-r border-[color:var(--border)] bg-[var(--surface)] h-full overflow-hidden transition-[width] duration-200 ease-out shrink-0"
    :class="collapsed ? 'w-16' : 'w-[220px]'"
  >
    <!-- Logo mark -->
    <div class="flex items-center px-3 py-4" :class="collapsed ? 'justify-center' : 'justify-start gap-3'">
      <div
        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-[var(--muted)] text-sm font-bold tracking-[0.16em] text-[var(--accent)]"
      >
        DR
      </div>
      <span
        v-if="!collapsed"
        class="text-small font-semibold truncate text-[var(--text-soft)]"
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

      <!-- Platform (design/06): separate identity, divider + own eyebrow,
           border-st instead of the accent-mix border regular links use. -->
      <template v-if="canManagePlatform">
        <div class="my-2 border-t border-[color:var(--border-st)]" />
        <span
          v-if="!collapsed"
          class="px-2 pb-1 text-micro font-semibold uppercase tracking-wider text-[var(--text-mute)]"
        >
          {{ t('platform.navEyebrow') }}
        </span>

        <RouterLink
          :to="{ name: 'platform', query: route.query }"
          class="group relative flex items-center gap-3 rounded-md border px-2 py-2.5 transition-colors duration-150 ease-out"
          :class="[
            collapsed ? 'justify-center' : 'justify-start',
            isPlatformActive
              ? 'border-[color:color-mix(in_srgb,var(--accent)_28%,var(--border-st))] text-[var(--accent)]'
              : 'text-[var(--text-mute)] hover:text-[var(--text)] border-[color:var(--border-st)]',
          ]"
          :style="isPlatformActive ? { background: 'var(--muted)' } : undefined"
          :title="collapsed ? t('platform.tab') : undefined"
        >
          <span
            v-if="isPlatformActive"
            class="absolute left-0 top-1/2 h-6 w-1 -translate-y-1/2 rounded-r-sm"
            :style="{ background: 'var(--accent)' }"
          />

          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md">
            <ShieldCheck class="h-5 w-5" :stroke-width="1.75" aria-hidden="true" />
          </span>

          <span v-if="!collapsed" class="truncate text-small font-semibold">
            {{ t('platform.tab') }}
          </span>
          <span v-else class="sr-only">{{ t('platform.tab') }}</span>
        </RouterLink>
      </template>
    </div>

    <!-- Collapse toggle — visible only at lg (hidden at xl+) -->
    <div class="flex items-center px-2 py-3 lg:flex xl:hidden">
      <button
        type="button"
        class="flex h-8 w-8 items-center justify-center rounded-md border border-[color:var(--border)] text-[var(--text-mute)] transition-colors duration-150 ease-out"
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
