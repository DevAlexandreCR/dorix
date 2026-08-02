<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ChevronDown, LogOut } from 'lucide-vue-next';
import { useSession } from '../../composables/useSession';
import LocaleSwitch from './LocaleSwitch.vue';
import ThemeSwitch from './ThemeSwitch.vue';

const { t } = useI18n();
const router = useRouter();
const { authLoading, currentUser, logoutCurrentSession } = useSession();

const open = ref(false);
const triggerRef = ref<HTMLElement | null>(null);
const popoverRef = ref<HTMLElement | null>(null);

const avatarLabel = computed(() => {
  const value = currentUser.value?.name?.trim() || currentUser.value?.email?.trim() || '';

  return value
    .split(/\s+/)
    .filter((part: string) => part !== '')
    .slice(0, 2)
    .map((part: string) => part[0]?.toUpperCase() ?? '')
    .join('');
});

function toggle(): void {
  open.value = !open.value;
}

function close(): void {
  open.value = false;
}

function onPointerDown(event: PointerEvent): void {
  const target = event.target as Node | null;
  if (
    triggerRef.value &&
    !triggerRef.value.contains(target) &&
    popoverRef.value &&
    !popoverRef.value.contains(target)
  ) {
    close();
  }
}

function onKeyDown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close();
  }
}

onMounted(() => {
  document.addEventListener('pointerdown', onPointerDown);
  document.addEventListener('keydown', onKeyDown);
});

onUnmounted(() => {
  document.removeEventListener('pointerdown', onPointerDown);
  document.removeEventListener('keydown', onKeyDown);
});

async function signOut(): Promise<void> {
  close();
  await logoutCurrentSession();
  await router.push({ name: 'login' });
}
</script>

<template>
  <div class="relative">
    <!-- Trigger button -->
    <button
      ref="triggerRef"
      type="button"
      class="avatar-trigger"
      :aria-expanded="open"
      aria-haspopup="true"
      @click="toggle"
    >
      <span class="avatar-circle" aria-hidden="true">
        {{ avatarLabel }}
      </span>
      <span class="avatar-name hidden sm:block" :style="{ color: 'var(--text)' }">
        {{ currentUser?.name }}
      </span>
      <ChevronDown
        class="avatar-chevron"
        :class="{ 'rotate-180': open }"
        :stroke-width="1.75"
        :style="{ color: 'var(--text-mute)' }"
        aria-hidden="true"
      />
    </button>

    <!-- Popover -->
    <Transition name="avatar-menu">
      <div v-if="open" ref="popoverRef" class="avatar-popover" role="menu" aria-label="User menu">

        <!-- User info (readonly) -->
        <div class="avatar-user-info">
          <p class="text-small font-semibold truncate" :style="{ color: 'var(--text)' }">
            {{ currentUser?.name }}
          </p>
          <p class="text-small truncate" :style="{ color: 'var(--text-mute)' }">
            {{ currentUser?.email }}
          </p>
        </div>

        <!-- Divider -->
        <div class="avatar-divider" />

        <!-- Theme toggle row -->
        <div class="avatar-row">
          <span class="text-small" :style="{ color: 'var(--text-soft)' }">
            {{ t('shell.theme') }}
          </span>
          <ThemeSwitch />
        </div>

        <!-- Locale toggle row -->
        <div class="avatar-row">
          <span class="text-small" :style="{ color: 'var(--text-soft)' }">
            {{ t('shell.locale') }}
          </span>
          <LocaleSwitch />
        </div>

        <!-- Divider -->
        <div class="avatar-divider" />

        <!-- Logout -->
        <button
          type="button"
          class="avatar-action-row"
          :disabled="authLoading"
          role="menuitem"
          @click="signOut"
        >
          <LogOut class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
          <span class="text-small">
            {{ authLoading ? t('auth.loggingOut') : t('auth.logout') }}
          </span>
        </button>

      </div>
    </Transition>
  </div>
</template>

<style scoped>
.avatar-trigger {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 38px;
  padding: 0 12px;
  border-radius: var(--radius-md);
  border: 1px solid var(--border);
  background: var(--surface);
  cursor: pointer;
  transition: background 120ms ease;
}

.avatar-trigger:hover {
  background: var(--muted);
}

.avatar-circle {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: var(--accent-100);
  color: var(--accent);
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  flex-shrink: 0;
}

:root[data-theme="light"] .avatar-circle {
  background: var(--accent-50);
}

.avatar-name {
  font-size: 0.8125rem;
  font-weight: 600;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.avatar-chevron {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
  transition: transform 150ms ease;
}

.avatar-popover {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  width: 260px;
  border-radius: var(--radius-lg);
  border: 1px solid var(--border);
  background: var(--bg-elev);
  box-shadow: var(--shadow-sm);
  padding: 8px;
  z-index: 200;
}

.avatar-user-info {
  padding: 8px 10px 10px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow: hidden;
}

.avatar-divider {
  height: 1px;
  background: var(--border);
  margin: 4px 0;
}

.avatar-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 10px;
  gap: 8px;
  border-radius: var(--radius-md);
}

.avatar-action-row {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 8px 10px;
  border-radius: var(--radius-md);
  background: transparent;
  border: none;
  cursor: pointer;
  color: var(--text);
  transition: background 120ms ease;
  text-align: left;
}

.avatar-action-row:hover {
  background: var(--muted);
}

.avatar-action-row:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.avatar-menu-enter-active,
.avatar-menu-leave-active {
  transition: opacity 120ms ease, transform 120ms ease;
}

.avatar-menu-enter-from,
.avatar-menu-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
