<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { appConfig } from '../../config/app';
import { useShellLayout } from '../../composables/useShellLayout';
import { useSession } from '../../composables/useSession';
import { useTenantSelection } from '../../composables/useTenantSelection';
import AvatarMenu from './AvatarMenu.vue';
import TenantSelector from './TenantSelector.vue';

const { t } = useI18n();
const { titleKey, activeSection } = useShellLayout();
const { memberships } = useSession();
const { selectedMembership, selectedTenantId, setSelectedTenantId } = useTenantSelection();

const currentSectionTitle = computed(() => (titleKey.value ? t(titleKey.value) : appConfig.appName));

// design.md decision 11 / design/04 §6: /platform/** never shows the tenant
// pill or selector — the title already reads "Plataforma" (titleKey above),
// and /platform/credentials owns its own tenant selector in-screen (task 5.3).
const isPlatformSection = computed(() => activeSection.value === 'platform');

function translateRole(role: string | null | undefined): string {
  return t(`common.roles.${role ?? 'unknown'}`);
}
</script>

<template>
  <header class="border-b border-[color:var(--border)] bg-[var(--surface)]">
    <!-- Single row at ≥md -->
    <div class="flex items-center justify-between gap-4 px-4 py-3 sm:px-5 sm:py-3.5">

      <!-- Left: section title + tenant pill -->
      <div class="flex min-w-0 items-center gap-3">
        <h1 class="text-h1 min-w-0 truncate text-[var(--text)]">
          {{ currentSectionTitle }}
        </h1>
        <span
          v-if="selectedMembership && !isPlatformSection"
          class="text-micro hidden shrink-0 rounded-sm bg-[var(--muted)] px-2 py-0.5 text-[var(--text-soft)] sm:inline-flex"
        >
          {{ selectedMembership.tenant_name }}
        </span>
      </div>

      <!-- Right: TenantSelector (multi-tenant only) + avatar menu -->
      <div class="flex shrink-0 items-center gap-2">
        <TenantSelector
          v-if="memberships.length > 1 && !isPlatformSection"
          :memberships="memberships"
          :model-value="selectedTenantId"
          :role-label="translateRole"
          @update:model-value="setSelectedTenantId"
        />

        <AvatarMenu />
      </div>

    </div>
  </header>
</template>
