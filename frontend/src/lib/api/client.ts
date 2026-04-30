import { currentLocale, translate } from '../../i18n';

export interface JsonRequestOptions {
  method?: string;
  body?: FormData | unknown;
  headers?: HeadersInit;
}

export interface ApiErrorPayload {
  code?: string;
  message?: string;
  errors?: Record<string, string[]>;
}

export class ApiError extends Error {
  readonly status: number;

  readonly code: string;

  readonly errors?: Record<string, string[]>;

  constructor(
    message: string,
    status: number,
    code: string,
    errors?: Record<string, string[]>,
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.errors = errors;
  }
}

function readCookie(name: string): string | null {
  const pattern = document.cookie
    .split('; ')
    .find((chunk) => chunk.startsWith(`${name}=`));

  if (!pattern) {
    return null;
  }

  return decodeURIComponent(pattern.split('=').slice(1).join('='));
}

function buildHeaders(options: JsonRequestOptions): Headers {
  const headers = new Headers(options.headers ?? {});
  const method = (options.method ?? 'GET').toUpperCase();

  headers.set('Accept', 'application/json');
  headers.set('Accept-Language', currentLocale());

  if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS') {
    const csrfToken = readCookie('XSRF-TOKEN');

    if (csrfToken) {
      headers.set('X-XSRF-TOKEN', csrfToken);
    }
  }

  if (options.body !== undefined && !(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json');
  }

  return headers;
}

async function parseError(response: Response): Promise<ApiError> {
  try {
    const payload = (await response.json()) as ApiErrorPayload;

    if (payload.message) {
      return new ApiError(
        payload.message,
        response.status,
        payload.code ?? 'api_error',
        payload.errors,
      );
    }

    const firstField = Object.values(payload.errors ?? {})[0];

    if (firstField?.[0]) {
      return new ApiError(
        firstField[0],
        response.status,
        payload.code ?? 'validation_failed',
        payload.errors,
      );
    }
  } catch {
    // Ignore JSON parse failures and fall back to status text.
  }

  return new ApiError(
    translate('api.requestFailed', { status: response.status }),
    response.status,
    'http_error',
  );
}

export async function requestJson<T>(url: string, options: JsonRequestOptions = {}): Promise<T> {
  const response = await fetch(url, {
    method: options.method ?? 'GET',
    credentials: 'include',
    headers: buildHeaders(options),
    body:
      options.body === undefined
        ? undefined
        : options.body instanceof FormData
          ? options.body
          : JSON.stringify(options.body),
  });

  if (!response.ok) {
    throw await parseError(response);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
}

export function getJson<T>(url: string, options: Omit<JsonRequestOptions, 'method' | 'body'> = {}): Promise<T> {
  return requestJson<T>(url, options);
}

export function postJson<T>(
  url: string,
  body: unknown,
  options: Omit<JsonRequestOptions, 'method' | 'body'> = {},
): Promise<T> {
  return requestJson<T>(url, {
    ...options,
    method: 'POST',
    body,
  });
}

export function putJson<T>(
  url: string,
  body: unknown,
  options: Omit<JsonRequestOptions, 'method' | 'body'> = {},
): Promise<T> {
  return requestJson<T>(url, {
    ...options,
    method: 'PUT',
    body,
  });
}

export function patchJson<T>(
  url: string,
  body: unknown,
  options: Omit<JsonRequestOptions, 'method' | 'body'> = {},
): Promise<T> {
  return requestJson<T>(url, {
    ...options,
    method: 'PATCH',
    body,
  });
}

export function deleteJson<T>(
  url: string,
  options: Omit<JsonRequestOptions, 'method' | 'body'> = {},
): Promise<T> {
  return requestJson<T>(url, {
    ...options,
    method: 'DELETE',
  });
}

export function postForm<T>(
  url: string,
  body: FormData,
  options: Omit<JsonRequestOptions, 'method' | 'body'> = {},
): Promise<T> {
  return requestJson<T>(url, {
    ...options,
    method: 'POST',
    body,
  });
}

export async function ensureCsrfCookie(url: string): Promise<void> {
  await fetch(url, {
    method: 'GET',
    credentials: 'include',
    headers: {
      Accept: 'application/json',
    },
  });
}
