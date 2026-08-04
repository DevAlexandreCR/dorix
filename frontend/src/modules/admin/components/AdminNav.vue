<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch, type Component } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Activity, Building2, ChevronDown, ChevronLeft, ChevronRight, Plug, Sparkles } from 'lucide-vue-next';
import { useNavigationAccess } from '../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../composables/useTenantSelection';
import SearchInput from '../../../components/ui/SearchInput.vue';
import UiDrawer from '../../../components/ui/UiDrawer.vue';
import { ADMIN_ROUTE_REQUIRES } from '../router';
import { searchSettings, type SettingsSearchResult } from '../composables/useSettingsSearch';

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

type VisibleGroup = NavGroup & { visibleItems: NavItem[] };

const GROUPS: NavGroup[] = [
  {
    id: 'org',
    labelKey: 'admin.nav.org',
    icon: Building2,
    items: [
      { path: '/admin/org/info', labelKey: 'admin.nav.orgInfo' },
      { path: '/admin/org/members', labelKey: 'admin.nav.orgMembers' },
    ],
  },
  {
    id: 'connect',
    labelKey: 'admin.nav.connect',
    icon: Plug,
    items: [
      { path: '/admin/connect/lines', labelKey: 'admin.nav.connectLines' },
      { path: '/admin/connect/credentials', labelKey: 'admin.nav.connectCredentials' },
      { path: '/admin/connect/data', labelKey: 'admin.nav.connectData' },
    ],
  },
  {
    id: 'assistant',
    labelKey: 'admin.nav.assistant',
    icon: Sparkles,
    items: [
      { path: '/admin/assistant/behavior', labelKey: 'admin.nav.assistantBehavior' },
      { path: '/admin/assistant/tools', labelKey: 'admin.nav.assistantTools' },
      { path: '/admin/assistant/catalog', labelKey: 'admin.nav.assistantCatalog' },
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

// Shared by AdminNav's own item filtering AND the settings search results,
// so a search can never surface (or navigate to) a panel the nav itself
// would hide.
function hasAccess(requires: readonly string[] | undefined): boolean {
  if (!requires || requires.length === 0) return false;
  const acc = access.value as Record<string, { value: boolean }>;
  return requires.some((key) => acc[key]?.value === true);
}

function itemVisible(item: NavItem): boolean {
  return hasAccess(ADMIN_ROUTE_REQUIRES[item.path]);
}

function isPanelAllowed(panelPath: string): boolean {
  return hasAccess(ADMIN_ROUTE_REQUIRES[panelPath]);
}

const visibleGroups = computed<VisibleGroup[]>(() =>
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

// --- settings search (task 4.9 / design.md decision 8) ----------------------

const searchQuery = ref('');
const activeResultIndex = ref(-1);
const desktopSearchRef = ref<InstanceType<typeof SearchInput> | null>(null);
const mobileSearchRef = ref<InstanceType<typeof SearchInput> | null>(null);

const searchResults = computed<SettingsSearchResult[]>(() =>
  searchSettings(searchQuery.value, (key) => t(key), isPanelAllowed),
);

watch(searchResults, (results) => {
  activeResultIndex.value = results.length > 0 ? 0 : -1;
});

function resetSearch(): void {
  searchQuery.value = '';
  activeResultIndex.value = -1;
}

function selectResult(result: SettingsSearchResult): void {
  void router.push({ path: result.panelPath, query: { ...route.query, highlight: result.highlightKey } });
  resetSearch();
  mobileNavOpen.value = false;
}

function onSearchKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown') {
    if (searchResults.value.length === 0) return;
    event.preventDefault();
    activeResultIndex.value = (activeResultIndex.value + 1) % searchResults.value.length;
  } else if (event.key === 'ArrowUp') {
    if (searchResults.value.length === 0) return;
    event.preventDefault();
    activeResultIndex.value =
      (activeResultIndex.value - 1 + searchResults.value.length) % searchResults.value.length;
  } else if (event.key === 'Enter') {
    const result = searchResults.value[activeResultIndex.value] ?? searchResults.value[0];
    if (result) {
      event.preventDefault();
      selectResult(result);
    }
  } else if (event.key === 'Escape') {
    event.preventDefault();
    if (searchQuery.value) {
      resetSearch();
    } else {
      mobileNavOpen.value = false;
    }
  }
}

// "/" focuses the search box, unless the user is already typing somewhere
// else (an input/textarea/select/contenteditable) — otherwise a chat-style
// message box or any text field using "/" would lose the character.
function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  if (target.isContentEditable) return true;
  return target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT';
}

const DESKTOP_MEDIA_QUERY = '(min-width: 1024px)';

async function focusSearch(): Promise<void> {
  if (window.matchMedia(DESKTOP_MEDIA_QUERY).matches) {
    desktopSearchRef.value?.focus();
    return;
  }

  openMobileNav();
  // UiDrawer's own focus trap focuses its first focusable element (the
  // close button) as soon as it opens (useModalBehavior); wait for that to
  // settle before stealing focus back to the search input.
  await nextTick();
  await nextTick();
  mobileSearchRef.value?.focus();
}

function onGlobalKeydown(event: KeyboardEvent): void {
  if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) return;
  if (isTypingTarget(event.target)) return;
  event.preventDefault();
  void focusSearch();
}

