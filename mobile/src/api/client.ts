import { config } from '../lib/config';
import { getRefreshToken, setRefreshToken } from '../lib/keychain';
import { ErrorBody } from './types';

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fields: Record<string, string[]>;
  readonly retryAfterSeconds: number | null;

  constructor(
    status: number,
    body: ErrorBody,
    retryAfterSeconds: number | null = null,
  ) {
    super(body.message);
    this.status = status;
    this.code = body.code;
    this.fields = body.fields ?? {};
    this.retryAfterSeconds = retryAfterSeconds;
  }
}

export class NetworkError extends Error {
  constructor(
    message = 'Cannot reach the server. Check your connection and try again.',
  ) {
    super(message);
  }
}

/** An in-flight response belongs to a session that has already ended. */
export class SessionChangedError extends Error {
  constructor() {
    super('This session has ended. Please sign in again.');
  }
}

function parseRetryAfter(header: string | null): number | null {
  if (!header?.trim()) {
    return null;
  }
  const value = header.trim();
  if (/^\d+$/.test(value)) {
    const seconds = Number(value);
    return Number.isSafeInteger(seconds) ? seconds : null;
  }
  // An HTTP date must not turn an invalid numeric value into a parsed date.
  const timestamp = /^[a-z]/i.test(value) ? Date.parse(value) : NaN;
  return Number.isFinite(timestamp)
    ? Math.max(0, Math.ceil((timestamp - Date.now()) / 1000))
    : null;
}

const TIMEOUT_READ_MS = 10_000;
const TIMEOUT_MUTATION_MS = 45_000;

// The access token lives in memory only; the refresh token stays in the keychain.
let accessToken: string | null = null;
let sessionVersion = 0;
let refreshInFlight: Promise<boolean> | null = null;
let sessionExpiredHandler: (() => void) | null = null;

/** Only use at a session boundary (sign-in, sign-out, expiry), not during rotation. */
export function setAccessToken(token: string | null): void {
  sessionVersion += 1;
  accessToken = token;
  refreshInFlight = null;
}

function assertSession(version: number): void {
  if (version !== sessionVersion) {
    throw new SessionChangedError();
  }
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
  headers?: Record<string, string>;
  /** Overrides the default per-method timeout. */
  timeoutMs?: number;
}

/** Single request without refresh handling; throws ApiError/NetworkError. */
async function rawRequest<T>(
  path: string,
  options: RequestOptions,
  token: string | null,
): Promise<T> {
  const method = options.method ?? 'GET';
  const controller = new AbortController();
  const timeout = setTimeout(
    () => controller.abort(),
    options.timeoutMs ??
      (method === 'GET' ? TIMEOUT_READ_MS : TIMEOUT_MUTATION_MS),
  );

  try {
    let response: Response;
    try {
      response = await fetch(`${config.apiBaseUrl}/${path}`, {
        method,
        signal: controller.signal,
        headers: {
          Accept: 'application/json',
          ...options.headers,
          ...(options.body !== undefined
            ? { 'Content-Type': 'application/json' }
            : {}),
          ...(token !== null ? { Authorization: `Bearer ${token}` } : {}),
        },
        body:
          options.body !== undefined ? JSON.stringify(options.body) : undefined,
      });
    } catch {
      // Do not log URLs/queries or transport objects: they may contain personal data.
      throw new NetworkError();
    }

    if (response.status === 204) {
      return undefined as T;
    }
    const payload: unknown = await response.json().catch(() => null);
    if (!response.ok) {
      const body = (payload as { error?: ErrorBody } | null)?.error;
      throw new ApiError(
        response.status,
        body ?? {
          code: 'unknown_error',
          message: 'The request could not be completed.',
        },
        response.status === 429
          ? parseRetryAfter(response.headers.get('Retry-After'))
          : null,
      );
    }
    if (payload === null) {
      throw new ApiError(502, {
        code: 'invalid_response',
        message:
          'The server returned an unreadable response. Please try again.',
      });
    }
    return payload as T;
  } finally {
    clearTimeout(timeout);
  }
}

