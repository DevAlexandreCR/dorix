<script setup lang="ts">
import { computed, useId } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
  defineProps<{
    id?: string;
    error?: string;
    disabled?: boolean;
    required?: boolean;
  }>(),
  {
    id: undefined,
    error: undefined,
    disabled: false,
    required: false,
  },
);

const model = defineModel<string>({ default: '' });

const autoId = useId();
const selectId = computed(() => props.id ?? autoId);
const errorId = computed(() => `${selectId.value}-error`);
</script>

<template>
  <div class="grid gap-1.5">
    <select
      v-bind="$attrs"
      :id="selectId"
      v-model="model"
      class="input-base"
      :class="{ 'input-error': error }"
      :disabled="disabled"
      :required="required"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="error ? errorId : undefined"
    >
      <slot />
    </select>
    <p v-if="error" :id="errorId" class="text-small" style="color: var(--danger)">
      {{ error }}
    </p>
  </div>
</template>
