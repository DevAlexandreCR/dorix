<script setup lang="ts">
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import ForbiddenState from '../../../components/ui/ForbiddenState.vue';
import PlatformNav from '../components/PlatformNav.vue';

const route = useRoute();
const { t } = useI18n();

// Set by the router guard (router/guards.ts) when the active route's
// meta.requires (canManagePlatform) fails against the current membership —
// same mechanism modules/admin/views/AdminLayout.vue uses.
const forbidden = computed(() => route.meta.forbidden === true);
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-4">
    <PlatformNav v-if="!forbidden" />
    <div class="min-w-0 flex-1">
      <ForbiddenState
        v-if="forbidden"
        :title="t('states.restrictedTitle')"
        :description="t('platform.noAccess')"
      />
      <RouterView v-else />
    </div>
  </div>
</template>
