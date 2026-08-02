<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Building2, ChevronDown, Plug, Sparkles, Activity } from 'lucide-vue-next';
import { useNavigationAccess } from '../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../composables/useTenantSelection';
import { ADMIN_ROUTE_REQUIRES } from '../router';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const { selectedMembership } = useTenantSelection();

type GroupId = 'org' | 'connect' | 'assistant' | 'activity';

interface NavItem {
  path: string;
  labelKey: string;
}

interface NavGroup {
  id: GroupId;
  labelKey: string;
  icon: Component;
  items: NavItem[];
}

const GROUPS: NavGroup[] = [
  {
    id: 'org',
    labelKey: 'admin.nav.org',
    icon: Building2,
    items: [
      { path: '/admin/org/info', labelKey: 'admin.nav.org.info' },
      { path: '/admin/org/members', labelKey: 'admin.nav.org.members' },
    ],
  },
  {
    id: 'connect',
    labelKey: 'admin.nav.connect',
    icon: Plug,
    items: [
      { path: '/admin/connect/lines', labelKey: 'admin.nav.connect.lines' },
      { path: '/admin/connect/credentials', labelKey: 'admin.nav.connect.credentials' },
      { path: '/admin/connect/data', labelKey: 'admin.nav.connect.data' },
    ],
  },
  {
    id: 'assistant',
    labelKey: 'admin.nav.assistant',
    icon: Sparkles,
    items: [
      { path: '/admin/assistant/behavior', labelKey: 'admin.nav.assistant.behavior' },
      { path: '/admin/assistant/tools', labelKey: 'admin.nav.assistant.tools' },
    ],
  },
  {
    id: 'activity',
    labelKey: 'admin.nav.activity',
    icon: Activity,
    items: [
      { path: '/admin/activity', labelKey: 'admin.nav.activity' },
    ],
  },
];

const access = computed(() => useNavigationAccess(selectedMembership));

function itemVisible(item: NavItem): boolean {
  const requires = ADMIN_ROUTE_REQUIRES[item.path];
  if (!requires || requires.length === 0) return false;
  const acc = access.value as Record<string, { value: boolean }>;
  return requires.some((key) => acc[key]?.value === true);
}

const visibleGroups = computed(() =>
  GROUPS
    .map((group) => ({
      ...group,
      visibleItems: group.items.filter(itemVisible),
    }))
    .filter((group) => group.visibleItems.length > 0),
);

function isItemActive(itemPath: string): boolean {
  return route.path === itemPath || route.path.startsWith(itemPath + '/');
}

// Mobile dropdown state
const open = ref(false);
const triggerRef = ref<HTMLElement | null>(null);
const popoverRef = ref<HTMLElement | null>(null);
const dropdownId = 'admin-nav-dropdown';

interface ActivePanel {
  groupLabel: string;
  itemLabel: string;
}

const activePanel = computed<ActivePanel | null>(() => {
  for (const group of visibleGroups.value) {
    for (const item of group.visibleItems) {
      if (isItemActive(item.path)) {
        return {
          groupLabel: t(group.labelKey),
          itemLabel: t(item.labelKey),
        };
      }
    }
  }
  return null;
});

function toggle(): void {
  open.value = !open.value;
}

function close(): void {
  open.value = false;
}

function selectItem(path: string): void {
  router.push({ path, query: route.query });
  close();
}

function onPointerDown(event: PointerEvent): void {
  const target = event.target as Node | null;
  if (
    triggerRef.value &&
    !triggerRef.value.contains(target) &&
    popoverRef.value &&
    !popoverRef.value.contains(target)
  ) {
    close();
  }
}

function onKeyDown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close();
  }
}

onMounted(() => {
  document.addEventListener('pointerdown', onPointerDown);
  document.addEventListener('keydown', onKeyDown);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onPointerDown);
  document.removeEventListener('keydown', onKeyDown);
});

watch(route, () => {
  open.value = false;
});
</script>

