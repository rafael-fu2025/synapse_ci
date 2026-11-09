import { config } from '../lib/config';
import { getRefreshToken, setRefreshToken } from '../lib/keychain';
import { ErrorBody } from './types';

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fields: Record<string, string[]>;
  readonly retryAfterSeconds: number | null;

  constructor(status: number, body: ErrorBody, retryAfterSeconds: number | null = null) {
    super(body.message);
    this.status = status;
    this.code = body.code;
    this.fields = body.fields ?? {};
    this.retryAfterSeconds = retryAfterSeconds;
  }
}

export class NetworkError extends Error {
  constructor(message = 'Cannot reach the server. Check your connection and try again.') {
    super(message);
  }
}

/** Work captured by an environment/account that has already ended. */
export class SessionChangedError extends Error {
  constructor() {
    super('This session has ended. Please sign in again.');
  }
}

export interface ApiRuntime {
  readonly id: number;
  readonly apiBaseUrl: string;
  readonly profileId: 'university' | 'home' | null;
  readonly credentialBinding: string | null;
  readonly lanTest: boolean;
  readonly active: boolean;
  /** Changes at account boundaries, not during access-token rotation. */
  readonly sessionVersion: number;
}

export interface ApiRuntimeOptions {
  apiBaseUrl: string;
  profileId?: ApiRuntime['profileId'];
  credentialBinding?: string | null;
  lanTest?: boolean;
}

export interface ApiSession {
  readonly runtime: ApiRuntime;
  readonly sessionVersion: number;
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'DELETE';
  body?: unknown;
  /** false for the auth endpoints themselves. They still obey runtime/account boundaries. */
  authenticated?: boolean;
  headers?: Record<string, string>;
  timeoutMs?: number;
  signal?: AbortSignal;
}

export interface ApiClient extends ApiSession {
  assertCurrent(): void;
  isCurrent(): boolean;
  request<T>(path: string, options?: RequestOptions): Promise<T>;
  authorizedFetch(path: string, init: RequestInit): Promise<Response>;
  /** Guard asynchronous body/image decoding through its final result as well. */
  run<T>(operation: (signal: AbortSignal) => Promise<T>, timeoutMs?: number): Promise<T>;
}

export type ApiBinding = ApiRuntime | ApiClient;

type RuntimeState = {
  phase: 'created' | 'active' | 'retired';
  accessToken: string | null;
  sessionVersion: number;
  refreshInFlight: Promise<boolean> | null;
  sessionExpiredHandler: (() => void) | null;
  requests: Set<AbortController>;
};

const TIMEOUT_READ_MS = 10_000;
const TIMEOUT_MUTATION_MS = 45_000;
const TIMEOUT_CLEANUP_MS = 5_000;
const states = new WeakMap<ApiRuntime, RuntimeState>();
let nextRuntimeId = 0;
let activeRuntime: ApiRuntime | null = null;

function stateOf(runtime: ApiRuntime): RuntimeState {
  const state = states.get(runtime);
  if (!state) { throw new SessionChangedError(); }
  return state;
}

/** Immutable destination/credential identity. Creating one does not activate it. */
export function createApiRuntime(options: ApiRuntimeOptions): ApiRuntime {
  const state: RuntimeState = {
    phase: 'created', accessToken: null, sessionVersion: 0,
    refreshInFlight: null, sessionExpiredHandler: null, requests: new Set(),
  };
  const runtime: ApiRuntime = Object.freeze({
    id: ++nextRuntimeId,
    apiBaseUrl: options.apiBaseUrl.replace(/\/+$/, ''),
    profileId: options.profileId ?? null,
    credentialBinding: options.credentialBinding ?? null,
    lanTest: options.lanTest ?? false,
    get active() { return state.phase === 'active' && activeRuntime === runtime; },
    get sessionVersion() { return state.sessionVersion; },
  });
  states.set(runtime, state);
  return runtime;
}

export function activateApiRuntime(runtime: ApiRuntime): void {
  const state = stateOf(runtime);
  if (state.phase === 'retired') { throw new SessionChangedError(); }
  if (activeRuntime !== null && activeRuntime !== runtime) { retireApiRuntime(activeRuntime); }
  activeRuntime = runtime;
  state.phase = 'active';
}

