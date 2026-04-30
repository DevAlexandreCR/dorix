export interface JsonRequestOptions {
  method?: string;
  body?: FormData | unknown;
  headers?: HeadersInit;
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

async function parseError(response: Response): Promise<string> {
  try {
    const payload = (await response.json()) as { message?: string; errors?: Record<string, string[]> };

    if (payload.message) {
      return payload.message;
    }

    const firstField = Object.values(payload.errors ?? {})[0];

    if (firstField?.[0]) {
      return firstField[0];
    }
  } catch {
    // Ignore JSON parse failures and fall back to status text.
  }

  return `Request failed with status ${response.status}`;
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
    throw new Error(await parseError(response));
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
