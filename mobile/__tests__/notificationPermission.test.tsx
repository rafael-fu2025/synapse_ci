import React from 'react';
import { AppState, Platform } from 'react-native';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { NotificationPermissionLifecycle } from '../src/components/NotificationPermissionLifecycle';
import {
  enableNotificationPermission, getNotificationPermission, initializeNotificationPermission,
  refreshNotificationPermission, subscribeNotificationPermission, __resetNotificationPermissionForTests,
} from '../src/lib/push/permission';
import type { PushPermission } from '../src/lib/push/fcm';

const mockCheck = jest.fn();
const mockRequest = jest.fn();
const mockStorageGet = jest.fn();
const mockStorageSet = jest.fn();
const mockSystem = jest.fn();
let stored: string | null = null;
let tree: ReactTestRenderer | undefined;
type AppListener = Parameters<typeof AppState.addEventListener>[1];
const listeners = new Set<AppListener>();

jest.mock('react-native/Libraries/AppState/AppState', () => ({ __esModule: true, default: { currentState: 'active', addEventListener: jest.fn() } }));
jest.mock('../src/lib/push/fcm', () => ({
  checkPushPermission: () => mockCheck(),
  requestPushPermission: (...args: unknown[]) => mockRequest(...args),
}));
jest.mock('../src/lib/push/systemNotifications', () => ({ checkSystemNotifications: () => mockSystem() }));
jest.mock('@react-native-async-storage/async-storage', () => ({
  __esModule: true, default: { getItem: (...args: unknown[]) => mockStorageGet(...args), setItem: (...args: unknown[]) => mockStorageSet(...args) },
}));

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => { resolve = yes; });
  return { promise, resolve };
}
async function flush() { for (let i = 0; i < 20; i += 1) { await Promise.resolve(); } }
async function state(next: 'active' | 'inactive' | 'background') {
  await act(async () => {
    jest.replaceProperty(AppState, 'currentState', next);
    for (const callback of listeners) { callback(next); }
    await flush();
  });
}

beforeEach(() => {
  __resetNotificationPermissionForTests();
  jest.resetAllMocks();
  stored = null;
  listeners.clear();
  jest.replaceProperty(Platform, 'OS', 'android');
  jest.spyOn(Platform, 'Version', 'get').mockReturnValue(33);
  jest.replaceProperty(AppState, 'currentState', 'active');
  jest.mocked(AppState.addEventListener).mockImplementation((_event, callback) => {
    listeners.add(callback);
    return { remove: () => { listeners.delete(callback); } };
  });
  mockCheck.mockResolvedValue('denied');
  mockRequest.mockImplementation(async (active: () => boolean, onRequest?: () => void): Promise<PushPermission> => {
    if (!active()) { return 'unavailable'; }
    onRequest?.();
    return 'granted';
  });
  mockSystem.mockResolvedValue(true);
  mockStorageGet.mockImplementation(async () => stored);
  mockStorageSet.mockImplementation(async (_key: string, value: string) => { stored = value; });
});
afterEach(async () => {
  await act(async () => { tree?.unmount(); });
  tree = undefined;
  jest.restoreAllMocks();
});

it('requests on the first active Android 13 screen without authentication, token or backend setup', async () => {
  await act(async () => { tree = create(<NotificationPermissionLifecycle />); await flush(); });
  expect(mockRequest).toHaveBeenCalledTimes(1);
  expect(mockStorageSet).toHaveBeenCalledWith('synapse.notifications.prompted.v1', '1');
  expect(getNotificationPermission()).toEqual({ permission: 'granted', requesting: false, systemEnabled: true });
});

it.each([24, 26, 32])('does not request an Android 13 runtime permission on API %s', async version => {
  jest.spyOn(Platform, 'Version', 'get').mockReturnValue(version);
  await initializeNotificationPermission(() => true);
  expect(mockRequest).not.toHaveBeenCalled();
  expect(mockStorageSet).not.toHaveBeenCalled();
});

it('does not change iOS first-launch permission timing', async () => {
  jest.replaceProperty(Platform, 'OS', 'ios');
  await act(async () => { tree = create(<NotificationPermissionLifecycle />); });
  expect(mockCheck).not.toHaveBeenCalled();
  expect(mockRequest).not.toHaveBeenCalled();
});

it.each(['granted', 'blocked', 'unavailable'] as const)('does not prompt automatically when permission is %s', async permission => {
  mockCheck.mockResolvedValue(permission);
  await initializeNotificationPermission(() => true);
  expect(mockRequest).not.toHaveBeenCalled();
});

it('coalesces first-screen and registration checks into one native permission request', async () => {
  const check = deferred<PushPermission>();
  mockCheck.mockReturnValueOnce(check.promise);
  const first = initializeNotificationPermission(() => true);
  const second = initializeNotificationPermission(() => true);
  const registration = refreshNotificationPermission();
  check.resolve('denied');
  await expect(Promise.all([first, second, registration])).resolves.toEqual(['granted', 'granted', 'granted']);
  expect(mockCheck).toHaveBeenCalledTimes(1);
  expect(mockRequest).toHaveBeenCalledTimes(1);
});

it('does not lose first-launch prompting when a check-only registration starts first', async () => {
  const check = deferred<PushPermission>();
  mockCheck.mockReturnValueOnce(check.promise);
  const registration = refreshNotificationPermission();
  const first = initializeNotificationPermission(() => true);
  check.resolve('denied');
  await registration;
  await expect(first).resolves.toBe('granted');
  expect(mockRequest).toHaveBeenCalledTimes(1);
});

