<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { appConfig } from '../../config/app';
import { useShellLayout } from '../../composables/useShellLayout';
import { useSession } from '../../composables/useSession';
import { useTenantSelection } from '../../composables/useTenantSelection';
import LocaleSwitch from './LocaleSwitch.vue';
import TenantSelector from './TenantSelector.vue';
import ThemeSwitch from './ThemeSwitch.vue';

const { t } = useI18n();
const router = useRouter();
const { titleKey } = useShellLayout();
const { authLoading, currentUser, logoutCurrentSession, memberships } = useSession();
const { selectedMembership, selectedTenantId, setSelectedTenantId } = useTenantSelection();

const currentSectionTitle = computed(() => (titleKey.value ? t(titleKey.value) : appConfig.appName));
const avatarLabel = computed(() => {
  const value = currentUser.value?.name?.trim() || currentUser.value?.email?.trim() || appConfig.appName;

  return value
    .split(/\s+/)
    .filter((part: string) => part !== '')
    .slice(0, 2)
    .map((part: string) => part[0]?.toUpperCase() ?? '')
    .join('');
});

function translateRole(role: string | null | undefined): string {
  return t(`common.roles.${role ?? 'unknown'}`);
}

async function signOut(): Promise<void> {
  await logoutCurrentSession();
  await router.push({ name: 'login' });
}
</script>

<template>
  <header>
    <div class="rounded-[22px] border bg-[var(--surface)] px-4 py-3 shadow-[var(--shadow-panel)] sm:px-5 sm:py-4" :style="{ borderColor: 'var(--border)' }">
      <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
        <div class="flex min-w-0 items-center gap-3">
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[18px] bg-[var(--surface-muted)] text-sm font-bold tracking-[0.14em] text-[var(--accent)]">
            {{ appConfig.appName.slice(0, 2).toUpperCase() }}
          </div>
          <div class="min-w-0">
            <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-[var(--accent)]">
              {{ appConfig.appName }}
            </p>
            <div class="mt-1 flex min-w-0 flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-3">
              <h1 class="min-w-0 truncate text-xl font-semibold tracking-tight sm:text-2xl">{{ currentSectionTitle }}</h1>
              <p class="min-w-0 truncate text-sm text-[var(--text-muted)]">{{ selectedMembership?.tenant_name }}</p>
            </div>
          </div>
        </div>

        <div class="grid min-w-0 gap-3 xl:grid-cols-[minmax(270px,360px)_auto_auto] xl:items-center">
          <TenantSelector
            :memberships="memberships"
            :model-value="selectedTenantId"
            :role-label="translateRole"
            @update:model-value="setSelectedTenantId"
          />

          <div class="flex flex-wrap items-center gap-2 xl:justify-end">
            <LocaleSwitch />
            <ThemeSwitch />
            <button class="btn-secondary w-full justify-center sm:w-auto" type="button" :disabled="authLoading" @click="signOut">
              {{ authLoading ? t('auth.loggingOut') : t('auth.logout') }}
            </button>
          </div>

          <div class="flex min-w-0 items-center gap-3 rounded-[18px] border bg-[var(--surface-muted)] px-3 py-2" :style="{ borderColor: 'var(--border)' }">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[14px] bg-[var(--surface)] text-sm font-bold text-[var(--accent)]">
              {{ avatarLabel }}
            </div>
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold">{{ currentUser?.name }}</p>
              <p class="truncate text-xs text-[var(--text-muted)]">{{ currentUser?.email }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>
</template>
