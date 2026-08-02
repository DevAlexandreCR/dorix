<template>
  <AppShell>
    <div class="flex min-h-0 flex-1 flex-col justify-center py-4 sm:py-6">
      <div class="mb-4 flex flex-wrap justify-end gap-2">
        <LocaleSwitch />
        <ThemeSwitch />
      </div>

      <div class="flex flex-1 items-center">
        <div class="mx-auto grid w-full max-w-6xl gap-4 lg:grid-cols-[minmax(0,1fr)_460px] lg:gap-6">
          <section
            class="rounded-lg border bg-[var(--surface)] p-6 shadow-[var(--shadow-sm)] sm:p-8 lg:p-10"
            :style="{ borderColor: 'var(--border)' }"
          >
            <div class="space-y-8">
              <div class="space-y-4">
                <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-[var(--accent)]">
                  {{ t('common.appEyebrow') }}
                </p>
                <h1 class="max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl">
                  {{ appConfig.appName }}
                </h1>
                <p class="max-w-2xl text-sm leading-6 text-[var(--text-mute)] sm:text-base sm:leading-7">
                  {{ t('common.appLede') }}
                </p>
              </div>

              <div class="grid gap-3 sm:grid-cols-3">
                <span
                  class="rounded-lg border px-4 py-3 text-xs font-semibold uppercase tracking-[0.16em] text-[var(--text-mute)]"
                  :style="{ borderColor: 'var(--border)' }"
                >
                  {{ t('operations.tab') }}
                </span>
                <span
                  class="rounded-lg border px-4 py-3 text-xs font-semibold uppercase tracking-[0.16em] text-[var(--text-mute)]"
                  :style="{ borderColor: 'var(--border)' }"
                >
                  {{ t('sandbox.tab') }}
                </span>
                <span
                  class="rounded-lg border px-4 py-3 text-xs font-semibold uppercase tracking-[0.16em] text-[var(--text-mute)]"
                  :style="{ borderColor: 'var(--border)' }"
                >
                  {{ t('admin.tab') }}
                </span>
              </div>

              <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
                <div class="rounded-lg border px-4 py-4 text-sm leading-6 text-[var(--text-mute)]" :style="{ borderColor: 'var(--border)' }">
                  {{ t('auth.workspaceNote') }}
                </div>
                <a
                  :href="appConfig.backendHealthUrl"
                  class="btn-secondary justify-center whitespace-nowrap"
                  :style="{ borderColor: 'var(--border)' }"
                  rel="noreferrer"
                  target="_blank"
                >
                  {{ t('common.apiHealth') }}
                </a>
              </div>
            </div>
          </section>

          <div class="w-full lg:justify-self-end">
            <RouterView />
          </div>
        </div>
      </div>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router';
import { useI18n } from 'vue-i18n';
import AppShell from '../app/AppShell.vue';
import LocaleSwitch from '../components/shell/LocaleSwitch.vue';
import ThemeSwitch from '../components/shell/ThemeSwitch.vue';
import { appConfig } from '../config/app';

const { t } = useI18n();
</script>
