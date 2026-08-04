import { appConfig } from '../../../config/app';
import type { WhatsAppConnectionMode } from '../types';

// ---------------------------------------------------------------------------
// Facebook JS SDK — minimal ambient typing for the surface this file uses.
// The SDK itself is loaded on-demand (see `loadFacebookSdk`), never bundled.
// ---------------------------------------------------------------------------

interface FacebookAuthResponse {
  code?: string;
}

interface FacebookLoginResponse {
  status?: string;
  authResponse?: FacebookAuthResponse | null;
}

interface FacebookLoginExtras {
  sessionInfoVersion: '3';
  featureType?: 'whatsapp_business_app_onboarding';
}

interface FacebookLoginOptions {
  config_id: string;
  response_type: 'code';
  override_default_response_type: true;
  extras: FacebookLoginExtras;
}

interface FacebookSdk {
  init(params: { appId: string; version: string; xfbml?: boolean }): void;
  login(callback: (response: FacebookLoginResponse) => void, options: FacebookLoginOptions): void;
}

declare global {
  interface Window {
    FB?: FacebookSdk;
    fbAsyncInit?: () => void;
  }
}

// ---------------------------------------------------------------------------
// Public types
// ---------------------------------------------------------------------------

// Re-exports `types.ts`'s `WhatsAppConnectionMode` (task 5.2) — that file is
// the single source of truth for the union; this alias just keeps the name
// this composable's public API was already using.
export type EmbeddedSignupConnectionMode = WhatsAppConnectionMode;

/** Session info (v3) plus the OAuth code, ready to send to `connect` (task 5.2). */
export interface EmbeddedSignupResult {
  code: string;
  /** Absent for the `FINISH_ONLY_WABA` terminal event (no phone number provisioned yet). */
  phoneNumberId: string | null;
  wabaId: string;
  connectionMode: EmbeddedSignupConnectionMode;
}

export type EmbeddedSignupOutcome = { status: 'success'; result: EmbeddedSignupResult } | { status: 'cancelled' };

/**
 * Thrown for genuine failures (SDK failed to load, missing config, malformed
 * `WA_EMBEDDED_SIGNUP` payload, flow timed out). The user closing the popup
 * or declining authorization is `{ status: 'cancelled' }`, never a throw —
 * spec `ui-admin` ("Popup cancelado") treats cancellation as a non-error.
 */
export class EmbeddedSignupError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'EmbeddedSignupError';
  }
}

export interface UseMetaEmbeddedSignup {
  /** Loads the SDK if needed, opens the Meta popup, and resolves once a terminal outcome is known. */
  launch(mode: EmbeddedSignupConnectionMode): Promise<EmbeddedSignupOutcome>;
}

// ---------------------------------------------------------------------------
// config_id resolution (design.md Open Questions)
//
// ASSUMPTION (unverified against Meta's App Dashboard — see design.md Open
// Questions / task 5.1): a single Embedded Signup config works for both
// modes, differentiated only by `extras.featureType` below. This map is the
// single seam if that assumption is wrong: if Meta requires two configs,
// split `VITE_META_ES_CONFIG_ID` into `VITE_META_ES_CONFIG_ID_CLOUD_API` /
// `VITE_META_ES_CONFIG_ID_COEXISTENCE` and change only the two lines inside
// this map — no other code in this file (or its callers) needs to change.
// ---------------------------------------------------------------------------

const EMBEDDED_SIGNUP_CONFIG_IDS: Record<EmbeddedSignupConnectionMode, string> = {
  cloud_api: appConfig.metaEmbeddedSignupConfigId,
  coexistence: appConfig.metaEmbeddedSignupConfigId,
};

function resolveEmbeddedSignupConfigId(mode: EmbeddedSignupConnectionMode): string {
  const configId = EMBEDDED_SIGNUP_CONFIG_IDS[mode];

  if (!configId) {
    throw new EmbeddedSignupError(
      'Missing Meta Embedded Signup config id — set VITE_META_ES_CONFIG_ID in frontend/.env.',
    );
  }

  return configId;
}

