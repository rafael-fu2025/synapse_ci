import React from 'react';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import {
  __resetClientForTests,
  getAccessToken,
  request,
  SessionChangedError,
} from '../src/api/client';
import { LoginResponse } from '../src/api/types';
import { clearRefreshToken, getRefreshToken, setRefreshToken } from '../src/lib/keychain';
import { queryClient } from '../src/lib/queryClient';
import { AuthProvider, useAuth } from '../src/store/auth';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({
  getRefreshToken: jest.fn(),
  setRefreshToken: jest.fn(),
  clearRefreshToken: jest.fn(),
}));
jest.mock('../src/lib/queryClient', () => ({
  queryClient: { clear: jest.fn(), setQueryData: jest.fn() },
}));

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => { resolve = yes; });
  return { promise, resolve };
}

// Attach a rejection handler before advancing a race's deferred responses.
function captureRejection(pending: Promise<unknown>): Promise<unknown> {
  return pending.then(() => undefined, (error: unknown) => error);
}

function json(status: number, body: unknown = { error: { code: 'unauthenticated', message: 'Sign in again' } }) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function credentials(id = 1): LoginResponse {
  return {
    user: {
      id, person_id: id, tenant_id: 1, username: `user-${id}`, email: null,
      name: `User ${id}`, avatar_initials: 'U', identifier: `user-${id}`, role: 'student',
      is_teaching: false, permissions: [],
    },
    token: {
      token_type: 'Bearer', access_token: `access-${id}`, refresh_token: `refresh-${id}`,
      access_expires_at: '2026-12-01T00:00:00Z',
    },
  };
}

let auth: ReturnType<typeof useAuth>;
let renderer: ReactTestRenderer | undefined;
let fetchMock: jest.Mock<Promise<Response>, [RequestInfo | URL, RequestInit?]>;
const originalFetch = globalThis.fetch;

function Probe() {
  auth = useAuth();
  return null;
}

async function mount() {
  await act(async () => { renderer = create(<AuthProvider><Probe /></AuthProvider>); });
}

async function signIn(id = 1) {
  fetchMock.mockResolvedValueOnce(json(200, credentials(id)));
  await act(async () => { await auth.signIn(`user-${id}`, 'password'); });
  expect(auth.status).toBe('signedIn');
  expect(auth.user?.id).toBe(id);
  expect(getAccessToken()).toBe(`access-${id}`);
}

beforeEach(() => {
  __resetClientForTests();
  jest.resetAllMocks();
  jest.mocked(getRefreshToken).mockResolvedValue(null);
  jest.mocked(setRefreshToken).mockResolvedValue(undefined);
  jest.mocked(clearRefreshToken).mockResolvedValue(undefined);
  fetchMock = jest.fn();
  globalThis.fetch = fetchMock as typeof fetch;
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  globalThis.fetch = originalFetch;
});

it('clears cached private data and leaves signedIn immediately, before a stalled revoke completes', async () => {
  await mount();
  await signIn();
  jest.mocked(queryClient.clear).mockClear();
  const revoke = deferred<Response>();
  fetchMock.mockReturnValueOnce(revoke.promise);
  let pending!: Promise<void>;
  act(() => { pending = auth.signOut(); });

  expect(auth.status).toBe('signedOut');
  expect(auth.user).toBeNull();
  expect(getAccessToken()).toBeNull();
  expect(queryClient.clear).toHaveBeenCalledTimes(1);
  expect(clearRefreshToken).toHaveBeenCalledTimes(1);
  expect(new Headers(fetchMock.mock.calls[1][1]?.headers).get('Authorization')).toBe('Bearer access-1');
  revoke.resolve(json(401));
  await act(async () => { await pending; });
  expect(auth.status).toBe('signedOut');
  expect(fetchMock).toHaveBeenCalledTimes(2);
  expect(setRefreshToken).toHaveBeenCalledTimes(1);
});

it('does not remain signedIn if secure deletion rejects during sign-out', async () => {
  await mount();
  await signIn();
  jest.mocked(queryClient.clear).mockClear();
  const error = new Error('secure storage unavailable');
  jest.mocked(clearRefreshToken).mockRejectedValueOnce(error);
  fetchMock.mockRejectedValueOnce(new Error('offline'));
  await act(async () => { await expect(auth.signOut()).rejects.toBe(error); });

  expect(auth.status).toBe('signedOut');
  expect(auth.user).toBeNull();
  expect(getAccessToken()).toBeNull();
  expect(queryClient.clear).toHaveBeenCalledTimes(1);
});

it('clears the cache and expires on a valid refresh 401 even if credential deletion fails', async () => {
  await mount();
  await signIn();
  jest.mocked(queryClient.clear).mockClear();
  jest.mocked(getRefreshToken).mockResolvedValue('refresh-1');
  jest.mocked(clearRefreshToken).mockRejectedValueOnce(new Error('native deletion failed'));
  fetchMock.mockResolvedValueOnce(json(401)).mockResolvedValueOnce(json(401));
  await act(async () => {
    await expect(request('api/v1/private')).rejects.toMatchObject({ status: 401 });
  });

  expect(auth.status).toBe('expired');
  expect(auth.user).toBeNull();
  expect(getAccessToken()).toBeNull();
  expect(queryClient.clear).toHaveBeenCalledTimes(1);
  expect(clearRefreshToken).toHaveBeenCalledTimes(1);
  act(() => auth.acknowledgeExpired());
  expect(auth.status).toBe('signedOut');
});

