<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { appConfig } from './config/app';
import { fetchHealth, fetchMeta } from './lib/api/platform';
import type { HealthResponse, MetaResponse } from './types/platform';

const health = ref<HealthResponse | null>(null);
const meta = ref<MetaResponse | null>(null);
const error = ref<string | null>(null);
const loading = ref(true);

onMounted(async () => {
  loading.value = true;

  try {
    const [healthPayload, metaPayload] = await Promise.all([
      fetchHealth(),
      fetchMeta(),
    ]);

    health.value = healthPayload;
    meta.value = metaPayload;
  } catch (requestError) {
    error.value =
      requestError instanceof Error
        ? requestError.message
        : 'Unknown error while contacting the backend.';
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <main class="app-shell">
    <section class="hero">
      <p class="eyebrow">Foundation / Phase 0</p>
      <h1>{{ appConfig.appName }}</h1>
      <p class="lede">
        Monorepo base para la plataforma multi-tenant de automatización por
        WhatsApp. Este shell valida que la SPA y la API arrancan separadas y
        conversan por contrato HTTP.
      </p>
      <div class="hero-actions">
        <a :href="appConfig.backendHealthUrl" target="_blank" rel="noreferrer">
          Ver health endpoint
        </a>
        <a :href="appConfig.backendMetaUrl" target="_blank" rel="noreferrer">
          Ver API meta
        </a>
      </div>
    </section>

    <section class="status-grid">
      <article class="panel">
        <div class="panel-header">
          <h2>Backend handshake</h2>
          <span :class="['badge', loading ? 'badge-warn' : error ? 'badge-danger' : 'badge-ok']">
            {{ loading ? 'loading' : error ? 'error' : 'connected' }}
          </span>
        </div>

        <p v-if="error" class="error-copy">{{ error }}</p>

        <dl v-else-if="health" class="data-list">
          <div>
            <dt>Status</dt>
            <dd>{{ health.status }}</dd>
          </div>
          <div>
            <dt>App</dt>
            <dd>{{ health.application }}</dd>
          </div>
          <div>
            <dt>Environment</dt>
            <dd>{{ health.environment }}</dd>
          </div>
          <div>
            <dt>Timestamp</dt>
            <dd>{{ health.timestamp }}</dd>
          </div>
        </dl>
      </article>

      <article class="panel">
        <div class="panel-header">
          <h2>Stack baseline</h2>
        </div>

        <ul v-if="health" class="stack-list">
          <li>API: {{ health.stack.api }}</li>
          <li>Frontend: {{ health.stack.frontend }}</li>
          <li>Database: {{ health.stack.database }}</li>
          <li>Queue: {{ health.stack.queue }}</li>
          <li>Cache: {{ health.stack.cache }}</li>
        </ul>
      </article>

      <article class="panel panel-wide">
        <div class="panel-header">
          <h2>Contracts and placeholders</h2>
        </div>

        <dl v-if="meta" class="data-list">
          <div>
            <dt>API base path</dt>
            <dd>{{ meta.api.base_path }}</dd>
          </div>
          <div>
            <dt>Auth</dt>
            <dd>{{ meta.api.auth }}</dd>
          </div>
          <div>
            <dt>Tenancy</dt>
            <dd>{{ meta.api.tenancy }}</dd>
          </div>
          <div>
            <dt>Backend modules</dt>
            <dd>{{ meta.backend.domain_root }}</dd>
          </div>
          <div>
            <dt>Frontend modules</dt>
            <dd>{{ meta.frontend.module_root }}</dd>
          </div>
        </dl>
      </article>
    </section>
  </main>
</template>
