<script setup lang="ts">
import { computed, useId } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
  defineProps<{
    id?: string;
    label?: string;
    error?: string;
    disabled?: boolean;
  }>(),
  {
    id: undefined,
    label: undefined,
    error: undefined,
    disabled: false,
  },
);

const model = defineModel<boolean>({ default: false });

const autoId = useId();
const switchId = computed(() => props.id ?? autoId);
const errorId = computed(() => `${switchId.value}-error`);

function toggle(): void {
  if (props.disabled) {
    return;
  }

  model.value = !model.value;
}
</script>

<template>
  <div class="grid gap-1.5">
    <div class="inline-flex items-center gap-2">
      <button
        v-bind="$attrs"
        :id="switchId"
        type="button"
        role="switch"
        class="ui-switch"
        :aria-checked="model"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="error ? errorId : undefined"
        :disabled="disabled"
        @click="toggle"
      >
        <span class="ui-switch-thumb" aria-hidden="true" />
      </button>
      <label v-if="label" class="text-body" :class="{ 'opacity-60': disabled }" :for="switchId">
        {{ label }}
      </label>
    </div>
    <p v-if="error" :id="errorId" class="text-small" style="color: var(--danger)">
      {{ error }}
    </p>
  </div>
</template>
