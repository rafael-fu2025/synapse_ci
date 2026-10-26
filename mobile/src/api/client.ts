import { config } from '../lib/config';
import { getRefreshToken, setRefreshToken } from '../lib/keychain';
import { ErrorBody } from './types';

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fields: Record<string, string[]>;

  constructor(status: number, body: ErrorBody) {
    super(body.message);
    this.status = status;
    this.code = body.code;
    this.fields = body.fields ?? {};
  }
}

export class NetworkError extends Error {
  constructor(message = 'Cannot reach the server. Check your connection and try again.') {
    super(message);
  }
}

const TIMEOUT_READ_MS = 10_000;
const TIMEOUT_MUTATION_MS = 45_000;

// The access token lives in memory only; the refresh token stays in the keychain.
let accessToken: string | null = null;
let refreshInFlight: Promise<boolean> | null = null;
let sessionExpiredHandler: (() => void) | null = null;

export function setAccessToken(token: string | null): void {
  accessToken = token;
}

export function getAccessToken(): string | null {
  return accessToken;
}

export function setSessionExpiredHandler(handler: (() => void) | null): void {
  sessionExpiredHandler = handler;
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'DELETE';
  body?: unknown;
  /** false for the auth endpoints themselves. */
  authenticated?: boolean;
}

/** Single request without refresh handling; throws ApiError/NetworkError. */
async function rawRequest<T>(path: string, options: RequestOptions, token: string | null): Promise<T> {
  const method = options.method ?? 'GET';
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), method === 'GET' ? TIMEOUT_READ_MS : TIMEOUT_MUTATION_MS);

  let response: Response;
  try {
    response = await fetch(`${config.apiBaseUrl}/${path}`, {
      method,
      signal: controller.signal,
      headers: {
        Accept: 'application/json',
        ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(token !== null ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    });
  } catch {
    throw new NetworkError();
  } finally {
    clearTimeout(timeout);
  }

  if (response.status === 204) {
    return undefined as T;
  }
  const payload: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const body = (payload as { error?: ErrorBody } | null)?.error;
    throw new ApiError(response.status, body ?? { code: 'unknown_error', message: 'The request could not be completed.' });
  }
  return payload as T;
}

async function performRefresh(): Promise<boolean> {
  const refreshToken = await getRefreshToken();
  if (refreshToken === null) {
    return false;
  }
  try {
    const payload = await rawRequest<{ token: { access_token: string; refresh_token: string } }>(
      'api/v1/auth/refresh',
      { method: 'POST', body: { refresh_token: refreshToken }, authenticated: false },
      null,
    );
    accessToken = payload.token.access_token;
    await setRefreshToken(payload.token.refresh_token);
    return true;
  } catch {
    return false;
  }
}

/** Concurrent 401s share one refresh attempt; a failing or repeated 401 ends the session. */
function refresh(): Promise<boolean> {
  if (refreshInFlight === null) {
    refreshInFlight = performRefresh().finally(() => {
      refreshInFlight = null;
    });
  }
  return refreshInFlight;
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const authenticated = options.authenticated !== false;
  try {
    return await rawRequest<T>(path, options, authenticated ? accessToken : null);
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 401 || !authenticated || path.startsWith('api/v1/auth/')) {
      throw error;
    }
    if (!(await refresh())) {
      sessionExpiredHandler?.();
      throw error;
    }
    try {
      return await rawRequest<T>(path, options, accessToken);
    } catch (retry) {
      if (retry instanceof ApiError && retry.status === 401) {
        sessionExpiredHandler?.();
      }
      throw retry;
    }
  }
}

/** Session restore: exchange the stored refresh token for a fresh access token. */
export function refreshSession(): Promise<boolean> {
  return refresh();
}

/** Raw authorized fetch for non-JSON bodies (multipart uploads); one refresh retry on 401. */
export async function authorizedFetch(path: string, init: RequestInit): Promise<Response> {
  const build = (): RequestInit => {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (accessToken !== null) {
      headers.set('Authorization', `Bearer ${accessToken}`);
    }
    return { ...init, headers };
  };
  const response = await fetch(`${config.apiBaseUrl}/${path}`, build());
  if (response.status !== 401 || !(await refresh())) {
    return response;
  }
  return fetch(`${config.apiBaseUrl}/${path}`, build());
}

/** Test hook: reset module state between test cases. */
export function __resetClientForTests(): void {
  accessToken = null;
  refreshInFlight = null;
  sessionExpiredHandler = null;
}