// ---------------------------------------------------------------------------
// SDK loader — on-demand, idempotent (never injects the script twice, even
// across concurrent callers) per design.md D7 ("SDK se carga on-demand al
// entrar a la vista, no en el bundle global").
// ---------------------------------------------------------------------------

const FB_SDK_SCRIPT_ID = 'facebook-jssdk';
const FB_SDK_SRC = 'https://connect.facebook.net/en_US/sdk.js';
const FB_SDK_VERSION = 'v21.0';

let sdkLoadPromise: Promise<void> | null = null;

function loadFacebookSdk(): Promise<void> {
  if (window.FB) {
    return Promise.resolve();
  }

  if (sdkLoadPromise) {
    return sdkLoadPromise;
  }

  sdkLoadPromise = new Promise<void>((resolve, reject) => {
    const appId = appConfig.metaAppId;

    if (!appId) {
      sdkLoadPromise = null;
      reject(new EmbeddedSignupError('Missing Meta app id — set VITE_META_APP_ID in frontend/.env.'));
      return;
    }

    window.fbAsyncInit = () => {
      window.FB?.init({ appId, version: FB_SDK_VERSION, xfbml: false });
      resolve();
    };

    if (document.getElementById(FB_SDK_SCRIPT_ID)) {
      // A previous call already injected the script; `fbAsyncInit` above
      // will resolve this promise once it finishes loading.
      return;
    }

    const script = document.createElement('script');
    script.id = FB_SDK_SCRIPT_ID;
    script.src = FB_SDK_SRC;
    script.async = true;
    script.defer = true;
    script.crossOrigin = 'anonymous';
    script.onerror = () => {
      script.remove();
      sdkLoadPromise = null;
      reject(new EmbeddedSignupError('Failed to load the Facebook JS SDK.'));
    };

    document.body.appendChild(script);
  });

  return sdkLoadPromise;
}

// ---------------------------------------------------------------------------
// `WA_EMBEDDED_SIGNUP` message channel
// ---------------------------------------------------------------------------

const TRUSTED_MESSAGE_ORIGINS = new Set(['https://www.facebook.com', 'https://web.facebook.com']);

interface EmbeddedSignupMessage {
  type: string;
  event?: string;
  data?: {
    phoneNumberId?: string;
    wabaId?: string;
  };
}

/** Meta may send `event.data` as a JSON string or (older SDK builds) as an already-parsed object. */
function parseEmbeddedSignupMessage(raw: unknown): EmbeddedSignupMessage | null {
  let candidate: unknown = raw;

  if (typeof raw === 'string') {
    try {
      candidate = JSON.parse(raw);
    } catch {
      return null;
    }
  }

  if (typeof candidate !== 'object' || candidate === null) {
    return null;
  }

  const record = candidate as Record<string, unknown>;

  if (typeof record.type !== 'string') {
    return null;
  }

  const rawData =
    typeof record.data === 'object' && record.data !== null ? (record.data as Record<string, unknown>) : undefined;

  return {
    type: record.type,
    event: typeof record.event === 'string' ? record.event : undefined,
    data: rawData
      ? {
          phoneNumberId: typeof rawData.phone_number_id === 'string' ? rawData.phone_number_id : undefined,
          wabaId: typeof rawData.waba_id === 'string' ? rawData.waba_id : undefined,
        }
      : undefined,
  };
}

/**
 * Terminal `data.event` values per design.md D1 — only these end the flow.
 * Intermediate steps and `CANCEL` (handled separately) are ignored so the
 * backend is never called before the user finished the mode they chose, and
 * never more than once for the same single-use code.
 */
function isTerminalEventForMode(mode: EmbeddedSignupConnectionMode, event: string): boolean {
  if (mode === 'cloud_api') {
    return event === 'FINISH' || event === 'FINISH_ONLY_WABA';
  }

  return event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING';
}

const SIGNUP_TIMEOUT_MS = 60_000;

