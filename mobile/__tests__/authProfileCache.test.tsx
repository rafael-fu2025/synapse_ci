import React, { useEffect } from 'react';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { QueryClient } from '@tanstack/react-query';
import { __resetClientForTests, getAccessToken } from '../src/api/client';
import type { LoginResponse, MeResponse } from '../src/api/types';
import { clearRefreshToken, getRefreshToken, setRefreshToken } from '../src/lib/keychain';
import { queryClient } from '../src/lib/queryClient';
import { AuthProvider, useAuth } from '../src/store/auth';

jest.mock('react-native-config', () => ({ API_BASE_URL: 'http://api.test' }));
jest.mock('../src/lib/keychain', () => ({
  getRefreshToken: jest.fn(),
  setRefreshToken: jest.fn(),
  clearRefreshToken: jest.fn(),
}));
jest.mock('../src/lib/push/session', () => ({ usePushSession: jest.fn() }));
jest.mock('../src/lib/push/registration', () => ({
  revokeDevice: jest.fn(() => Promise.resolve()),
}));
jest.mock('../src/lib/queryClient', () => {
  const { QueryClient: ActualQueryClient } = jest.requireActual<typeof import('@tanstack/react-query')>('@tanstack/react-query');
  return {
    queryClient: new ActualQueryClient({
      defaultOptions: {
        queries: { retry: false, gcTime: Infinity },
        mutations: { retry: false },
      },
    }),
  };
});

function profile(id = 1, tenantId = 7): MeResponse {
  return {
    user: {
      id, person_id: id, tenant_id: tenantId, username: `user-${id}`, email: null,
      name: `User ${id}`, avatar_initials: 'U', identifier: `user-${id}`, role: 'staff',
      is_teaching: false, permissions: ['team.read'],
    },
    entitlements: {
      portal: true,
      staff_queue_units: [],
      personal: { enabled: true },
      work: {
        enabled: true,
        units: [{
          unit: 'clinic', label: 'Clinic', can_manage: true,
          features: { team_board: true, my_tasks: true, team_chat: true, team_calendar: true },
        }],
      },
    },
    photo_url: '/api/v1/me/photo',
  };
}

function credentials(next: MeResponse): LoginResponse {
  return {
    user: next.user,
    token: {
      token_type: 'Bearer',
      access_token: `access-${next.user.id}`,
      refresh_token: `refresh-${next.user.id}`,
      access_expires_at: '2026-12-01T00:00:00Z',
    },
  };
}

function json(body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => { resolve = yes; });
  return { promise, resolve };
}

let auth: ReturnType<typeof useAuth>;
let renderer: ReactTestRenderer | undefined;
let fetchMock: jest.Mock<Promise<Response>, [RequestInfo | URL, RequestInit?]>;
const originalFetch = globalThis.fetch;
const signedInMount = jest.fn();
const signedInUnmount = jest.fn();
const snapshots: Array<Pick<ReturnType<typeof useAuth>, 'status' | 'profile'>> = [];

function SignedInTree() {
  useEffect(() => {
    signedInMount();
    return () => { signedInUnmount(); };
  }, []);
  return null;
}

function AuthProbe() {
  auth = useAuth();
  snapshots.push({ status: auth.status, profile: auth.profile });
  return auth.status === 'signedIn' ? <SignedInTree /> : null;
}

async function mount() {
  await act(async () => {
    renderer = create(<AuthProvider><AuthProbe /></AuthProvider>);
  });
  expect(queryClient).toBeInstanceOf(QueryClient);
  expect(auth.status).toBe('signedOut');
  expect(auth.profile).toBeNull();
}

async function signIn(next = profile()) {
  fetchMock
    .mockResolvedValueOnce(json(credentials(next)))
    .mockResolvedValueOnce(json(next));
  await act(async () => { await auth.signIn(next.user.identifier, 'password'); });
  expect(auth.status).toBe('signedIn');
  expect(auth.profile).toEqual(next);
  expect(getAccessToken()).toBe(`access-${next.user.id}`);
}

