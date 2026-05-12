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
  <header class="mb-3">
    <div class="rounded-[22px] border bg-[color:color-mix(in_srgb,var(--surface)_94%,transparent)] p-3 shadow-[var(--shadow-panel)] backdrop-blur-sm sm:p-4" :style="{ borderColor: 'var(--border)' }">
      <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
        <div class="min-w-0">
          <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-[var(--accent)]">
            {{ t('common.appEyebrow') }}
          </p>
          <div class="mt-1 flex min-w-0 flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-3">
            <h1 class="min-w-0 truncate text-xl font-semibold tracking-tight sm:text-2xl">{{ currentUser?.name }}</h1>
            <p class="min-w-0 truncate text-sm text-[var(--text-muted)]">{{ currentUser?.email }}</p>
          </div>
        </div>

        <div class="flex min-w-0 flex-col gap-2 xl:flex-row xl:items-end xl:justify-end">
          <div class="grid gap-2 sm:flex sm:flex-wrap sm:items-center sm:justify-start xl:order-3 xl:justify-end">
            <LocaleSwitch />
            <ThemeSwitch />
            <button class="btn-secondary w-full justify-center px-4 py-2 sm:w-auto" type="button" :disabled="authLoading" @click="signOut">
              {{ authLoading ? t('auth.loggingOut') : t('auth.logout') }}
            </button>
          </div>

          <div class="grid min-w-0 gap-2 sm:grid-cols-[minmax(240px,420px)_auto] sm:items-end xl:order-2 xl:grid-cols-[minmax(260px,360px)_auto]">
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
