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
                class="data-table-th text-small"
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
              <td
                :colspan="columns?.length ?? 1"
                class="data-table-td data-table-empty text-body"
              >
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

.data-table-th {
  padding: 12px 16px;
  text-align: left;
  font-weight: 600;
  color: var(--text-soft);
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

.data-table-td {
  padding: 12px 16px;
  color: var(--text);
}

.data-table-empty {
  text-align: center;
  color: var(--text-mute);
}
</style>