function expectRetainedSignedInTree() {
  expect(auth.status).toBe('signedIn');
  expect(signedInMount).toHaveBeenCalledTimes(1);
  expect(signedInUnmount).not.toHaveBeenCalled();
}

beforeEach(() => {
  jest.clearAllMocks();
  queryClient.clear();
  __resetClientForTests();
  snapshots.length = 0;
  jest.mocked(getRefreshToken).mockResolvedValue(null);
  jest.mocked(setRefreshToken).mockResolvedValue(undefined);
  jest.mocked(clearRefreshToken).mockResolvedValue(undefined);
  fetchMock = jest.fn<Promise<Response>, [RequestInfo | URL, RequestInit?]>(async () => {
    throw new Error('Unexpected network request');
  });
  globalThis.fetch = fetchMock as typeof fetch;
});

afterEach(() => {
  act(() => { renderer?.unmount(); });
  renderer = undefined;
  queryClient.clear();
  __resetClientForTests();
  globalThis.fetch = originalFetch;
});

it('reconciles real exact [me] cache updates without remounting the signed-in tree', async () => {
  await mount();
  await signIn();
  const start = snapshots.length;
  const next = profile();
  next.user.name = 'Updated name';
  next.user.permissions = ['team.read', 'team.calendar'];
  next.photo_url = '/api/v1/me/photo?version=2';
  next.entitlements.personal.enabled = false;
  next.entitlements.work.units[0].can_manage = false;
  next.entitlements.work.units[0].features.my_tasks = false;

  act(() => { queryClient.setQueryData(['me'], next); });

  expect(auth.profile).toEqual(next);
  expect(auth.profile).toBe(queryClient.getQueryData(['me']));
  expect(auth.user).toBe(auth.profile?.user);
  expectRetainedSignedInTree();

  // Reconciliation re-subscribes after a profile change; later valid events still work.
  const restored = profile();
  act(() => { queryClient.setQueryData(['me'], restored); });
  expect(auth.profile).toEqual(restored);
  expectRetainedSignedInTree();
  expect(snapshots.slice(start).every(snapshot => snapshot.status === 'signedIn')).toBe(true);
  expect(fetchMock).toHaveBeenCalledTimes(2);
});

it.each([
  { label: 'another user', id: 2, tenantId: 7 },
  { label: 'another tenant with the same user id', id: 1, tenantId: 8 },
])('ignores a real [me] update for $label', async ({ id, tenantId }) => {
  await mount();
  await signIn();
  const previous = auth.profile;
  const foreign = profile(id, tenantId);
  foreign.entitlements.personal.enabled = false;

  act(() => { queryClient.setQueryData(['me'], foreign); });

  expect(queryClient.getQueryData(['me'])).toEqual(foreign);
  expect(auth.profile).toBe(previous);
  expectRetainedSignedInTree();

  const accepted = profile();
  accepted.entitlements.personal.enabled = false;
  act(() => { queryClient.setQueryData(['me'], accepted); });
  expect(auth.profile).toEqual(accepted);
  expectRetainedSignedInTree();
  expect(fetchMock).toHaveBeenCalledTimes(2);
});

it('ignores [me, photo] even when its data has the current identity and changed entitlements', async () => {
  await mount();
  await signIn();
  const previous = auth.profile;
  const photoData = profile();
  photoData.entitlements.personal.enabled = false;

  act(() => { queryClient.setQueryData(['me', 'photo'], photoData); });

  expect(queryClient.getQueryData(['me', 'photo'])).toEqual(photoData);
  expect(auth.profile).toBe(previous);
  expect(queryClient.getQueryData(['me'])).toBe(previous);
  expectRetainedSignedInTree();
  expect(fetchMock).toHaveBeenCalledTimes(2);
});

