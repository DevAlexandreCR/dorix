import { ref } from 'vue';
import type { Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useToast } from '../../../composables/useToast';

export interface AdminActionOptions {
  /** Shown as inline success text and as a toast. Omit for silent actions (e.g. background retries). */
  successMessage?: string;
  /** i18n key used when the thrown error has no usable message. Defaults to `admin.actionFailed`. */
  errorFallbackKey?: string;
}

export interface AdminFeedback {
  loading: Ref<boolean>;
  error: Ref<string | null>;
  success: Ref<string | null>;
  /**
   * Runs `action`, tracking loading/error/success around it and mirroring the
   * outcome into the shared toast singleton (task 2.6's `useToast`). Returns
   * the action's resolved value, or `undefined` if it threw (the error is
   * captured in `error`/toasted, not re-thrown).
   */
  run<T>(action: () => Promise<T>, options?: AdminActionOptions): Promise<T | undefined>;
}

/**
 * Generic loading/error/success + toast wiring for a single admin action
 * surface (e.g. "saving the members list"). Not tied to any one resource —
 * `useAdminResource` creates one of these per resource (tenant, members,
 * lines, ...) so that saving one resource never disturbs another's feedback
 * state.
 */
export function useAdminFeedback(): AdminFeedback {
  const { t } = useI18n();
  const toast = useToast();

  const loading = ref(false);
  const error = ref<string | null>(null);
  const success = ref<string | null>(null);

  function resolveErrorMessage(err: unknown, fallbackKey: string): string {
    return err instanceof Error && err.message !== '' ? err.message : t(fallbackKey);
  }

  async function run<T>(action: () => Promise<T>, options: AdminActionOptions = {}): Promise<T | undefined> {
    loading.value = true;
    error.value = null;
    success.value = null;

    try {
      const result = await action();

      if (options.successMessage) {
        success.value = options.successMessage;
        toast.success(options.successMessage);
      }

      return result;
    } catch (err) {
      const message = resolveErrorMessage(err, options.errorFallbackKey ?? 'admin.actionFailed');
      error.value = message;
      toast.error(message);
      return undefined;
    } finally {
      loading.value = false;
    }
  }

  return { loading, error, success, run };
}
