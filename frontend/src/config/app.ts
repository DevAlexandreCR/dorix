const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? '/api';

export const appConfig = {
  appName: import.meta.env.VITE_APP_NAME ?? 'Dorix',
  apiBaseUrl,
  sanctumCsrfCookieUrl: import.meta.env.VITE_SANCTUM_CSRF_COOKIE_URL ?? '/sanctum/csrf-cookie',
  backendHealthUrl: import.meta.env.VITE_BACKEND_HEALTH_URL ?? `${apiBaseUrl}/health`,
  authSessionUrl: `${apiBaseUrl}/v1/auth/session`,
  authLoginUrl: `${apiBaseUrl}/v1/auth/login`,
  authLogoutUrl: `${apiBaseUrl}/v1/auth/logout`,
  conversationsUrl: `${apiBaseUrl}/v1/conversations`,
  dataSourcesUrl: `${apiBaseUrl}/v1/data-sources`,
  adminBaseUrl: `${apiBaseUrl}/v1/admin`,
} as const;