function runLoginFlow(mode: EmbeddedSignupConnectionMode, configId: string): Promise<EmbeddedSignupOutcome> {
  const fb = window.FB;

  if (!fb) {
    return Promise.reject(new EmbeddedSignupError('Facebook SDK is not initialized.'));
  }

  return new Promise<EmbeddedSignupOutcome>((resolve, reject) => {
    let settled = false;
    let code: string | null = null;
    let sessionInfo: { phoneNumberId: string | null; wabaId: string } | null = null;
    let timeoutId: ReturnType<typeof setTimeout> | null = null;

    function cleanup(): void {
      window.removeEventListener('message', onMessage);

      if (timeoutId !== null) {
        clearTimeout(timeoutId);
      }
    }

    function settleSuccess(): void {
      if (settled || code === null || sessionInfo === null) {
        return;
      }

      settled = true;
      cleanup();
      resolve({
        status: 'success',
        result: { code, phoneNumberId: sessionInfo.phoneNumberId, wabaId: sessionInfo.wabaId, connectionMode: mode },
      });
    }

    function settleCancelled(): void {
      if (settled) {
        return;
      }

      settled = true;
      cleanup();
      resolve({ status: 'cancelled' });
    }

    function settleError(message: string): void {
      if (settled) {
        return;
      }

      settled = true;
      cleanup();
      reject(new EmbeddedSignupError(message));
    }

    function onMessage(event: MessageEvent): void {
      if (!TRUSTED_MESSAGE_ORIGINS.has(event.origin)) {
        return;
      }

      const message = parseEmbeddedSignupMessage(event.data);

      if (!message || message.type !== 'WA_EMBEDDED_SIGNUP') {
        return;
      }

      if (message.event === 'CANCEL') {
        settleCancelled();
        return;
      }

      if (!message.event || !isTerminalEventForMode(mode, message.event)) {
        // Intermediate step, or a terminal event of the *other* mode —
        // ignored (design.md D1): acting on it here would risk calling the
        // backend for a flow the user didn't actually choose/finish.
        return;
      }

      const wabaId = message.data?.wabaId;

      if (!wabaId) {
        settleError('WA_EMBEDDED_SIGNUP terminal event is missing waba_id.');
        return;
      }

      sessionInfo = { wabaId, phoneNumberId: message.data?.phoneNumberId ?? null };
      settleSuccess();
    }

    window.addEventListener('message', onMessage);

    timeoutId = setTimeout(() => {
      settleError('Timed out waiting for the Embedded Signup flow to complete.');
    }, SIGNUP_TIMEOUT_MS);

    try {
      fb.login(
        (response) => {
          const authCode = response.authResponse?.code;

          if (!authCode) {
            // Per Meta's documented Embedded Signup pattern, an absent
            // `authResponse` means the user closed the popup or did not
            // fully authorize — cancellation, not an error.
            settleCancelled();
            return;
          }

          code = authCode;
          settleSuccess();
        },
        {
          config_id: configId,
          response_type: 'code',
          override_default_response_type: true,
          extras: {
            sessionInfoVersion: '3',
            ...(mode === 'coexistence' ? { featureType: 'whatsapp_business_app_onboarding' } : {}),
          },
        },
      );
    } catch {
      settleError('Failed to launch the Meta Embedded Signup popup.');
    }
  });
}

// ---------------------------------------------------------------------------
// Public composable
// ---------------------------------------------------------------------------

/**
 * Loads the Facebook JS SDK on-demand and drives one Embedded Signup attempt
 * end to end, combining the two result channels from design.md D1 (the
 * `FB.login` callback's `authResponse.code` and the `WA_EMBEDDED_SIGNUP`
 * `message` event's session info) into a single typed outcome. Consumed by
 * the connect API call (task 5.2) and `LinesView` (task 5.3) — this
 * composable never calls the backend itself.
 */
export function useMetaEmbeddedSignup(): UseMetaEmbeddedSignup {
  async function launch(mode: EmbeddedSignupConnectionMode): Promise<EmbeddedSignupOutcome> {
    const configId = resolveEmbeddedSignupConfigId(mode);
    await loadFacebookSdk();
    return runLoginFlow(mode, configId);
  }

  return { launch };
}