async function performRefresh(version: number): Promise<boolean> {
  const refreshToken = await getRefreshToken();
  assertSession(version);
  if (refreshToken === null) {
    return false;
  }
  try {
    const payload = await rawRequest<{
      token: { access_token: string; refresh_token: string };
    }>(
      'api/v1/auth/refresh',
      {
        method: 'POST',
        body: { refresh_token: refreshToken },
        authenticated: false,
      },
      null,
    );
    assertSession(version);
    await setRefreshToken(payload.token.refresh_token);
    assertSession(version);
    accessToken = payload.token.access_token;
    return true;
  } catch (error) {
    // A temporary outage must not destroy a valid refresh credential.
    if (error instanceof ApiError && error.status === 401) {
      return false;
    }
    throw error;
  }
}

/** Concurrent 401s share one refresh attempt; a failing or repeated 401 ends the session. */
function refresh(): Promise<boolean> {
  if (refreshInFlight === null) {
    const pending = performRefresh(sessionVersion).finally(() => {
      if (refreshInFlight === pending) {
        refreshInFlight = null;
      }
    });
    refreshInFlight = pending;
  }
  return refreshInFlight;
}

export async function request<T>(
  path: string,
  options: RequestOptions = {},
): Promise<T> {
  const authenticated = options.authenticated !== false;
  const version = sessionVersion;
  const token = authenticated ? accessToken : null;
  try {
    const result = await rawRequest<T>(path, options, token);
    if (authenticated) {
      assertSession(version);
    }
    return result;
  } catch (error) {
    if (authenticated) {
      assertSession(version);
    }
    if (
      !(error instanceof ApiError) ||
      error.status !== 401 ||
      !authenticated ||
      path.startsWith('api/v1/auth/')
    ) {
      throw error;
    }
    // Another request may already have refreshed while this response was in flight.
    if (token === accessToken && !(await refresh())) {
      assertSession(version);
      sessionExpiredHandler?.();
      throw error;
    }
    assertSession(version);
    try {
      const result = await rawRequest<T>(path, options, accessToken);
      assertSession(version);
      return result;
    } catch (retry) {
      assertSession(version);
      if (retry instanceof ApiError && retry.status === 401) {
        sessionExpiredHandler?.();
      }
      throw retry;
    }
  }
}

/**
 * Best-effort server-side revocation for sign-out: one short attempt with the current token,
 * never refreshing, so a failed revoke can not re-store a credential after the user left.
 */
export async function revokeSession(): Promise<void> {
  if (accessToken === null) {
    return;
  }
  try {
    await rawRequest<void>(
      'api/v1/auth/logout',
      { method: 'POST', timeoutMs: 5_000 },
      accessToken,
    );
  } catch {
    // The local credential is discarded regardless; the server token also expires on its own.
  }
}

/** Session restore: exchange the stored refresh token for a fresh access token. */
export function refreshSession(): Promise<boolean> {
  return refresh();
}

/** Raw authorized fetch for non-JSON bodies (multipart uploads); one refresh retry on 401. */
export async function authorizedFetch(
  path: string,
  init: RequestInit,
): Promise<Response> {
  const version = sessionVersion;
  const token = accessToken;
  const attempt = async (): Promise<Response> => {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (accessToken !== null) {
      headers.set('Authorization', `Bearer ${accessToken}`);
    }
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), TIMEOUT_MUTATION_MS);
    try {
      const response = await fetch(`${config.apiBaseUrl}/${path}`, {
        ...init,
        headers,
        signal: controller.signal,
      });
      assertSession(version);
      return response;
    } catch (error) {
      assertSession(version);
      if (error instanceof SessionChangedError) {
        throw error;
      }
      throw new NetworkError();
    } finally {
      clearTimeout(timeout);
    }
  };
  const response = await attempt();
  if (response.status !== 401) {
    return response;
  }
  if (token === accessToken && !(await refresh())) {
    assertSession(version);
    sessionExpiredHandler?.();
    return response;
  }
  assertSession(version);
  const retry = await attempt();
  if (retry.status === 401) {
    sessionExpiredHandler?.();
  }
  return retry;
}

/** Test hook: reset module state between test cases. */
export function __resetClientForTests(): void {
  accessToken = null;
  sessionVersion = 0;
  refreshInFlight = null;
  sessionExpiredHandler = null;
}