it('does not automatically ask again after denial, including after a cold restart', async () => {
  mockRequest.mockImplementation(async (_active: () => boolean, onRequest?: () => void) => { onRequest?.(); return 'denied'; });
  await initializeNotificationPermission(() => true);
  await initializeNotificationPermission(() => true);
  __resetNotificationPermissionForTests();
  await initializeNotificationPermission(() => true);
  expect(mockRequest).toHaveBeenCalledTimes(1);
  expect(getNotificationPermission().permission).toBe('denied');
});

it('persists the prompt attempt while the native dialog is still pending', async () => {
  const dialog = deferred<PushPermission>();
  mockRequest.mockImplementation((_active: () => boolean, onRequest?: () => void) => { onRequest?.(); return dialog.promise; });
  const first = initializeNotificationPermission(() => true);
  await flush();
  expect(stored).toBe('1');
  expect(getNotificationPermission().requesting).toBe(true);
  dialog.resolve('denied');
  await first;
});

it('waits for an active screen and refreshes permission after settings without asking again', async () => {
  jest.replaceProperty(AppState, 'currentState', 'background');
  await act(async () => { tree = create(<NotificationPermissionLifecycle />); });
  expect(mockCheck).not.toHaveBeenCalled();
  await state('active');
  expect(mockRequest).toHaveBeenCalledTimes(1);
  await state('background');
  mockCheck.mockResolvedValue('granted');
  await state('active');
  expect(mockRequest).toHaveBeenCalledTimes(1);
  expect(getNotificationPermission().permission).toBe('granted');
});

it('does not consume first-launch permission when a delayed check finishes after backgrounding', async () => {
  const check = deferred<PushPermission>();
  mockCheck.mockReturnValueOnce(check.promise);
  await act(async () => { tree = create(<NotificationPermissionLifecycle />); });
  await state('background');
  await act(async () => { check.resolve('denied'); await flush(); });
  expect(mockRequest).not.toHaveBeenCalled();
  expect(mockStorageSet).not.toHaveBeenCalled();
  await state('active');
  expect(mockRequest).toHaveBeenCalledTimes(1);
});

it('allows a new mounted screen to finish an older shared check but not an unmounted screen', async () => {
  const check = deferred<PushPermission>();
  mockCheck.mockReturnValueOnce(check.promise);
  let oldActive = true;
  const first = initializeNotificationPermission(() => oldActive);
  oldActive = false;
  const second = initializeNotificationPermission(() => true);
  check.resolve('denied');
  await Promise.all([first, second]);
  expect(mockRequest).toHaveBeenCalledTimes(1);

  __resetNotificationPermissionForTests();
  mockRequest.mockClear();
  await initializeNotificationPermission(() => false);
  expect(mockRequest).not.toHaveBeenCalled();
});

it('permits one explicit enable request after denial, while concurrent clicks share the request', async () => {
  stored = '1';
  await initializeNotificationPermission(() => true);
  expect(mockRequest).not.toHaveBeenCalled();
  const one = enableNotificationPermission(() => true);
  const two = enableNotificationPermission(() => true);
  await expect(Promise.all([one, two])).resolves.toEqual(['granted', 'granted']);
  expect(mockRequest).toHaveBeenCalledTimes(1);
});

it('does not lose an explicit enable action to a pending check-only refresh', async () => {
  const check = deferred<PushPermission>();
  mockCheck.mockReturnValueOnce(check.promise);
  const refresh = refreshNotificationPermission();
  const enable = enableNotificationPermission(() => true);
  check.resolve('denied');
  await refresh;
  await expect(enable).resolves.toBe('granted');
  expect(mockRequest).toHaveBeenCalledTimes(1);
});

it('reports disabled channels separately from permission and exposes immutable status changes', async () => {
  mockCheck.mockResolvedValue('granted');
  mockSystem.mockResolvedValue(false);
  const listener = jest.fn();
  const stop = subscribeNotificationPermission(listener);
  await refreshNotificationPermission();
  expect(getNotificationPermission()).toEqual({ permission: 'granted', requesting: false, systemEnabled: false });
  expect(Object.isFrozen(getNotificationPermission())).toBe(true);
  expect(listener).toHaveBeenCalledTimes(2);
  stop();
  await refreshNotificationPermission();
  expect(listener).toHaveBeenCalledTimes(2);
});

it('does not crash or repeat requests in process if preference storage is unavailable', async () => {
  mockStorageGet.mockRejectedValue(new Error('native storage unavailable'));
  mockStorageSet.mockRejectedValue(new Error('native storage unavailable'));
  mockRequest.mockImplementation(async (_active: () => boolean, onRequest?: () => void) => { onRequest?.(); return 'denied'; });
  await initializeNotificationPermission(() => true);
  await initializeNotificationPermission(() => true);
  expect(mockRequest).toHaveBeenCalledTimes(1);
});

it('removes the lifecycle listener on unmount and ignores its captured callback', async () => {
  await act(async () => { tree = create(<NotificationPermissionLifecycle />); await flush(); });
  const callbacks = [...listeners];
  await act(async () => { tree!.unmount(); tree = undefined; });
  expect(listeners.size).toBe(0);
  mockCheck.mockClear();
  callbacks.forEach(callback => callback('active'));
  await flush();
  expect(mockCheck).not.toHaveBeenCalled();
});
