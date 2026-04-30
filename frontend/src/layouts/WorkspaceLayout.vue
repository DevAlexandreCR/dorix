<template>
  <AppShell>
    <TopBar />
    <main class="flex-1">
      <RouterView />
    </main>
  </AppShell>
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router';
import AppShell from '../app/AppShell.vue';
import TopBar from '../components/shell/TopBar.vue';
</script>
