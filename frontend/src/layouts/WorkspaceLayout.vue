<script setup lang="ts">
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import AppShell from '../app/AppShell.vue';
import SectionNav from '../components/shell/SectionNav.vue';
import TopBar from '../components/shell/TopBar.vue';
import BottomNav from '../components/shell/BottomNav.vue';

const route = useRoute();
const lockViewport = computed(() => route.meta.section === 'operations' || route.meta.section === 'sandbox');
</script>

<template>
  <AppShell :lock-viewport="lockViewport">
    <div class="flex min-h-0 flex-1 flex-col gap-4 lg:flex-row" :class="lockViewport ? 'xl:overflow-hidden' : ''">
      <SectionNav />
      <div class="flex min-h-0 flex-1 flex-col gap-4" :class="lockViewport ? 'xl:overflow-hidden' : ''">
        <TopBar />
        <main class="flex min-h-0 flex-1 flex-col pb-14 lg:pb-0" :class="lockViewport ? 'xl:overflow-hidden' : ''">
          <RouterView />
        </main>
      </div>
    </div>
    <BottomNav />
  </AppShell>
</template>