onMounted(() => {
  document.addEventListener('keydown', onGlobalKeydown);
});

onUnmounted(() => {
  document.removeEventListener('keydown', onGlobalKeydown);
});

// --- mobile drill-down (<lg): group list -> panel list, full screen --------

const mobileNavOpen = ref(false);
const mobileStep = ref<'groups' | 'panels'>('groups');
const mobileActiveGroup = ref<VisibleGroup | null>(null);

function openMobileNav(): void {
  mobileStep.value = 'groups';
  mobileActiveGroup.value = null;
  resetSearch();
  mobileNavOpen.value = true;
}

function openMobileGroup(group: VisibleGroup): void {
  mobileActiveGroup.value = group;
  mobileStep.value = 'panels';
}

function backToGroups(): void {
  mobileStep.value = 'groups';
  mobileActiveGroup.value = null;
}

function selectItem(path: string): void {
  void router.push({ path, query: route.query });
  mobileNavOpen.value = false;
}

const mobileDrawerTitle = computed(() => {
  if (mobileStep.value === 'panels' && mobileActiveGroup.value) {
    return t(mobileActiveGroup.value.labelKey);
  }
  return t('admin.nav.mobileTitle');
});

watch(route, () => {
  mobileNavOpen.value = false;
  resetSearch();
});
</script>

<template>
  <!-- Sidebar: visible at lg+ -->
  <nav
    class="hidden lg:flex flex-col w-[240px] shrink-0 h-full overflow-y-auto border-r border-[color:var(--border)] bg-[var(--surface)]"
    aria-label="Admin navigation"
  >
    <div class="px-2 pt-2">
      <SearchInput
        id="admin-nav-search-desktop"
        ref="desktopSearchRef"
        v-model="searchQuery"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="searchQuery.trim().length > 0"
        aria-controls="admin-nav-search-listbox-desktop"
        :aria-activedescendant="
          activeResultIndex >= 0 ? `admin-nav-search-option-desktop-${activeResultIndex}` : undefined
        "
        :placeholder="t('admin.nav.search.placeholder')"
        :aria-label="t('admin.nav.search.ariaLabel')"
        shortcut-hint="/"
        :clear-label="t('admin.nav.search.clear')"
        @keydown="onSearchKeydown"
      />
    </div>

    <ul
      v-if="searchQuery.trim()"
      id="admin-nav-search-listbox-desktop"
      role="listbox"
      :aria-label="t('admin.nav.search.resultsLabel')"
      class="admin-nav-search-results"
    >
      <li v-if="searchResults.length === 0" class="admin-nav-search-empty">
        {{ t('admin.nav.search.noResults') }}
      </li>
      <li
        v-for="(result, index) in searchResults"
        :id="`admin-nav-search-option-desktop-${index}`"
        :key="result.id"
        role="option"
        :aria-selected="index === activeResultIndex"
        class="admin-nav-search-option"
        :class="{ 'admin-nav-search-option--active': index === activeResultIndex }"
        @click="selectResult(result)"
        @mouseenter="activeResultIndex = index"
      >
        <span class="text-small admin-nav-search-option-title">{{ result.title }}</span>
        <span class="text-micro admin-nav-search-option-panel">{{ result.panelTitle }}</span>
      </li>
    </ul>

    <template v-else>
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
    </template>
  </nav>

  <!-- Mobile trigger: visible at < lg, opens a full-screen drill-down -->
  <div class="lg:hidden w-full">
    <button
      type="button"
      class="admin-nav-trigger"
      :aria-haspopup="'dialog'"
      :aria-expanded="mobileNavOpen"
      @click="openMobileNav"
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
        :stroke-width="1.75"
        :style="{ color: 'var(--text-mute)' }"
        aria-hidden="true"
      />
    </button>
  </div>

  <UiDrawer
    :open="mobileNavOpen"
    :title="mobileDrawerTitle"
    :close-label="t('common.close')"
    @update:open="mobileNavOpen = $event"
  >
    <SearchInput
      id="admin-nav-search-mobile"
      ref="mobileSearchRef"
      v-model="searchQuery"
      role="combobox"
      aria-autocomplete="list"
      :aria-expanded="searchQuery.trim().length > 0"
      aria-controls="admin-nav-search-listbox-mobile"
      :aria-activedescendant="
        activeResultIndex >= 0 ? `admin-nav-search-option-mobile-${activeResultIndex}` : undefined
      "
      :placeholder="t('admin.nav.search.placeholder')"
      :aria-label="t('admin.nav.search.ariaLabel')"
      :clear-label="t('admin.nav.search.clear')"
      @keydown="onSearchKeydown"
    />

    <ul
      v-if="searchQuery.trim()"
      id="admin-nav-search-listbox-mobile"
      role="listbox"
      :aria-label="t('admin.nav.search.resultsLabel')"
      class="admin-nav-search-results admin-nav-search-results--mobile"
    >
      <li v-if="searchResults.length === 0" class="admin-nav-search-empty">
        {{ t('admin.nav.search.noResults') }}
      </li>
      <li
        v-for="(result, index) in searchResults"
        :id="`admin-nav-search-option-mobile-${index}`"
        :key="result.id"
        role="option"
        :aria-selected="index === activeResultIndex"
        class="admin-nav-search-option"
        :class="{ 'admin-nav-search-option--active': index === activeResultIndex }"
        @click="selectResult(result)"
        @mouseenter="activeResultIndex = index"
      >
        <span class="text-small admin-nav-search-option-title">{{ result.title }}</span>
        <span class="text-micro admin-nav-search-option-panel">{{ result.panelTitle }}</span>
      </li>
    </ul>

    <template v-else>
      <ul v-if="mobileStep === 'groups'" class="admin-nav-mobile-list" role="list">
        <li v-for="group in visibleGroups" :key="group.id">
          <button type="button" class="admin-nav-mobile-group" @click="openMobileGroup(group)">
            <span class="admin-nav-mobile-group-label">
              <component :is="group.icon" class="h-4 w-4" :stroke-width="2" aria-hidden="true" />
              {{ t(group.labelKey) }}
            </span>
            <ChevronRight class="h-4 w-4" :stroke-width="1.75" aria-hidden="true" />
          </button>
        </li>
      </ul>

      <div v-else-if="mobileActiveGroup">
        <button type="button" class="admin-nav-mobile-back" @click="backToGroups">
          <ChevronLeft class="h-4 w-4" :stroke-width="1.75" aria-hidden="true" />
          {{ t('common.back') }}
        </button>
        <ul class="admin-nav-mobile-list" role="list">
          <li v-for="item in mobileActiveGroup.visibleItems" :key="item.path">
            <button
              type="button"
              class="admin-nav-mobile-item"
              :class="{ 'admin-nav-mobile-item--active': isItemActive(item.path) }"
              :aria-current="isItemActive(item.path) ? 'page' : undefined"
              @click="selectItem(item.path)"
            >
              {{ t(item.labelKey) }}
            </button>
          </li>
        </ul>
      </div>
    </template>
  </UiDrawer>
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
}

