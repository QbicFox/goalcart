import { getBootData } from '../boot';
import type { ApiEnvelope } from '../types';

export class ApiError extends Error {
  readonly status: number;
  readonly code?: string;
  readonly data?: unknown;

  constructor(status: number, message: string, code?: string, data?: unknown) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.data = data;
  }
}

export interface RequestOptions extends Omit<RequestInit, 'headers'> {
  headers?: Record<string, string>;
}

/**
 * Fetch helper for the FaraCart REST namespace.
 *
 * Sends the WordPress REST nonce (`X-WP-Nonce`) on every request so the
 * backend authenticates the current admin user (nonce strategy),
 * and unwraps the standard `{ data, meta, pagination }` response envelope
 * used by the plugin API.
 *
 * Pass `unwrap = false` when the caller needs the full envelope (data +
 * meta + pagination), e.g. to read pagination totals alongside a list.
 *
 * Mirrors the reference plugin (WooInsights\api\client).
 */
export async function apiFetch<T>(
  path: string,
  options: RequestOptions = {},
  unwrap = true
): Promise<T> {
  const boot = getBootData();
  const url = `${boot.restBase}${path.startsWith('/') ? path : `/${path}`}`;

  const headers: Record<string, string> = {
    'X-WP-Nonce': boot.nonce,
    ...(options.body ? { 'Content-Type': 'application/json' } : {}),
    ...options.headers,
  };

  let response: Response;
  try {
    response = await fetch(url, { ...options, headers, credentials: 'include' });
  } catch (error) {
    throw new ApiError(
      0,
      'Network error while contacting the FaraCart API.',
      'network_error',
      error
    );
  }

  let payload: unknown = null;
  try {
    payload = await response.json();
  } catch {
    // Non-JSON body (e.g. an HTML error page) — fall through to a generic error.
  }

  if (!response.ok) {
    const code = (payload as { code?: string } | null)?.code;
    const message =
      (payload as { message?: string } | null)?.message ??
      `Request failed with status ${response.status}.`;
    throw new ApiError(response.status, message, code, payload);
  }

  if (!unwrap) {
    return payload as T;
  }

  const envelope = payload as ApiEnvelope<T>;

  // `data: null` is a valid payload — only unwrap when the key exists.
  return envelope?.data !== undefined ? envelope.data : (payload as T);
}
