import {
  ApiError,
  NetworkError,
  SessionChangedError,
  __resetClientForTests,
  authorizedFetch,
  getAccessToken,
  refreshSession,
  request,
  revokeSession,
  activateApiRuntime,
  bindApiClient,
  captureApiSession,
  captureRuntimeCleanup,
  createApiRuntime,
  getApiRuntime,
  isApiSessionCurrent,
  retireApiRuntime,
  setAccessToken,
  setSessionExpiredHandler,
} from '../src/api/client';
import { login, loginCarouselRealtime } from '../src/api/endpoints/auth';
import { create as createAppointment } from '../src/api/endpoints/appointments';
import { config } from '../src/lib/config';
import { getRefreshToken, setRefreshToken } from '../src/lib/keychain';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test', lanTest: false } }));
jest.mock('../src/lib/keychain', () => ({
  getRefreshToken: jest.fn(),
  setRefreshToken: jest.fn(),
}));

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

// Attach a rejection handler before advancing a race's deferred responses.
function captureRejection(pending: Promise<unknown>): Promise<unknown> {
  return pending.then(() => undefined, (error: unknown) => error);
}

function json(status: number, body: unknown = { error: { code: 'unauthenticated', message: 'Sign in again' } }) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

// Synthetic per-test values are used only by mocked fetch/keychain calls.
let tokens: { oldAccess: string; oldRefresh: string; newAccess: string; newRefresh: string;
  rotatedAccess: string; rotatedRefresh: string; newRotatedAccess: string; newRotatedRefresh: string };
function fixtureToken() { return `fixture-${Math.random().toString(36).slice(2)}`; }
function rotated() {
  return json(200, { token: { access_token: tokens.rotatedAccess, refresh_token: tokens.rotatedRefresh } });
}

const surfaces = [
  { name: 'request', run: (path: string) => request(path) },
  { name: 'authorizedFetch', run: (path: string) => authorizedFetch(path, { method: 'POST', body: 'upload' }) },
];
const originalFetch = globalThis.fetch;
let fetchMock: jest.Mock<Promise<Response>, [RequestInfo | URL, RequestInit?]>;
let expired: jest.Mock;

function bearer(call: number) {
  return new Headers(fetchMock.mock.calls[call][1]?.headers).get('Authorization');
}

beforeEach(() => {
  tokens = { oldAccess: fixtureToken(), oldRefresh: fixtureToken(), newAccess: fixtureToken(), newRefresh: fixtureToken(),
    rotatedAccess: fixtureToken(), rotatedRefresh: fixtureToken(), newRotatedAccess: fixtureToken(), newRotatedRefresh: fixtureToken() };
  __resetClientForTests();
  jest.mocked(getRefreshToken).mockReset().mockResolvedValue(tokens.oldRefresh);
  jest.mocked(setRefreshToken).mockReset().mockResolvedValue(undefined);
  setAccessToken(tokens.oldAccess);
  expired = jest.fn();
  setSessionExpiredHandler(expired);
  fetchMock = jest.fn();
  globalThis.fetch = fetchMock as typeof fetch;
});

afterEach(() => {
  globalThis.fetch = originalFetch;
  jest.useRealTimers();
  jest.restoreAllMocks();
});