/** Normal-mode compatibility only. A LAN build must wait for the connection owner. */
export function getApiRuntime(): ApiRuntime {
  if (activeRuntime !== null && activeRuntime.active) { return activeRuntime; }
  if (config.lanTest) { throw new SessionChangedError(); }
  const runtime = createApiRuntime({ apiBaseUrl: config.apiBaseUrl });
  activateApiRuntime(runtime);
  return runtime;
}

export function retireApiRuntime(runtime: ApiRuntime): void {
  const state = stateOf(runtime);
  if (state.phase === 'retired') { return; }
  state.phase = 'retired';
  state.sessionVersion += 1;
  state.accessToken = null;
  state.refreshInFlight = null;
  state.sessionExpiredHandler = null;
  if (activeRuntime === runtime) { activeRuntime = null; }
  for (const controller of state.requests) { controller.abort(); }
  state.requests.clear();
}

export function captureApiSession(runtime: ApiRuntime = getApiRuntime()): ApiSession {
  return Object.freeze({ runtime, sessionVersion: stateOf(runtime).sessionVersion });
}

export function isApiSessionCurrent(session: ApiSession): boolean {
  return session.runtime.active && stateOf(session.runtime).sessionVersion === session.sessionVersion;
}

export function assertApiSession(session: ApiSession): void {
  if (!isApiSessionCurrent(session)) { throw new SessionChangedError(); }
}

function sessionFor(binding?: ApiBinding): ApiSession {
  if (binding && 'runtime' in binding) { return binding; }
  return captureApiSession(binding);
}

/** Only use at an account boundary, never during access-token rotation. */
export function setAccessToken(token: string | null, runtime: ApiRuntime = getApiRuntime()): void {
  const state = stateOf(runtime);
  if (state.phase === 'retired') { throw new SessionChangedError(); }
  state.sessionVersion += 1;
  state.accessToken = token;
  state.refreshInFlight = null;
  for (const controller of state.requests) { controller.abort(); }
}

export function getAccessToken(runtime: ApiRuntime = getApiRuntime()): string | null {
  return stateOf(runtime).accessToken;
}

export function setSessionExpiredHandler(handler: (() => void) | null, runtime: ApiRuntime = getApiRuntime()): void {
  const state = stateOf(runtime);
  if (state.phase !== 'retired') { state.sessionExpiredHandler = handler; }
}

function parseRetryAfter(header: string | null): number | null {
  if (!header?.trim()) { return null; }
  const value = header.trim();
  if (/^\d+$/.test(value)) {
    const seconds = Number(value);
    return Number.isSafeInteger(seconds) ? seconds : null;
  }
  const timestamp = /^[a-z]/i.test(value) ? Date.parse(value) : NaN;
  return Number.isFinite(timestamp) ? Math.max(0, Math.ceil((timestamp - Date.now()) / 1000)) : null;
}

/** Registry covers fetch AND decoding; retirement also settles transports that ignore abort. */
async function runBound<T>(
  session: ApiSession,
  operation: (signal: AbortSignal) => Promise<T>,
  timeoutMs?: number,
  signal?: AbortSignal | null,
): Promise<T> {
  assertApiSession(session);
  const state = stateOf(session.runtime);
  const controller = new AbortController();
  state.requests.add(controller);
  const abort = () => controller.abort();
  signal?.addEventListener('abort', abort, { once: true });
  const timeout = timeoutMs === undefined ? null : setTimeout(abort, timeoutMs);
  let rejectAborted!: (error: Error) => void;
  const aborted = new Promise<never>((_resolve, reject) => { rejectAborted = reject; });
  const onAbort = () => rejectAborted(isApiSessionCurrent(session) ? new NetworkError() : new SessionChangedError());
  controller.signal.addEventListener('abort', onAbort, { once: true });
  try {
    if (signal?.aborted) { controller.abort(); }
    if (controller.signal.aborted) { return await aborted; }
    const result = await Promise.race([operation(controller.signal), aborted]);
    assertApiSession(session);
    return result;
  } catch (error) {
    assertApiSession(session);
    throw error;
  } finally {
    if (timeout !== null) { clearTimeout(timeout); }
    state.requests.delete(controller);
    signal?.removeEventListener('abort', abort);
    controller.signal.removeEventListener('abort', onAbort);
  }
}

