<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

const props = defineProps<{
  group?: string;
  panel: string;
  description?: string;
}>();

const { t } = useI18n();
</script>

<template>
  <header class="flex flex-col pb-6">
    <!-- Breadcrumb -->
    <nav aria-label="Breadcrumb">
      <ol class="flex items-center gap-1 text-small flex-wrap">
        <li>
          <RouterLink
            to="/admin"
            class="transition-colors duration-150"
            :style="{ color: 'var(--text-mute)' }"
          >
            {{ t('admin.tab') }}
          </RouterLink>
        </li>

        <template v-if="props.group">
          <li aria-hidden="true" :style="{ color: 'var(--text-mute)' }">›</li>
          <li :style="{ color: 'var(--text-mute)' }">{{ t(props.group) }}</li>
          <li aria-hidden="true" :style="{ color: 'var(--text-mute)' }">›</li>
          <li aria-current="page" :style="{ color: 'var(--text-soft)' }">{{ t(props.panel) }}</li>
        </template>

        <template v-else>
          <li aria-hidden="true" :style="{ color: 'var(--text-mute)' }">›</li>
          <li aria-current="page" :style="{ color: 'var(--text-soft)' }">{{ t(props.panel) }}</li>
        </template>
      </ol>
    </nav>

    <!-- Title -->
    <h1 class="text-h1 mt-2" :style="{ color: 'var(--text)' }">
      {{ t(props.panel) }}
    </h1>

    <!-- Contextual copy -->
    <p
      v-if="props.description"
      class="text-body mt-1"
      :style="{ color: 'var(--text-soft)' }"
    >
      {{ t(props.description) }}
    </p>
  </header>
</template>
