import { reactive } from 'vue';

export type ToastVariant = 'success' | 'error' | 'info';

export interface ToastEntry {
  id: number;
  message: string;
  variant: ToastVariant;
  duration: number;
}

const DEFAULT_DURATION_MS = 4000;

// Module-level singleton state (not per-component instance): every caller
// of useToast() reads/writes the same list, and the single <UiToast/>
// mount point in App.vue is the only place that renders it. This is what
// lets a future shared admin feedback composable (task 3.1) call
// useToast().success(...) from anywhere without needing a toast instance
// passed down through props.
const toasts = reactive<ToastEntry[]>([]);

let nextId = 1;

function dismiss(id: number): void {
  const index = toasts.findIndex((toast) => toast.id === id);
  if (index !== -1) {
    toasts.splice(index, 1);
  }
}

function show(message: string, variant: ToastVariant = 'info', duration = DEFAULT_DURATION_MS): number {
  const id = nextId++;
  toasts.push({ id, message, variant, duration });
  return id;
}

function success(message: string, duration?: number): number {
  return show(message, 'success', duration);
}

function error(message: string, duration?: number): number {
  return show(message, 'error', duration);
}

function info(message: string, duration?: number): number {
  return show(message, 'info', duration);
}

export function useToast() {
  return { toasts, show, success, error, info, dismiss };
}
