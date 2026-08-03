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
            class="transition-colors duration-150 text-[var(--text-mute)]"
          >
            {{ t('admin.tab') }}
          </RouterLink>
        </li>

        <template v-if="props.group">
          <li aria-hidden="true" class="text-[var(--text-mute)]">›</li>
          <li class="text-[var(--text-mute)]">{{ t(props.group) }}</li>
          <li aria-hidden="true" class="text-[var(--text-mute)]">›</li>
          <li aria-current="page" class="text-[var(--text-soft)]">{{ t(props.panel) }}</li>
        </template>

        <template v-else>
          <li aria-hidden="true" class="text-[var(--text-mute)]">›</li>
          <li aria-current="page" class="text-[var(--text-soft)]">{{ t(props.panel) }}</li>
        </template>
      </ol>
    </nav>

    <!-- Title + optional per-panel actions (e.g. "+ Conectar línea") -->
    <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-h1 text-[var(--text)]">
          {{ t(props.panel) }}
        </h1>

        <!-- Contextual copy -->
        <p
          v-if="props.description"
          class="text-body mt-1 text-[var(--text-soft)]"
        >
          {{ t(props.description) }}
        </p>
      </div>

      <div v-if="$slots.actions" class="flex-shrink-0">
        <slot name="actions" />
      </div>
    </div>
  </header>
</template>
