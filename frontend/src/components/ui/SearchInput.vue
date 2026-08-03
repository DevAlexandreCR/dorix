<script setup lang="ts">
// Search box primitive (design/03 §5: "SearchInput — búsqueda fuzzy de
// ajustes en AdminNav"). It only owns the input chrome (icon, clear
// button, `/` shortcut hint) — combobox semantics (role, aria-expanded,
// aria-controls, aria-activedescendant) are caller-supplied via
// `v-bind`/fallthrough attrs, same `inheritAttrs: false` pattern as
// UiInput, because the only current consumer (AdminNav) needs to wire
// those attributes itself to point at its own results listbox.
import { ref } from 'vue';
import { Search, X } from 'lucide-vue-next';

defineOptions({ inheritAttrs: false });

withDefaults(
  defineProps<{
    id?: string;
    placeholder?: string;
    ariaLabel?: string;
    shortcutHint?: string;
    clearLabel?: string;
  }>(),
  {
    id: undefined,
    placeholder: undefined,
    ariaLabel: undefined,
    shortcutHint: undefined,
    clearLabel: undefined,
  },
);

const emit = defineEmits<{ clear: [] }>();

const model = defineModel<string>({ default: '' });

const inputRef = ref<HTMLInputElement | null>(null);

function focus(): void {
  inputRef.value?.focus();
}

function clear(): void {
  model.value = '';
  emit('clear');
  focus();
}

defineExpose({ focus });
</script>

<template>
  <div class="search-input">
    <Search class="search-input-icon" :stroke-width="1.75" aria-hidden="true" />
    <input
      ref="inputRef"
      v-bind="$attrs"
      v-model="model"
      :id="id"
      type="text"
      class="search-input-field"
      :placeholder="placeholder"
      :aria-label="ariaLabel"
      autocomplete="off"
      spellcheck="false"
    />
    <button
      v-if="model"
      type="button"
      class="search-input-clear"
      :aria-label="clearLabel"
      @click="clear"
    >
      <X class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
    </button>
    <kbd v-else-if="shortcutHint" class="search-input-kbd" aria-hidden="true">{{ shortcutHint }}</kbd>
  </div>
</template>

<style scoped>
.search-input {
  display: flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 8px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface);
  transition: border-color 150ms ease-out;
}

.search-input:focus-within {
  border-color: var(--accent);
}

.search-input-icon {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
  color: var(--text-mute);
}

.search-input-field {
  flex: 1;
  min-width: 0;
  border: none;
  outline: none;
  background: transparent;
  font-size: 0.8125rem;
  color: var(--text);
}

.search-input-field::placeholder {
  color: var(--text-mute);
}

.search-input-clear {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 18px;
  height: 18px;
  border-radius: var(--radius-sm);
  color: var(--text-mute);
  transition: background-color 120ms ease-out, color 120ms ease-out;
}

.search-input-clear:hover {
  background: var(--muted);
  color: var(--text);
}

.search-input-kbd {
  flex-shrink: 0;
  min-width: 16px;
  padding: 1px 4px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-family: var(--font-mono);
  font-size: 0.6875rem;
  line-height: 1.3;
  text-align: center;
  color: var(--text-mute);
  background: var(--muted);
}
</style>
