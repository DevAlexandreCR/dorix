<script setup lang="ts">
import { onBeforeUnmount, type ComponentPublicInstance } from 'vue';
import { useI18n } from 'vue-i18n';
import { CircleCheck, CircleX, Info } from 'lucide-vue-next';
import { useToast, type ToastEntry } from '../../composables/useToast';

// Single global mount point (App.vue) for the toast viewport — see
// useToast.ts for why the state is a module-level singleton rather than
// per-instance. Task 3.1's shared admin feedback composable will call
// useToast().success/error/info from anywhere without needing this
// component passed down.
const { toasts, dismiss } = useToast();
const { t } = useI18n();

const VARIANT_ICON = {
  success: CircleCheck,
  error: CircleX,
  info: Info,
} as const;

interface TimerState {
  timeoutId: ReturnType<typeof setTimeout>;
  remaining: number;
  startedAt: number;
}

const timers = new Map<number, TimerState>();

function clearTimer(id: number): void {
  const timer = timers.get(id);
  if (timer) {
    clearTimeout(timer.timeoutId);
    timers.delete(id);
  }
}

function dismissToast(id: number): void {
  clearTimer(id);
  dismiss(id);
}

function startTimer(toast: ToastEntry, remaining = toast.duration): void {
  const timeoutId = setTimeout(() => dismissToast(toast.id), remaining);
  timers.set(toast.id, { timeoutId, remaining, startedAt: Date.now() });
}

// Called via the v-for element's function ref. Vue re-invokes function
// refs on every list update (not just mount) since a fresh arrow function
// is passed each render — the `timers.has(...)` guard keeps this
// idempotent so a toast's countdown only ever starts once.
function onToastMounted(toast: ToastEntry, el: Element | ComponentPublicInstance | null): void {
  if (!el || timers.has(toast.id)) return;
  startTimer(toast);
}

function pauseTimer(id: number): void {
  const timer = timers.get(id);
  if (!timer) return;
  clearTimeout(timer.timeoutId);
  timer.remaining = Math.max(timer.remaining - (Date.now() - timer.startedAt), 0);
}

function resumeTimer(toast: ToastEntry): void {
  const timer = timers.get(toast.id);
  startTimer(toast, timer?.remaining ?? toast.duration);
}

onBeforeUnmount(() => {
  timers.forEach((timer) => clearTimeout(timer.timeoutId));
  timers.clear();
});
</script>

<template>
  <Teleport to="body">
    <div class="ui-toast-viewport" aria-label="Notificaciones">
      <TransitionGroup name="ui-toast">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          :ref="(el) => onToastMounted(toast, el)"
          class="ui-toast"
          :role="toast.variant === 'error' ? 'alert' : 'status'"
          :aria-live="toast.variant === 'error' ? 'assertive' : 'polite'"
          aria-atomic="true"
          @mouseenter="pauseTimer(toast.id)"
          @mouseleave="resumeTimer(toast)"
        >
          <component :is="VARIANT_ICON[toast.variant]" class="ui-toast-icon" :size="16" aria-hidden="true" />
          <span class="ui-toast-message">{{ toast.message }}</span>
          <button
            type="button"
            class="ui-toast-dismiss"
            :aria-label="t('common.dismiss')"
            @click="dismissToast(toast.id)"
          >
            <svg viewBox="0 0 16 16" width="12" height="12" fill="none" aria-hidden="true">
              <path
                d="M3 3L13 13M13 3L3 13"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
              />
            </svg>
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.ui-toast-viewport {
  position: fixed;
  left: 50%;
  bottom: var(--space-6);
  transform: translateX(-50%);
  display: flex;
  flex-direction: column-reverse;
  align-items: center;
  gap: var(--space-2);
  z-index: 500;
  pointer-events: none;
}

/* Neutral dark surface for every variant (design/mockups/admin-pulso.html
   `.toast`; --text on --bg is the already-audited pair from
   design/contrast-check.md, inverted here — contrast ratio is symmetric
   either way round). --live is reserved for connection status (spec
   "Verde reservado a estado de conexión") and no solid-fill danger/info
   pair is audited, so variants are distinguished by icon, not by a new
   background color. */
.ui-toast {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  max-width: min(420px, calc(100vw - 32px));
  background: var(--text);
  color: var(--bg);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-md);
  padding: var(--space-2) var(--space-3) var(--space-2) var(--space-4);
  pointer-events: auto;
}

.ui-toast-icon {
  flex-shrink: 0;
}

.ui-toast-message {
  font-size: 0.8125rem;
  font-weight: 600;
  line-height: 1.4;
}

.ui-toast-dismiss {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  flex-shrink: 0;
  border-radius: var(--radius-sm);
  color: inherit;
  opacity: 0.8;
}

.ui-toast-dismiss:hover {
  opacity: 1;
  background: color-mix(in srgb, var(--bg) 15%, transparent);
}

/* design/03 §4: the global prefers-reduced-motion rule (style.css) zeroes
   these for users who opt out. */
.ui-toast-enter-active,
.ui-toast-leave-active {
  transition: opacity 160ms ease-out, transform 160ms ease-out;
}

.ui-toast-enter-from,
.ui-toast-leave-to {
  opacity: 0;
  transform: translateY(8px);
}

.ui-toast-leave-active {
  position: absolute;
}
</style>
