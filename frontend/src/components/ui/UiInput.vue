<script setup lang="ts">
import { computed, useId } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
  defineProps<{
    id?: string;
    type?: 'text' | 'email' | 'password' | 'number' | 'tel' | 'search' | 'url';
    placeholder?: string;
    error?: string;
    disabled?: boolean;
    readonly?: boolean;
    required?: boolean;
    autocomplete?: string;
  }>(),
  {
    id: undefined,
    type: 'text',
    placeholder: undefined,
    error: undefined,
    disabled: false,
    readonly: false,
    required: false,
    autocomplete: undefined,
  },
);

const model = defineModel<string | number>({ default: '' });

const autoId = useId();
const inputId = computed(() => props.id ?? autoId);
const errorId = computed(() => `${inputId.value}-error`);
</script>

<template>
  <div class="grid gap-1.5">
    <input
      v-bind="$attrs"
      :id="inputId"
      v-model="model"
      class="input-base"
      :class="{ 'input-error': error }"
      :type="type"
      :placeholder="placeholder"
      :disabled="disabled"
      :readonly="readonly"
      :required="required"
      :autocomplete="autocomplete"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="error ? errorId : undefined"
    />
    <p v-if="error" :id="errorId" class="text-small" style="color: var(--danger)">
      {{ error }}
    </p>
  </div>
</template>
