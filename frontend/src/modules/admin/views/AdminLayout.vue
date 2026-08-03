<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import AdminNav from '../components/AdminNav.vue';
import ForbiddenState from '../../../components/ui/ForbiddenState.vue';
import { useSettingsHighlight } from '../composables/useSettingsHighlight';

const route = useRoute();
const { t } = useI18n();

// Set by the router guard (router/guards.ts) when the active route's
// meta.requires fails against the current membership's permissions.
const forbidden = computed(() => route.meta.forbidden === true);

// AdminNav's settings search (task 4.9) navigates with `?highlight=<key>`;
// this scrolls to and flashes whichever child view rendered a matching
// `data-settings-key` element. One watcher here covers every admin screen.
const contentRef = ref<HTMLElement | null>(null);
useSettingsHighlight(contentRef);
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-4 lg:flex-row">
    <AdminNav />
    <div ref="contentRef" class="min-w-0 flex-1">
      <ForbiddenState
        v-if="forbidden"
        :title="t('states.restrictedTitle')"
        :description="t('admin.noAccess')"
      />
      <RouterView v-else />
    </div>
  </div>
</template>
