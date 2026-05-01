<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { TenantMembership } from '../../app/providers/session';

defineProps<{
  memberships: TenantMembership[];
  modelValue: number | null;
  roleLabel: (role: string | null | undefined) => string;
}>();

defineEmits<{
  'update:modelValue': [value: number | null];
}>();

const { t } = useI18n();
</script>

<template>
  <label class="grid min-w-0 w-full gap-2 text-sm sm:min-w-[220px]">
    <span class="font-medium text-[var(--text-muted)]">{{ t('common.scopes.tenant') }}</span>
    <select
      :value="modelValue ?? ''"
      class="input-base"
      @change="$emit('update:modelValue', Number(($event.target as HTMLSelectElement).value) || null)"
    >
      <option v-for="membership in memberships" :key="membership.tenant_id" :value="membership.tenant_id">
        {{ membership.tenant_name }} · {{ roleLabel(membership.role) }}
      </option>
    </select>
  </label>
</template>