it.each(['network', '503'])('keeps a signed-in session and its cached data during refresh %s', async failure => {
  await mount();
  await signIn();
  jest.mocked(queryClient.clear).mockClear();
  jest.mocked(getRefreshToken).mockResolvedValue('refresh-1');
  fetchMock.mockResolvedValueOnce(json(401));
  if (failure === 'network') {
    fetchMock.mockRejectedValueOnce(new Error('offline'));
  } else {
    fetchMock.mockResolvedValueOnce(json(503));
  }
  await act(async () => { await expect(request('api/v1/private')).rejects.toBeInstanceOf(Error); });

  expect(auth.status).toBe('signedIn');
  expect(auth.user?.id).toBe(1);
  expect(getAccessToken()).toBe('access-1');
  expect(queryClient.clear).not.toHaveBeenCalled();
  expect(clearRefreshToken).not.toHaveBeenCalled();
  expect(setRefreshToken).toHaveBeenCalledTimes(1);
});

it('does not enter signedIn or cache a user when saving sign-in credentials fails', async () => {
  await mount();
  const error = new Error('secure session storage unavailable');
  jest.mocked(setRefreshToken).mockRejectedValueOnce(error);
  fetchMock.mockResolvedValueOnce(json(200, credentials()));
  await act(async () => { await expect(auth.signIn('user-1', 'password')).rejects.toBe(error); });

  expect(auth.status).toBe('signedOut');
  expect(auth.user).toBeNull();
  expect(getAccessToken()).toBeNull();
  expect(queryClient.clear).not.toHaveBeenCalled();
  expect(queryClient.setQueryData).not.toHaveBeenCalled();
});

it('does not let a late login undo sign-out', async () => {
  await mount();
  const login = deferred<Response>();
  fetchMock.mockReturnValueOnce(login.promise);
  const pending = auth.signIn('old-user', 'password');
  const rejected = captureRejection(pending);
  await act(async () => { await auth.signOut(); });
  login.resolve(json(200, credentials()));
  await act(async () => { await expect(rejected).resolves.toBeInstanceOf(SessionChangedError); });

  expect(auth.status).toBe('signedOut');
  expect(auth.user).toBeNull();
  expect(getAccessToken()).toBeNull();
  expect(setRefreshToken).not.toHaveBeenCalled();
});

it('does not let an older login overwrite a newer successful sign-in', async () => {
  await mount();
  const login = deferred<Response>();
  fetchMock.mockReturnValueOnce(login.promise);
  const pending = auth.signIn('old-user', 'password');
  const rejected = captureRejection(pending);
  await signIn(2);
  login.resolve(json(200, credentials(1)));
  await act(async () => { await expect(rejected).resolves.toBeInstanceOf(SessionChangedError); });

  expect(auth.status).toBe('signedIn');
  expect(auth.user?.id).toBe(2);
  expect(getAccessToken()).toBe('access-2');
  expect(setRefreshToken).toHaveBeenCalledTimes(1);
  expect(setRefreshToken).toHaveBeenCalledWith('refresh-2');
});

it('does not restore a login whose secure save was still pending when sign-out began', async () => {
  await mount();
  const saved = deferred<void>();
  const saving = deferred<void>();
  jest.mocked(setRefreshToken).mockImplementationOnce(() => { saving.resolve(); return saved.promise; });
  fetchMock.mockResolvedValueOnce(json(200, credentials()));
  const pending = auth.signIn('user-1', 'password');
  const rejected = captureRejection(pending);
  await saving.promise;
  await act(async () => { await auth.signOut(); });
  saved.resolve();
  await act(async () => { await expect(rejected).resolves.toBeInstanceOf(SessionChangedError); });

  expect(auth.status).toBe('signedOut');
  expect(auth.user).toBeNull();
  expect(getAccessToken()).toBeNull();
  expect(clearRefreshToken).toHaveBeenCalledTimes(1);
});

it('keeps the stored refresh credential on a transient cold-start outage', async () => {
  jest.mocked(getRefreshToken).mockResolvedValue('stored-refresh');
  fetchMock.mockRejectedValueOnce(new Error('offline'));
  await mount();

  expect(auth.status).toBe('signedOut');
  expect(auth.user).toBeNull();
  expect(getAccessToken()).toBeNull();
  expect(clearRefreshToken).not.toHaveBeenCalled();
  expect(setRefreshToken).not.toHaveBeenCalled();
});

it('leaves restoring when the restored profile request and its refresh both return 401', async () => {
  jest.mocked(getRefreshToken).mockResolvedValue('stored-refresh');
  fetchMock
    .mockResolvedValueOnce(json(200, { token: credentials().token }))
    .mockResolvedValueOnce(json(401))
    .mockResolvedValueOnce(json(401));
  await mount();

  expect(fetchMock.mock.calls.map(([url]) => String(url))).toEqual([
    'http://api.test/api/v1/auth/refresh',
    'http://api.test/api/v1/me',
    'http://api.test/api/v1/auth/refresh',
  ]);
  expect(getAccessToken()).toBeNull();
  expect(auth.user).toBeNull();
  expect(queryClient.clear).toHaveBeenCalledTimes(1);
  expect(clearRefreshToken).toHaveBeenCalledTimes(1);
  expect(['signedOut', 'expired']).toContain(auth.status);
});
