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
    <div class="rounded-[28px] border bg-[color:color-mix(in_srgb,var(--surface)_94%,transparent)] p-4 shadow-[var(--shadow-panel)] backdrop-blur-sm sm:p-5" :style="{ borderColor: 'var(--border)' }">
      <div class="flex flex-col gap-4 lg:gap-5 xl:flex-row xl:items-start xl:justify-between">
        <div class="min-w-0 space-y-2">
          <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[var(--accent)]">
            {{ t('common.appEyebrow') }}
          </p>
          <div>
            <h1 class="text-2xl font-semibold tracking-tight sm:text-[2rem]">{{ currentUser?.name }}</h1>
            <p class="break-all text-sm text-[var(--text-muted)] sm:break-normal">{{ currentUser?.email }}</p>
          </div>
        </div>

        <div class="flex min-w-0 flex-col gap-3 xl:items-end">
          <div class="grid gap-2 sm:flex sm:flex-wrap sm:items-center sm:justify-start xl:justify-end">
            <LocaleSwitch />
            <ThemeSwitch />
            <button class="btn-secondary w-full justify-center sm:w-auto" type="button" :disabled="authLoading" @click="signOut">
              {{ authLoading ? t('auth.loggingOut') : t('auth.logout') }}
            </button>
          </div>

          <div class="grid gap-3 xl:grid-cols-[minmax(260px,360px)_auto] xl:items-end">
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
