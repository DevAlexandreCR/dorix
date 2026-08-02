<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import SurfaceCard from '../../../components/ui/SurfaceCard.vue';
import FormField from '../../../components/ui/FormField.vue';
import InlineAlert from '../../../components/ui/InlineAlert.vue';
import { useSession } from '../../../composables/useSession';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const { authError, authLoading, loginWithPassword } = useSession();

const email = ref('');
const password = ref('');

function safeRedirect(value: unknown, fallback: string): string {
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//')
    ? value
    : fallback;
}

async function submitLogin(): Promise<void> {
  const session = await loginWithPassword(email.value, password.value);
  password.value = '';

  if (session?.authenticated) {
    await router.push(safeRedirect(route.query.redirect, '/operations'));
  }
}
</script>

<template>
  <SurfaceCard padding="lg">
    <div class="space-y-6">
      <div class="space-y-3">
        <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[var(--accent)]">
          {{ t('auth.accessEyebrow') }}
        </p>
        <div class="space-y-2">
          <h2 class="text-3xl font-semibold tracking-tight lg:text-[2.1rem]">{{ t('auth.title') }}</h2>
          <p class="text-sm leading-6 text-[var(--text-mute)]">
            {{ t('auth.subtitle') }}
          </p>
        </div>
      </div>

      <form class="grid gap-5" @submit.prevent="submitLogin">
        <FormField :label="t('auth.email')">
          <input v-model="email" class="input-base" type="email" autocomplete="email" required />
        </FormField>

        <FormField :label="t('auth.password')">
          <input
            v-model="password"
            class="input-base"
            type="password"
            autocomplete="current-password"
            required
          />
        </FormField>

        <InlineAlert v-if="authError" :message="authError" tone="danger" />

        <button class="btn-primary mt-2 w-full justify-center py-3.5" type="submit" :disabled="authLoading">
          {{ authLoading ? t('auth.submitting') : t('auth.submit') }}
        </button>
      </form>
    </div>
  </SurfaceCard>
</template>
