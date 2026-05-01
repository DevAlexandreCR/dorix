<template>
  <AppShell>
    <TopBar />
    <main class="flex min-h-0 flex-1 flex-col">
      <RouterView />
    </main>
  </AppShell>
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router';
import AppShell from '../app/AppShell.vue';
import TopBar from '../components/shell/TopBar.vue';
</script>
