<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useNavigationAccess } from '../../composables/useNavigationAccess';
import { useSession } from '../../composables/useSession';
import { useTenantSelection } from '../../composables/useTenantSelection';
import LocaleSwitch from './LocaleSwitch.vue';
import SectionNav from './SectionNav.vue';
import TenantSelector from './TenantSelector.vue';
import ThemeSwitch from './ThemeSwitch.vue';

const { t } = useI18n();
const router = useRouter();
const { authLoading, currentUser, logoutCurrentSession, memberships } = useSession();
const { selectedMembership, selectedTenantId, setSelectedTenantId } = useTenantSelection();
const { canAccessAdmin, canAccessSandbox } = useNavigationAccess(selectedMembership);

function translateRole(role: string | null | undefined): string {
  return t(`common.roles.${role ?? 'unknown'}`);
}

async function signOut(): Promise<void> {
  await logoutCurrentSession();
  await router.push({ name: 'login' });
}
</script>

<template>
  <header class="mb-5">
    <div class="rounded-[32px] border bg-[color:color-mix(in_srgb,var(--surface)_94%,transparent)] p-4 shadow-[var(--shadow-panel)] backdrop-blur-sm lg:p-5" :style="{ borderColor: 'var(--border)' }">
      <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
        <div class="space-y-2">
          <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[var(--accent)]">
            {{ t('common.appEyebrow') }}
          </p>
          <div>
            <h1 class="text-2xl font-semibold tracking-tight lg:text-[2rem]">{{ currentUser?.name }}</h1>
            <p class="text-sm text-[var(--text-muted)]">{{ currentUser?.email }}</p>
          </div>
        </div>

        <div class="flex flex-col gap-3 xl:items-end">
          <div class="flex flex-wrap items-center gap-2">
            <LocaleSwitch />
            <ThemeSwitch />
            <button class="btn-secondary" type="button" :disabled="authLoading" @click="signOut">
              {{ authLoading ? t('auth.loggingOut') : t('auth.logout') }}
            </button>
          </div>

          <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
            <TenantSelector
              :memberships="memberships"
              :model-value="selectedTenantId"
              :role-label="translateRole"
              @update:model-value="setSelectedTenantId"
            />
            <SectionNav :can-access-admin="canAccessAdmin" :can-access-sandbox="canAccessSandbox" />
          </div>
        </div>
      </div>
    </div>
  </header>
</template>
