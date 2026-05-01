<template>
  <AppShell>
    <div class="flex min-h-0 flex-1 flex-col py-4 sm:py-5 lg:py-8">
      <div class="flex flex-wrap justify-end gap-2">
        <LocaleSwitch />
        <ThemeSwitch />
      </div>

      <div class="flex flex-1 items-center">
        <div class="mx-auto grid w-full max-w-6xl gap-5 py-6 sm:py-8 lg:grid-cols-[minmax(0,1fr)_minmax(420px,520px)] lg:gap-10 lg:py-12">
          <section
            class="relative overflow-hidden rounded-[30px] border bg-[color:color-mix(in_srgb,var(--surface)_82%,transparent)] p-6 shadow-[var(--shadow-panel)] backdrop-blur-sm sm:p-8 lg:rounded-[36px] lg:p-10 xl:p-12"
            :style="{ borderColor: 'var(--border)' }"
          >
            <div class="absolute -left-16 top-8 h-40 w-40 rounded-full bg-[color:color-mix(in_srgb,var(--accent)_20%,transparent)] blur-3xl" />
            <div class="absolute bottom-0 right-0 h-56 w-56 rounded-full bg-[rgba(67,113,255,0.12)] blur-3xl" />

            <div class="relative space-y-8">
              <div class="space-y-4">
                <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-[var(--accent)]">
                  {{ t('common.appEyebrow') }}
                </p>
                <h1 class="max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl lg:text-6xl">
                  {{ appConfig.appName }}
                </h1>
                <p class="max-w-2xl text-sm leading-6 text-[var(--text-muted)] sm:text-base sm:leading-7 lg:text-lg">
                  {{ t('common.appLede') }}
                </p>
              </div>

              <div class="flex flex-wrap gap-2">
                <span
                  class="rounded-full border px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-[var(--text-muted)]"
                  :style="{ borderColor: 'var(--border)' }"
                >
                  {{ t('operations.tab') }}
                </span>
                <span
                  class="rounded-full border px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-[var(--text-muted)]"
                  :style="{ borderColor: 'var(--border)' }"
                >
                  {{ t('sandbox.tab') }}
                </span>
                <span
                  class="rounded-full border px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-[var(--text-muted)]"
                  :style="{ borderColor: 'var(--border)' }"
                >
                  {{ t('admin.tab') }}
                </span>
              </div>

              <div class="flex flex-wrap items-center gap-4">
                <a
                  :href="appConfig.backendHealthUrl"
                  class="inline-flex rounded-full border px-4 py-2 text-sm text-[var(--text-muted)] transition hover:text-[var(--text)]"
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
