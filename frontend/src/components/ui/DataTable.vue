<template>
  <div class="data-table-wrapper">
    <div class="overflow-x-auto">
      <table class="data-table min-w-full">
        <thead v-if="columns || $slots.head">
          <tr>
            <slot name="head">
              <th
                v-for="col in columns"
                :key="col.key"
                scope="col"
                :style="{ textAlign: col.align ?? 'left' }"
              >
                {{ col.label }}
              </th>
            </slot>
          </tr>
        </thead>
        <tbody>
          <slot name="body">
            <tr v-if="emptyMessage">
              <td :colspan="columns?.length ?? 1" class="data-table-empty">
                {{ emptyMessage }}
              </td>
            </tr>
          </slot>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  columns?: { key: string; label: string; align?: 'left' | 'right' | 'center' }[];
  emptyMessage?: string;
}>();
</script>

<style scoped>
.data-table-wrapper {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-xs);
  overflow: hidden;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
}

.data-table thead tr {
  background: var(--muted);
}

/* Header row: 32px (design/03 §3). `:deep` targets both the default
   column headers above and anything a caller renders via the `head`
   slot, so future adopters (phase 4) get correct density for free
   without needing to know an internal class name. */
.data-table :deep(th) {
  height: 32px;
  padding: 0 16px;
  text-align: left;
  font-size: 0.6875rem; /* 11px */
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-mute);
  white-space: nowrap;
}

.data-table tbody tr {
  border-bottom: 1px solid var(--border);
}

.data-table tbody tr:last-child {
  border-bottom: none;
}

.data-table tbody tr:hover {
  background: var(--muted);
}

/* Body row: 40px (design/03 §3), same `:deep` reasoning as the header. */
.data-table :deep(td) {
  height: 40px;
  padding: 0 16px;
  color: var(--text);
  font-size: 0.8125rem; /* 13px, .text-body */
  vertical-align: middle;
}

/* Matches `:deep(td)`'s specificity so this override actually wins
   (a bare `.data-table-empty` class selector alone loses to the
   `.data-table :deep(td)` element+class combo above). */
.data-table :deep(td.data-table-empty) {
  text-align: center;
  color: var(--text-mute);
}
</style>
