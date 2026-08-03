<script setup lang="ts">
import { computed, useId } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
  defineProps<{
    id?: string;
    label?: string;
    error?: string;
    disabled?: boolean;
    required?: boolean;
  }>(),
  {
    id: undefined,
    label: undefined,
    error: undefined,
    disabled: false,
    required: false,
  },
);

const model = defineModel<boolean>({ default: false });

const autoId = useId();
const checkboxId = computed(() => props.id ?? autoId);
const errorId = computed(() => `${checkboxId.value}-error`);
</script>

<template>
  <div class="grid gap-1.5">
    <label class="inline-flex items-center gap-2 text-body" :class="{ 'opacity-60': disabled }" :for="checkboxId">
      <input
        v-bind="$attrs"
        :id="checkboxId"
        v-model="model"
        type="checkbox"
        class="ui-checkbox"
        :disabled="disabled"
        :required="required"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="error ? errorId : undefined"
      />
      <span v-if="label">{{ label }}</span>
      <slot v-else />
    </label>
    <p v-if="error" :id="errorId" class="text-small" style="color: var(--danger)">
      {{ error }}
    </p>
  </div>
</template>