/* Settings search results (shared shape for the desktop sidebar list and
   the mobile drawer list — `--mobile` only changes spacing/border, since
   the drawer body already provides its own padding). */
.admin-nav-search-results {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 6px 8px 8px;
  padding: 4px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface);
  max-height: 60vh;
  overflow-y: auto;
}

.admin-nav-search-results--mobile {
  margin: 0;
  max-height: none;
}

.admin-nav-search-empty {
  padding: 10px 8px;
  font-size: 0.8125rem;
  color: var(--text-mute);
}

.admin-nav-search-option {
  display: flex;
  flex-direction: column;
  gap: 1px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.admin-nav-search-option--active {
  background: var(--muted);
}

.admin-nav-search-option-title {
  color: var(--text);
  font-weight: 500;
}

.admin-nav-search-option-panel {
  color: var(--text-mute);
}

/* Mobile drill-down (task 4.9): full-screen group list -> panel list,
   rendered inside UiDrawer's body (already `<lg`-only via UiDrawer's own
   width breakpoint, see components/ui/UiDrawer.vue). */
.admin-nav-mobile-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.admin-nav-mobile-group,
.admin-nav-mobile-item {
  display: flex;
  align-items: center;
  width: 100%;
  min-height: 44px;
  padding: 10px 12px;
  border-radius: var(--radius-md);
  background: transparent;
  border: none;
  cursor: pointer;
  font-size: 0.875rem;
  text-align: left;
  color: var(--text-soft);
  transition: background 120ms ease, color 120ms ease;
}

.admin-nav-mobile-group {
  justify-content: space-between;
  color: var(--text);
}

.admin-nav-mobile-group-label {
  display: flex;
  align-items: center;
  gap: 10px;
}

.admin-nav-mobile-group:hover,
.admin-nav-mobile-item:not(.admin-nav-mobile-item--active):hover {
  background: var(--muted);
}

.admin-nav-mobile-item--active {
  color: var(--accent);
  background: var(--muted);
  font-weight: 500;
}

.admin-nav-mobile-back {
  display: flex;
  align-items: center;
  gap: 6px;
  width: 100%;
  min-height: 40px;
  padding: 8px 4px;
  margin-bottom: 4px;
  border: none;
  background: transparent;
  cursor: pointer;
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--text-mute);
}

.admin-nav-mobile-back:hover {
  color: var(--text);
}
</style>
