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
const { titleKey } = useShellLayout();
const { memberships } = useSession();
const { selectedMembership, selectedTenantId, setSelectedTenantId } = useTenantSelection();

const currentSectionTitle = computed(() => (titleKey.value ? t(titleKey.value) : appConfig.appName));

function translateRole(role: string | null | undefined): string {
  return t(`common.roles.${role ?? 'unknown'}`);
}
</script>

<template>
  <header class="border-b" :style="{ borderColor: 'var(--border)', background: 'var(--surface)' }">
    <!-- Single row at ≥md -->
    <div class="flex items-center justify-between gap-4 px-4 py-3 sm:px-5 sm:py-3.5">

      <!-- Left: section title + tenant pill -->
      <div class="flex min-w-0 items-center gap-3">
        <h1 class="text-h1 min-w-0 truncate" :style="{ color: 'var(--text)' }">
          {{ currentSectionTitle }}
        </h1>
        <span
          v-if="selectedMembership"
          class="text-micro hidden shrink-0 rounded-sm px-2 py-0.5 sm:inline-flex"
          :style="{ background: 'var(--muted)', color: 'var(--text-soft)' }"
        >
          {{ selectedMembership.tenant_name }}
        </span>
      </div>

      <!-- Right: TenantSelector (multi-tenant only) + avatar menu -->
      <div class="flex shrink-0 items-center gap-2">
        <TenantSelector
          v-if="memberships.length > 1"
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
