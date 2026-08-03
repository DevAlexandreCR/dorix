<script setup lang="ts">
import { computed, useId } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
  defineProps<{
    id?: string;
    placeholder?: string;
    error?: string;
    disabled?: boolean;
    readonly?: boolean;
    required?: boolean;
    rows?: number;
  }>(),
  {
    id: undefined,
    placeholder: undefined,
    error: undefined,
    disabled: false,
    readonly: false,
    required: false,
    rows: 3,
  },
);

const model = defineModel<string>({ default: '' });

const autoId = useId();
const textareaId = computed(() => props.id ?? autoId);
const errorId = computed(() => `${textareaId.value}-error`);
</script>

<template>
  <div class="grid gap-1.5">
    <textarea
      v-bind="$attrs"
      :id="textareaId"
      v-model="model"
      class="input-base"
      :class="{ 'input-error': error }"
      :placeholder="placeholder"
      :disabled="disabled"
      :readonly="readonly"
      :required="required"
      :rows="rows"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="error ? errorId : undefined"
      style="min-height: unset; padding-top: 6px; padding-bottom: 6px"
    />
    <p v-if="error" :id="errorId" class="text-small" style="color: var(--danger)">
      {{ error }}
    </p>
  </div>
</template>