<template>
  <!-- Sidebar: visible at lg+ -->
  <nav
    class="hidden lg:flex flex-col w-[240px] shrink-0 h-full overflow-y-auto"
    :style="{ background: 'var(--surface)', borderRight: '1px solid var(--border)' }"
    aria-label="Admin navigation"
  >
    <template v-for="group in visibleGroups" :key="group.id">
      <section :aria-labelledby="`admin-nav-group-${group.id}`">
        <!-- Group header -->
        <div class="flex items-center gap-2 px-3 py-2 mt-4 first:mt-2">
          <component
            :is="group.icon"
            class="h-3.5 w-3.5 shrink-0"
            :stroke-width="2"
            :style="{ color: 'var(--text-mute)' }"
            aria-hidden="true"
          />
          <h2
            :id="`admin-nav-group-${group.id}`"
            class="text-micro font-semibold uppercase tracking-wider"
            :style="{ color: 'var(--text-mute)' }"
          >
            {{ t(group.labelKey) }}
          </h2>
        </div>

        <!-- Group items -->
        <ul class="flex flex-col gap-0.5 px-2 pb-1">
          <li v-for="item in group.visibleItems" :key="item.path">
            <RouterLink
              :to="{ path: item.path, query: route.query }"
              class="flex w-full items-center rounded-md px-3 py-2 pl-8 text-small transition-colors duration-150 ease-out"
              :class="isItemActive(item.path)
                ? 'font-medium'
                : 'hover:[color:var(--text-soft)]'"
              :style="isItemActive(item.path)
                ? { color: 'var(--accent)', background: 'var(--muted)' }
                : { color: 'var(--text-mute)' }"
              :aria-current="isItemActive(item.path) ? 'page' : undefined"
            >
              {{ t(item.labelKey) }}
            </RouterLink>
          </li>
        </ul>
      </section>
    </template>
  </nav>

  <!-- Mobile dropdown trigger: visible at < lg -->
  <div class="lg:hidden relative w-full">
    <button
      ref="triggerRef"
      type="button"
      class="admin-nav-trigger"
      :aria-haspopup="'menu'"
      :aria-expanded="open"
      :aria-controls="dropdownId"
      @click="toggle"
    >
      <span class="text-small truncate" :style="{ color: 'var(--text)' }">
        <template v-if="activePanel">
          {{ activePanel.groupLabel }} · {{ activePanel.itemLabel }}
        </template>
        <template v-else>
          {{ t('admin.nav.select') }}
        </template>
      </span>
      <ChevronDown
        class="admin-nav-chevron"
        :class="{ 'rotate-180': open }"
        :stroke-width="1.75"
        :style="{ color: 'var(--text-mute)' }"
        aria-hidden="true"
      />
    </button>

    <!-- Popover -->
    <Transition name="admin-nav-popover">
      <div
        v-if="open"
        :id="dropdownId"
        ref="popoverRef"
        class="admin-nav-popover"
        role="menu"
        aria-label="Admin navigation"
      >
        <template v-for="group in visibleGroups" :key="group.id">
          <!-- Group label -->
          <div class="flex items-center gap-2 px-2 py-1 mt-2 first:mt-0">
            <component
              :is="group.icon"
              class="h-3 w-3 shrink-0"
              :stroke-width="2"
              :style="{ color: 'var(--text-mute)' }"
              aria-hidden="true"
            />
            <span
              class="text-micro font-semibold uppercase tracking-wider"
              :style="{ color: 'var(--text-mute)' }"
            >
              {{ t(group.labelKey) }}
            </span>
          </div>

          <!-- Group items -->
          <ul class="flex flex-col gap-0.5">
            <li v-for="item in group.visibleItems" :key="item.path">
              <button
                type="button"
                role="menuitem"
                class="admin-nav-item"
                :class="{ 'admin-nav-item--active': isItemActive(item.path) }"
                :style="isItemActive(item.path)
                  ? { color: 'var(--accent)', background: 'var(--muted)' }
                  : { color: 'var(--text-soft)' }"
                @click="selectItem(item.path)"
              >
                {{ t(item.labelKey) }}
              </button>
            </li>
          </ul>
        </template>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.admin-nav-trigger {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 8px 12px;
  border-radius: var(--radius-md);
  border: 1px solid var(--border);
  background: var(--surface);
  cursor: pointer;
  gap: 8px;
  transition: background 120ms ease;
}

.admin-nav-trigger:hover {
  background: var(--muted);
}

.admin-nav-chevron {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
  transition: transform 150ms ease;
}

.admin-nav-popover {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  border-radius: var(--radius-lg);
  border: 1px solid var(--border);
  background: var(--bg-elev);
  box-shadow: var(--shadow-sm);
  padding: 8px;
  z-index: 40;
}

.admin-nav-item {
  display: flex;
  align-items: center;
  width: 100%;
  padding: 8px 10px;
  border-radius: var(--radius-md);
  background: transparent;
  border: none;
  cursor: pointer;
  font-size: 0.8125rem;
  text-align: left;
  transition: background 120ms ease, color 120ms ease;
}

.admin-nav-item:not(.admin-nav-item--active):hover {
  background: var(--muted);
  color: var(--text) !important;
}

.admin-nav-popover-enter-active,
.admin-nav-popover-leave-active {
  transition: opacity 120ms ease, transform 120ms ease;
}

.admin-nav-popover-enter-from,
.admin-nav-popover-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
