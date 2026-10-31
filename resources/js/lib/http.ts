import { csrfHeaders } from './kanban';

/** A JSON answer from a Bridge web endpoint: ok + status + the decoded body (also on 4xx, which carry fields). */
export type JsonResult<T = Record<string, unknown>> = { ok: boolean; status: number; data: T & ErrorBody };
export type ErrorBody = { message?: string; errors?: Record<string, string[]> };

/**
 * Session JSON request (CSRF from the XSRF-TOKEN cookie). Never throws for HTTP errors: the auth screens need the
 * extra fields of 422 / 429 / 403 answers (tries_left, locked_until, deletion_pending). Network failure = status 0.
 */
export async function requestJson<T = Record<string, unknown>>(
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  url: string,
  body?: Record<string, unknown> | FormData,
): Promise<JsonResult<T>> {
  const form = body instanceof FormData;
  let res: Response;
  try {
    res = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(form || body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...csrfHeaders(),
      },
      body: form ? body : body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    return { ok: false, status: 0, data: { message: 'Bridge could not be reached. Check your connection and try again.' } as T & ErrorBody };
  }
  const data = (await res.json().catch(() => ({}))) as T & ErrorBody;
  if (res.status === 419) data.message = 'Your session expired. Reload the page and try again.';
  return { ok: res.ok, status: res.status, data };
}

/** First message per field: {email: ['…']} -> {email: '…'}. */
export function fieldErrors(errors: Record<string, string[]> | undefined): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(errors ?? {})) if (v[0]) out[k] = v[0];
  return out;
}

/** Full page load after login / logout, so every shared prop (user, features) is fresh. */
export function go(url: string) {
  window.location.assign(url);
}