it('does not restore cached profile data while logout is pending or after it finishes', async () => {
  await mount();
  await signIn();
  const logout = deferred<Response>();
  fetchMock.mockReturnValueOnce(logout.promise);
  let pending!: Promise<void>;

  act(() => { pending = auth.signOut(); });

  expect(auth.status).toBe('signedOut');
  expect(auth.profile).toBeNull();
  expect(auth.user).toBeNull();
  expect(queryClient.getQueryData(['me'])).toBeUndefined();
  expect(getAccessToken()).toBeNull();
  expect(signedInUnmount).toHaveBeenCalledTimes(1);

  const late = profile();
  act(() => { queryClient.setQueryData(['me'], late); });
  expect(queryClient.getQueryData(['me'])).toEqual(late);
  expect(auth.status).toBe('signedOut');
  expect(auth.profile).toBeNull();

  logout.resolve(json({}));
  await act(async () => { await pending; });
  act(() => { queryClient.setQueryData(['me'], profile()); });
  expect(auth.status).toBe('signedOut');
  expect(auth.profile).toBeNull();
  expect(signedInMount).toHaveBeenCalledTimes(1);
});

it('ignores a real cache event in the same turn that sign-out withdraws the session', async () => {
  await mount();
  await signIn();
  fetchMock.mockResolvedValueOnce(json({}));

  await act(async () => {
    const pending = auth.signOut();
    queryClient.setQueryData(['me'], profile());
    await pending;
  });

  expect(auth.status).toBe('signedOut');
  expect(auth.profile).toBeNull();
  expect(auth.user).toBeNull();
  expect(getAccessToken()).toBeNull();
  expect(signedInMount).toHaveBeenCalledTimes(1);
  expect(signedInUnmount).toHaveBeenCalledTimes(1);
});

it('unsubscribes on unmount and does not bootstrap a new provider from a late cached profile', async () => {
  await mount();
  await signIn();
  const cache = queryClient.getQueryCache();
  expect(cache.hasListeners()).toBe(true);

  act(() => { renderer!.unmount(); });
  renderer = undefined;
  expect(cache.hasListeners()).toBe(false);
  const count = snapshots.length;

  act(() => { queryClient.setQueryData(['me'], profile()); });

  expect(snapshots).toHaveLength(count);
  expect(signedInUnmount).toHaveBeenCalledTimes(1);
  __resetClientForTests();
  await mount();
  expect(queryClient.getQueryData(['me'])).toEqual(profile());
  expect(auth.status).toBe('signedOut');
  expect(auth.profile).toBeNull();
  expect(cache.hasListeners()).toBe(false);
  expect(signedInMount).toHaveBeenCalledTimes(1);
  expect(fetchMock).toHaveBeenCalledTimes(2);
});

it('clears an old cached identity on a subsequent sign-in and rejects its later cache events', async () => {
  await mount();
  await signIn();
  fetchMock.mockResolvedValueOnce(json({}));
  await act(async () => { await auth.signOut(); });
  act(() => { queryClient.setQueryData(['me'], profile()); });
  const start = snapshots.length;

  const next = profile(2);
  next.entitlements.personal.enabled = false;
  await signIn(next);

  expect(queryClient.getQueryData(['me'])).toEqual(next);
  expect(signedInMount).toHaveBeenCalledTimes(2);
  expect(signedInUnmount).toHaveBeenCalledTimes(1);
  expect(snapshots.slice(start).every(snapshot => snapshot.profile === null || snapshot.profile.user.id === 2)).toBe(true);

  act(() => { queryClient.setQueryData(['me'], profile()); });
  expect(auth.status).toBe('signedIn');
  expect(auth.profile).toEqual(next);
  expect(auth.user?.id).toBe(2);
  expect(getAccessToken()).toBe('access-2');
  expect(signedInMount).toHaveBeenCalledTimes(2);
  expect(signedInUnmount).toHaveBeenCalledTimes(1);
  expect(fetchMock).toHaveBeenCalledTimes(5);
});