describe.each(surfaces)('$name session boundaries', ({ name, run }) => {
  it.each(['sign-out', 'account switch'] as const)('discards a successful old response after %s', async boundary => {
    const nextToken = boundary === 'account switch' ? tokens.newAccess : null;
    const response = deferred<Response>();
    fetchMock.mockReturnValueOnce(response.promise);
    const pending = run('api/v1/private');
    const rejected = captureRejection(pending);
    expect(bearer(0)).toBe(`Bearer ${tokens.oldAccess}`);

    setAccessToken(nextToken);
    response.resolve(json(200, { private: 'old user data' }));

    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    expect(getAccessToken()).toBe(nextToken);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(expired).not.toHaveBeenCalled();
  });

  it.each([401, 'network'] as const)('discards an old %s failure without refreshing or expiring the new user', async failure => {
    const response = deferred<Response>();
    fetchMock.mockReturnValueOnce(response.promise);
    const pending = run('api/v1/private');
    const rejected = captureRejection(pending);
    setAccessToken(tokens.newAccess);
    if (failure === 'network') {
      response.reject(new Error('offline'));
    } else {
      response.resolve(json(failure));
    }
    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(getRefreshToken).not.toHaveBeenCalled();
    expect(getAccessToken()).toBe(tokens.newAccess);
    expect(expired).not.toHaveBeenCalled();
  });

  it.each([
    ['sign-out', 200],
    ['account switch', 200],
    ['sign-out', 401],
    ['account switch', 401],
  ] as const)('ignores a late refresh across a session boundary (case %#)', async (boundary, status) => {
    const nextToken = boundary === 'account switch' ? tokens.newAccess : null;
    const refreshResponse = deferred<Response>();
    const refreshStarted = deferred<void>();
    fetchMock.mockResolvedValueOnce(json(401)).mockImplementationOnce(() => {
      refreshStarted.resolve();
      return refreshResponse.promise;
    });
    const pending = run('api/v1/private');
    const rejected = captureRejection(pending);
    await refreshStarted.promise;
    setAccessToken(nextToken);
    refreshResponse.resolve(status === 200 ? rotated() : json(401));

    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    expect(getAccessToken()).toBe(nextToken);
    expect(setRefreshToken).not.toHaveBeenCalled();
    expect(expired).not.toHaveBeenCalled();
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('does not retry or restore access if the session changes during refresh-token storage', async () => {
    const saved = deferred<void>();
    const saving = deferred<void>();
    jest.mocked(setRefreshToken).mockImplementationOnce(() => {
      saving.resolve();
      return saved.promise;
    });
    fetchMock.mockResolvedValueOnce(json(401)).mockResolvedValueOnce(rotated());
    const pending = run('api/v1/private');
    const rejected = captureRejection(pending);
    await saving.promise;
    setAccessToken(tokens.newAccess);
    saved.resolve();

    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    expect(getAccessToken()).toBe(tokens.newAccess);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(expired).not.toHaveBeenCalled();
  });

  it('reuses a completed refresh for a delayed 401 instead of rotating twice', async () => {
    const delayed = deferred<Response>();
    fetchMock
      .mockReturnValueOnce(delayed.promise)
      .mockResolvedValueOnce(json(401))
      .mockResolvedValueOnce(rotated())
      .mockResolvedValueOnce(json(200, { data: 'first retry' }))
      .mockResolvedValueOnce(json(200, { data: 'delayed retry' }));
    const old = run('api/v1/delayed');
    await run('api/v1/fast');
    expect(getAccessToken()).toBe(tokens.rotatedAccess);
    delayed.resolve(json(401));
    await old;

    expect(fetchMock.mock.calls.map(([url]) => String(url))).toEqual([
      'http://api.test/api/v1/delayed',
      'http://api.test/api/v1/fast',
      'http://api.test/api/v1/auth/refresh',
      'http://api.test/api/v1/fast',
      'http://api.test/api/v1/delayed',
    ]);
    expect(bearer(0)).toBe(`Bearer ${tokens.oldAccess}`);
    expect(bearer(1)).toBe(`Bearer ${tokens.oldAccess}`);
    expect(bearer(2)).toBeNull();
    expect(bearer(3)).toBe(`Bearer ${tokens.rotatedAccess}`);
    expect(bearer(4)).toBe(`Bearer ${tokens.rotatedAccess}`);
    expect(getRefreshToken).toHaveBeenCalledTimes(1);
    expect(setRefreshToken).toHaveBeenCalledTimes(1);
    expect(setRefreshToken).toHaveBeenCalledWith(tokens.rotatedRefresh);
    expect(expired).not.toHaveBeenCalled();
  });

  it.each(['network', '503'] as const)('preserves the session on refresh %s and permits a later refresh', async failure => {
    fetchMock.mockResolvedValueOnce(json(401));
    if (failure === 'network') {
      fetchMock.mockRejectedValueOnce(new Error('offline'));
    } else {
      fetchMock.mockResolvedValueOnce(json(503));
    }
    const pending = run('api/v1/private');
    if (failure === 'network') {
      await expect(pending).rejects.toBeInstanceOf(NetworkError);
    } else {
      await expect(pending).rejects.toMatchObject({ status: 503 });
    }
    expect(getAccessToken()).toBe(tokens.oldAccess);
    expect(setRefreshToken).not.toHaveBeenCalled();
    expect(expired).not.toHaveBeenCalled();
    expect(fetchMock).toHaveBeenCalledTimes(2);

    fetchMock.mockResolvedValueOnce(json(401)).mockResolvedValueOnce(rotated()).mockResolvedValueOnce(json(200, { ok: true }));
    await run('api/v1/private');
    expect(getAccessToken()).toBe(tokens.rotatedAccess);
    expect(getRefreshToken).toHaveBeenCalledTimes(2);
    expect(setRefreshToken).toHaveBeenCalledWith(tokens.rotatedRefresh);
    expect(expired).not.toHaveBeenCalled();
  });

  it.each(['refresh rejected', 'retry rejected'])('expires the current session on a valid 401: %s', async failure => {
    expired.mockImplementation(() => setAccessToken(null));
    fetchMock.mockResolvedValueOnce(json(401));
    if (failure === 'refresh rejected') {
      fetchMock.mockResolvedValueOnce(json(401));
    } else {
      fetchMock.mockResolvedValueOnce(rotated()).mockResolvedValueOnce(json(401));
    }
    if (name === 'request') {
      await expect(run('api/v1/private')).rejects.toBeInstanceOf(ApiError);
    } else {
      expect(await run('api/v1/private')).toMatchObject({ status: 401 });
    }
    expect(expired).toHaveBeenCalledTimes(1);
    expect(getAccessToken()).toBeNull();
    expect(fetchMock).toHaveBeenCalledTimes(failure === 'refresh rejected' ? 2 : 3);
  });

  it('discards a successful retry that completes after account switching', async () => {
    const retry = deferred<Response>();
    const retryStarted = deferred<void>();
    fetchMock.mockResolvedValueOnce(json(401)).mockResolvedValueOnce(rotated()).mockImplementationOnce(() => {
      retryStarted.resolve();
      return retry.promise;
    });
    const pending = run('api/v1/private');
    const rejected = captureRejection(pending);
    await retryStarted.promise;
    setAccessToken(tokens.newAccess);
    retry.resolve(json(200, { private: 'old user data' }));
    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    expect(getAccessToken()).toBe(tokens.newAccess);
    expect(expired).not.toHaveBeenCalled();
  });
});

it('checks the session after asynchronous JSON parsing, not only after fetch', async () => {
  const body = deferred<unknown>();
  const parsing = deferred<void>();
  const response = json(200, {});
  jest.spyOn(response, 'json').mockImplementation(() => {
    parsing.resolve();
    return body.promise;
  });
  fetchMock.mockResolvedValueOnce(response);
  const pending = request('api/v1/private');
  const rejected = captureRejection(pending);
  await parsing.promise;
  setAccessToken(null);
  body.resolve({ private: 'old user data' });
  await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
});

it('does not let an old refresh finalizer detach the new session\'s pending refresh', async () => {
  const oldResponse = deferred<Response>();
  const newResponse = deferred<Response>();
  const oldStarted = deferred<void>();
  const newStarted = deferred<void>();
  fetchMock.mockImplementationOnce(() => { oldStarted.resolve(); return oldResponse.promise; })
    .mockImplementationOnce(() => { newStarted.resolve(); return newResponse.promise; });
  const old = refreshSession();
  const rejected = captureRejection(old);
  await oldStarted.promise;
  setAccessToken(tokens.newAccess);
  jest.mocked(getRefreshToken).mockResolvedValue(tokens.newRefresh);
  const current = refreshSession();
  await newStarted.promise;
  oldResponse.resolve(rotated());
  await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
  expect(refreshSession()).toBe(current);
  newResponse.resolve(json(200, { token: { access_token: tokens.newRotatedAccess, refresh_token: tokens.newRotatedRefresh } }));
  await expect(current).resolves.toBe(true);
  expect(getAccessToken()).toBe(tokens.newRotatedAccess);
  expect(setRefreshToken).toHaveBeenCalledTimes(1);
  expect(setRefreshToken).toHaveBeenCalledWith(tokens.newRotatedRefresh);
  expect(fetchMock).toHaveBeenCalledTimes(2);
});

describe('sign-out revocation', () => {
  it.each([401, 503])('makes only one attempt on %s, without refresh or expiry', async status => {
    fetchMock.mockResolvedValueOnce(json(status));
    await expect(revokeSession()).resolves.toBeUndefined();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toBe('http://api.test/api/v1/auth/logout');
    expect(fetchMock.mock.calls[0][1]?.method).toBe('POST');
    expect(bearer(0)).toBe(`Bearer ${tokens.oldAccess}`);
    expect(getRefreshToken).not.toHaveBeenCalled();
    expect(setRefreshToken).not.toHaveBeenCalled();
    expect(expired).not.toHaveBeenCalled();
  });

  it('aborts one stalled revoke at five seconds and never refreshes', async () => {
    jest.useFakeTimers();
    let signal!: AbortSignal;
    fetchMock.mockImplementationOnce((_url, init) => new Promise((_resolve, reject) => {
      signal = init!.signal!;
      signal.addEventListener('abort', () => reject(new Error('aborted')), { once: true });
    }));
    const pending = revokeSession();
    setAccessToken(null);
    await jest.advanceTimersByTimeAsync(4999);
    expect(signal.aborted).toBe(false);
    await jest.advanceTimersByTimeAsync(1);
    await expect(pending).resolves.toBeUndefined();
    expect(signal.aborted).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(getRefreshToken).not.toHaveBeenCalled();
    expect(getAccessToken()).toBeNull();
    expect(jest.getTimerCount()).toBe(0);
  });

  it('skips revocation when signed out', async () => {
    setAccessToken(null);
    await revokeSession();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe('immutable environment runtimes', () => {
  const universityBase = 'http://172.16.153.237:8080';
  const homeBase = 'http://192.168.1.11:8080';
  function lan(profileId: 'university' | 'home') {
    const apiBaseUrl = profileId === 'university' ? universityBase : homeBase;
    return createApiRuntime({ apiBaseUrl, profileId, credentialBinding: JSON.stringify([profileId, apiBaseUrl, 'fixture-generation']), lanTest: true });
  }
  function select(profileId: 'university' | 'home', token = tokens.oldAccess) {
    const runtime = lan(profileId);
    activateApiRuntime(runtime);
    setAccessToken(token, runtime);
    return runtime;
  }

  it('creates without activation, freezes destination identity, and never reuses a retired runtime ID', async () => {
    const previous = getApiRuntime();
    const runtime = lan('university');
    expect(getApiRuntime()).toBe(previous);
    expect(runtime.active).toBe(false);
    expect(Object.isFrozen(runtime)).toBe(true);
    await expect(bindApiClient(runtime).request('api/v1/me')).rejects.toBeInstanceOf(SessionChangedError);
    expect(fetchMock).not.toHaveBeenCalled();
    activateApiRuntime(runtime);
    expect(previous.active).toBe(false);
    expect(runtime.active).toBe(true);
    retireApiRuntime(runtime);
    expect(() => activateApiRuntime(runtime)).toThrow(SessionChangedError);
    __resetClientForTests();
    expect(lan('home').id).toBeGreaterThan(runtime.id);
  });

  it('has no lazy startup or post-retirement LAN fallback', async () => {
    __resetClientForTests();
    jest.replaceProperty(config, 'lanTest', true);
    expect(() => getApiRuntime()).toThrow(SessionChangedError);
    await expect(request('api/v1/auth/carousel', { authenticated: false })).rejects.toBeInstanceOf(SessionChangedError);
    const runtime = select('university');
    expect(getApiRuntime()).toBe(runtime);
    retireApiRuntime(runtime);
    expect(() => getApiRuntime()).toThrow(SessionChangedError);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it.each([200, 401, 'network'] as const)('aborts anonymous login and discards old %s results after environment retirement', async status => {
    const runtime = select('university');
    const client = bindApiClient(runtime);
    const response = deferred<Response>();
    fetchMock.mockReturnValueOnce(response.promise);
    const rejected = captureRejection(login('fixture-identifier', 'fixture-password', client));
    expect(String(fetchMock.mock.calls[0][0])).toBe(`${universityBase}/api/v1/auth/login`);
    expect(bearer(0)).toBeNull();
    const signal = fetchMock.mock.calls[0][1]!.signal!;
    const next = select('home', tokens.newAccess);
    expect(signal.aborted).toBe(true);
    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    if (status === 'network') { response.reject(new Error('old offline transport')); }
    else { response.resolve(json(status, { data: 'old environment' })); }
    await Promise.resolve();
    expect(getAccessToken(next)).toBe(tokens.newAccess);
    expect(getRefreshToken).not.toHaveBeenCalled();
    expect(setRefreshToken).not.toHaveBeenCalled();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('invalidates captured anonymous work at a same-runtime account boundary too', async () => {
    const runtime = select('university');
    const response = deferred<Response>();
    fetchMock.mockReturnValueOnce(response.promise);
    const rejected = captureRejection(bindApiClient(runtime).request('api/v1/auth/carousel', { authenticated: false }));
    setAccessToken(tokens.newAccess, runtime);
    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    response.resolve(json(200, { data: 'old anonymous body' }));
    expect(getAccessToken(runtime)).toBe(tokens.newAccess);
  });

  it('binds grant Origin and path to the captured runtime, not later config', async () => {
    const runtime = select('university');
    const client = bindApiClient(runtime);
    jest.replaceProperty(config as { apiBaseUrl: string }, 'apiBaseUrl', homeBase);
    fetchMock.mockResolvedValueOnce(json(200, { data: { enabled: false } }));
    await loginCarouselRealtime(client);
    expect(fetchMock.mock.calls[0][0]).toBe(`${universityBase}/api/v1/auth/carousel/realtime`);
    expect(new Headers(fetchMock.mock.calls[0][1]?.headers).get('Origin')).toBe(universityBase);
    expect(bearer(0)).toBeNull();
  });

  it('keeps all retries, refresh credentials, and access rotation in the captured environment', async () => {
    const runtime = select('university');
    const client = bindApiClient(runtime);
    jest.replaceProperty(config as { apiBaseUrl: string }, 'apiBaseUrl', homeBase);
    fetchMock.mockResolvedValueOnce(json(401)).mockResolvedValueOnce(rotated()).mockResolvedValueOnce(json(200, { data: 'same environment retry' }));
    await client.request('api/v1/private');
    expect(fetchMock.mock.calls.map(([url]) => String(url))).toEqual([
      `${universityBase}/api/v1/private`, `${universityBase}/api/v1/auth/refresh`, `${universityBase}/api/v1/private`,
    ]);
    expect(getRefreshToken).toHaveBeenCalledWith(runtime.credentialBinding);
    expect(setRefreshToken).toHaveBeenCalledWith(tokens.rotatedRefresh, runtime.credentialBinding);
    expect(getAccessToken(runtime)).toBe(tokens.rotatedAccess);
    expect(client.isCurrent()).toBe(true);
  });

  it('promptly rejects a retired refresh while its keychain read is still pending', async () => {
    const runtime = select('university');
    const credential = deferred<string | null>();
    jest.mocked(getRefreshToken).mockReturnValueOnce(credential.promise);
    const rejected = captureRejection(refreshSession(runtime));
    const next = select('home', tokens.newAccess);
    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    credential.resolve(tokens.oldRefresh);
    await Promise.resolve();
    expect(getRefreshToken).toHaveBeenCalledWith(runtime.credentialBinding);
    expect(fetchMock).not.toHaveBeenCalled();
    expect(setRefreshToken).not.toHaveBeenCalled();
    expect(getAccessToken(next)).toBe(tokens.newAccess);
  });

  it.each(['JSON', '204', 'upload'] as const)('registers every %s request for runtime cancellation', async surface => {
    const runtime = select('university');
    const response = deferred<Response>();
    fetchMock.mockReturnValueOnce(response.promise);
    const client = bindApiClient(runtime);
    const pending = surface === 'upload' ? client.authorizedFetch('api/v1/me/photo', { method: 'POST', body: 'fixture upload' })
      : client.request('api/v1/private');
    const rejected = captureRejection(pending);
    const signal = fetchMock.mock.calls[0][1]!.signal!;
    retireApiRuntime(runtime);
    expect(signal.aborted).toBe(true);
    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    response.resolve(surface === '204' ? new Response(null, { status: 204 }) : json(200, { data: 'late' }));
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('guards delayed authorized body decoding through final success and failure', async () => {
    const runtime = select('university');
    const client = bindApiClient(runtime);
    const started = deferred<void>();
    const body = deferred<string>();
    const rejected = captureRejection(client.run(async () => { started.resolve(); return body.promise; }));
    await started.promise;
    const next = select('home', tokens.newAccess);
    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    body.reject(new Error('late decode failure'));
    await Promise.resolve();
    expect(getAccessToken(next)).toBe(tokens.newAccess);
  });

  it('never dispatches an endpoint captured before retirement or a same-account sign-in', async () => {
    const runtime = select('university');
    const client = bindApiClient(runtime);
    const session = captureApiSession(runtime);
    const input = { unit: 'clinic' as const, date: '2026-12-10', preferred_time: '09:00', type: 'routine' };
    const queued = () => createAppointment(input, client);
    expect(isApiSessionCurrent(session)).toBe(true);
    setAccessToken(null, runtime);
    setAccessToken(tokens.oldAccess, runtime);
    expect(isApiSessionCurrent(session)).toBe(false);
    await expect(queued()).rejects.toBeInstanceOf(SessionChangedError);
    const replacement = select('home', tokens.newAccess);
    await expect(client.authorizedFetch('api/v1/me/photo', { method: 'POST' })).rejects.toBeInstanceOf(SessionChangedError);
    expect(getAccessToken(replacement)).toBe(tokens.newAccess);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('uses only an old captured bearer/base for explicit logout and push cleanup after retirement', async () => {
    const runtime = select('university');
    const cleanup = captureRuntimeCleanup(runtime);
    const next = select('home', tokens.newAccess);
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(json(401));
    const body = { token: 'fixture-push', binding: 'fixture-installation' };
    await cleanup.request('api/v1/push/token', { method: 'DELETE', body });
    await cleanup.revokeSession();
    expect(fetchMock.mock.calls.map(([url]) => String(url))).toEqual([
      `${universityBase}/api/v1/push/token`, `${universityBase}/api/v1/auth/logout`,
    ]);
    expect(fetchMock.mock.calls[0][1]?.body).toBe(JSON.stringify(body));
    expect(bearer(0)).toBe(`Bearer ${tokens.oldAccess}`);
    expect(bearer(1)).toBe(`Bearer ${tokens.oldAccess}`);
    expect(getAccessToken(next)).toBe(tokens.newAccess);
    expect(getRefreshToken).not.toHaveBeenCalled();
    expect(setRefreshToken).not.toHaveBeenCalled();
  });

  it('bounds cleanup at five seconds even if a transport ignores abort and requests a longer timeout', async () => {
    jest.useFakeTimers();
    const runtime = select('university');
    const cleanup = captureRuntimeCleanup(runtime);
    retireApiRuntime(runtime);
    fetchMock.mockReturnValueOnce(new Promise(() => undefined));
    const pending = cleanup.request('api/v1/push/token', { method: 'DELETE', timeoutMs: 60_000 });
    const signal = fetchMock.mock.calls[0][1]!.signal!;
    await jest.advanceTimersByTimeAsync(4999);
    expect(signal.aborted).toBe(false);
    await jest.advanceTimersByTimeAsync(1);
    await expect(pending).resolves.toBeUndefined();
    expect(signal.aborted).toBe(true);
    expect(jest.getTimerCount()).toBe(0);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
});
