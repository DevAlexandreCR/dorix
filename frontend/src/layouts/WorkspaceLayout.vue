<script setup lang="ts">
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import AppShell from '../app/AppShell.vue';
import TopBar from '../components/shell/TopBar.vue';

const route = useRoute();
const lockViewport = computed(() => route.meta.section === 'operations' || route.meta.section === 'sandbox');
</script>

<template>
  <AppShell :lock-viewport="lockViewport">
    <TopBar />
    <main class="flex min-h-0 flex-1 flex-col" :class="lockViewport ? 'xl:overflow-hidden' : ''">
      <RouterView />
    </main>
  </AppShell>
</template>
