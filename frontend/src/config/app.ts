const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? '/api';

export const appConfig = {
  appName: import.meta.env.VITE_APP_NAME ?? 'Gorda Auto',
  apiBaseUrl,
  backendHealthUrl: import.meta.env.VITE_BACKEND_HEALTH_URL ?? `${apiBaseUrl}/health`,
  backendMetaUrl: `${apiBaseUrl}/v1/meta`,
} as const;