async function decodeJson<T>(response: Response): Promise<T> {
  if (response.status === 204) { return undefined as T; }
  const payload: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const body = (payload as { error?: ErrorBody } | null)?.error;
    throw new ApiError(response.status, body ?? {
      code: 'unknown_error', message: 'The request could not be completed.',
    }, response.status === 429 ? parseRetryAfter(response.headers.get('Retry-After')) : null);
  }
  if (payload === null) {
    throw new ApiError(502, { code: 'invalid_response', message: 'The server returned an unreadable response. Please try again.' });
  }
  return payload as T;
}

function jsonInit(options: RequestOptions, token: string | null, signal: AbortSignal): RequestInit {
  return {
    method: options.method ?? 'GET', signal,
    headers: {
      Accept: 'application/json', ...options.headers,
      ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(token !== null ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  };
}

function rawRequest<T>(session: ApiSession, path: string, options: RequestOptions, token: string | null): Promise<T> {
  const url = `${session.runtime.apiBaseUrl}/${path}`;
  return runBound(session, async signal => {
    let response: Response;
    try { response = await fetch(url, jsonInit(options, token, signal)); }
    catch { assertApiSession(session); throw new NetworkError(); }
    assertApiSession(session);
    const result = await decodeJson<T>(response);
    assertApiSession(session);
    return result;
  }, options.timeoutMs ?? (options.method === undefined || options.method === 'GET' ? TIMEOUT_READ_MS : TIMEOUT_MUTATION_MS), options.signal);
}

async function performRefresh(session: ApiSession): Promise<boolean> {
  const { runtime } = session;
  const state = stateOf(runtime);
  const binding = runtime.credentialBinding;
  const refreshToken = await (binding === null ? getRefreshToken() : getRefreshToken(binding));
  assertApiSession(session);
  if (refreshToken === null) { return false; }
  try {
    const payload = await rawRequest<{ token: { access_token: string; refresh_token: string } }>(
      session, 'api/v1/auth/refresh', { method: 'POST', body: { refresh_token: refreshToken }, authenticated: false }, null,
    );
    assertApiSession(session);
    await (binding === null ? setRefreshToken(payload.token.refresh_token) : setRefreshToken(payload.token.refresh_token, binding));
    assertApiSession(session);
    state.accessToken = payload.token.access_token;
    return true;
  } catch (error) {
    assertApiSession(session);
    if (error instanceof ApiError && error.status === 401) { return false; }
    throw error;
  }
}

function refresh(session: ApiSession): Promise<boolean> {
  assertApiSession(session);
  const state = stateOf(session.runtime);
  if (state.refreshInFlight === null) {
    const pending = runBound(session, () => performRefresh(session)).finally(() => {
      if (state.refreshInFlight === pending) { state.refreshInFlight = null; }
    });
    state.refreshInFlight = pending;
  }
  return state.refreshInFlight;
}

async function requestBound<T>(session: ApiSession, path: string, options: RequestOptions): Promise<T> {
  assertApiSession(session);
  const state = stateOf(session.runtime);
  const authenticated = options.authenticated !== false;
  const token = authenticated ? state.accessToken : null;
  try {
    const result = await rawRequest<T>(session, path, options, token);
    assertApiSession(session);
    return result;
  } catch (error) {
    assertApiSession(session);
    if (!(error instanceof ApiError) || error.status !== 401 || !authenticated || path.startsWith('api/v1/auth/')) { throw error; }
    if (token === state.accessToken && !(await refresh(session))) {
      assertApiSession(session);
      state.sessionExpiredHandler?.();
      throw error;
    }
    assertApiSession(session);
    try {
      const result = await rawRequest<T>(session, path, options, state.accessToken);
      assertApiSession(session);
      return result;
    } catch (retry) {
      assertApiSession(session);
      if (retry instanceof ApiError && retry.status === 401) { state.sessionExpiredHandler?.(); }
      throw retry;
    }
  }
}

export function request<T>(path: string, options: RequestOptions = {}, binding?: ApiBinding): Promise<T> {
  try { return requestBound<T>(sessionFor(binding), path, options); }
  catch (error) { return Promise.reject(error); }
}

/** Single environment/account-bound upload/raw-body fetch; at most one refresh retry. */
async function authorizedFetchBound(session: ApiSession, path: string, init: RequestInit): Promise<Response> {
  assertApiSession(session);
  const state = stateOf(session.runtime);
  const token = state.accessToken;
  const url = `${session.runtime.apiBaseUrl}/${path}`;
  const attempt = (attemptToken: string | null) => runBound(session, async signal => {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    headers.delete('Authorization');
    if (attemptToken !== null) { headers.set('Authorization', `Bearer ${attemptToken}`); }
    try {
      const response = await fetch(url, { ...init, headers, signal });
      assertApiSession(session);
      return response;
    } catch (error) {
      assertApiSession(session);
      if (error instanceof SessionChangedError) { throw error; }
      throw new NetworkError();
    }
  }, TIMEOUT_MUTATION_MS, init.signal);
  const response = await attempt(token);
  assertApiSession(session);
  if (response.status !== 401 || path.startsWith('api/v1/auth/')) { return response; }
  if (token === state.accessToken && !(await refresh(session))) {
    assertApiSession(session);
    state.sessionExpiredHandler?.();
    return response;
  }
  assertApiSession(session);
  const retry = await attempt(state.accessToken);
  assertApiSession(session);
  if (retry.status === 401) { state.sessionExpiredHandler?.(); }
  return retry;
}

export function authorizedFetch(path: string, init: RequestInit, binding?: ApiBinding): Promise<Response> {
  try { return authorizedFetchBound(sessionFor(binding), path, init); }
  catch (error) { return Promise.reject(error); }
}

/** Bind at render/invocation, BEFORE any picker, queued mutation, retry, or other await. */
export function bindApiClient(binding?: ApiBinding, sessionVersion?: number): ApiClient {
  if (binding && 'runtime' in binding && sessionVersion === undefined) { return binding; }
  const captured = sessionFor(binding);
  const session = sessionVersion === undefined ? captured : Object.freeze({ runtime: captured.runtime, sessionVersion });
  const client: ApiClient = Object.freeze({
    ...session,
    assertCurrent: () => assertApiSession(session),
    isCurrent: () => isApiSessionCurrent(session),
    request: <T>(path: string, options: RequestOptions = {}) => request<T>(path, options, client),
    authorizedFetch: (path: string, init: RequestInit) => authorizedFetch(path, init, client),
    run: <T>(operation: (signal: AbortSignal) => Promise<T>, timeoutMs = TIMEOUT_MUTATION_MS) => runBound(session, operation, timeoutMs),
  });
  return client;
}

/** Restore only the runtime/session captured by the caller, sharing that runtime's refresh. */
export function refreshSession(binding?: ApiBinding): Promise<boolean> {
  try { return refresh(sessionFor(binding)); }
  catch (error) { return Promise.reject(error); }
}

export interface RuntimeCleanup {
  request<T>(path: string, options?: RequestOptions): Promise<T | undefined>;
  revokeSession(): Promise<void>;
}

/** Explicit best-effort cleanup is the ONLY work permitted after retirement; never refresh. */
export function captureRuntimeCleanup(runtime: ApiRuntime = getApiRuntime()): RuntimeCleanup {
  const apiBaseUrl = runtime.apiBaseUrl;
  const token = stateOf(runtime).accessToken;
  const cleanupRequest = async <T>(path: string, options: RequestOptions = {}): Promise<T | undefined> => {
    if (token === null) { return undefined; }
    const controller = new AbortController();
    const timeoutMs = Math.min(TIMEOUT_CLEANUP_MS, Math.max(0, options.timeoutMs ?? TIMEOUT_CLEANUP_MS));
    let finish!: () => void;
    const aborted = new Promise<undefined>(resolve => { finish = () => resolve(undefined); });
    controller.signal.addEventListener('abort', finish, { once: true });
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
      return await Promise.race([
        (async () => {
          const response = await fetch(`${apiBaseUrl}/${path}`, jsonInit(options, token, controller.signal));
          return await decodeJson<T>(response);
        })(),
        aborted,
      ]);
    } catch { return undefined; }
    finally {
      clearTimeout(timeout);
      controller.signal.removeEventListener('abort', finish);
    }
  };
  return Object.freeze({
    request: cleanupRequest,
    revokeSession: async () => { await cleanupRequest<void>('api/v1/auth/logout', { method: 'POST' }); },
  });
}

export async function revokeSession(runtime: ApiRuntime = getApiRuntime()): Promise<void> {
  await captureRuntimeCleanup(runtime).revokeSession();
}

/** Test-only boundary; runtime IDs are never recycled, even between tests. */
export function __resetClientForTests(): void {
  if (activeRuntime !== null) { retireApiRuntime(activeRuntime); }
  activeRuntime = null;
}
