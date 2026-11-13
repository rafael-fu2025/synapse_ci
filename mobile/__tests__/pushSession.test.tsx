import React from 'react';
import { AppState, type AppStateStatus } from 'react-native';
import TestRenderer, { act } from 'react-test-renderer';
import { QueryClient, QueryClientProvider, onlineManager } from '@tanstack/react-query';
import { usePushSession, type PushSessionOptions, type PushSessionStatus } from '../src/lib/push/session';
import * as pushScope from '../src/lib/push/scope';
import {
  activatePushOwner, currentPushOwner, isPushOwnerCurrent, makePushScope, publishPushScope, retirePushOwner,
  __resetPushScopeForTests,
} from '../src/lib/push/scope';
import { queueNavigationIntent } from '../src/features/workspace/navigationIntent';
import { getPushSetupStatus, publishPushSetupStatus, usePushSetupStatus, __resetPushSetupStatusForTests, type PushSetupState } from '../src/lib/push/setupStatus';
import type { PushMessage, PushPermission } from '../src/lib/push/fcm';
import type { NotificationPermissionStatus } from '../src/lib/push/permission';
import type { ApiRuntime } from '../src/api/client';

const mockRegister = jest.fn();
const mockRevoke = jest.fn();
const mockPause = jest.fn();
const mockWatch = jest.fn();
const mockInitial = jest.fn();
const mockRefreshPermission = jest.fn();
const mockShowSystemNotification = jest.fn();
let mockMessages: Array<(message: PushMessage) => void> = [];
let mockOpened: Array<(message: PushMessage) => void> = [];
let mockPermissionStatus: NotificationPermissionStatus;
const mockPermissionListeners = new Set<() => void>();
let mockPermissionCallbacks: Array<() => void> = [];
let mockPermissionStops: jest.Mock[] = [];
let mockMessageStops: jest.Mock[] = [];
let mockOpenedStops: jest.Mock[] = [];
let mockTokenStops: jest.Mock[] = [];
const activityListeners = new Set<(state: AppStateStatus) => void>();
let activityCallbacks: Array<(state: AppStateStatus) => void> = [];
let activityStops: jest.Mock[] = [];
const onlineListeners = new Set<(online: boolean) => void>();
let onlineCallbacks: Array<(online: boolean) => void> = [];
let onlineStops: jest.Mock[] = [];
const scopeListeners = new Set<() => void>();
let scopeStops: jest.Mock[] = [];
let registrationAttempts: Array<{ resolve: () => void }> = [];
const originalAppState = AppState.currentState;
const originalOnline = onlineManager.isOnline();

jest.mock('react-native/Libraries/AppState/AppState', () => ({ __esModule: true, default: { currentState: 'active', addEventListener: jest.fn() } }));
jest.mock('../src/lib/config', () => ({ config: { lanTest: false } }));
jest.mock('react-native-keychain', () => ({
  getGenericPassword: jest.fn().mockResolvedValue(false), resetGenericPassword: jest.fn().mockResolvedValue(true),
}));
jest.mock('../src/lib/push/registration', () => ({
  registerDevice: (...args: unknown[]) => mockRegister(...args),
  revokeDevice: (...args: unknown[]) => mockRevoke(...args),
  pausePushSession: (...args: unknown[]) => mockPause(...args),
  watchPushToken: (...args: unknown[]) => mockWatch(...args),
}));
jest.mock('../src/lib/push/fcm', () => ({
  initialPushMessage: () => mockInitial(),
  onPushMessage: (handler: (message: PushMessage) => void) => {
    mockMessages.push(handler);
    const stop = jest.fn(); mockMessageStops.push(stop); return stop;
  },
  onPushOpened: (handler: (message: PushMessage) => void) => {
    mockOpened.push(handler);
    const stop = jest.fn(); mockOpenedStops.push(stop); return stop;
  },
}));
// Keep an observable permission store: publishing notifies real hook callbacks,
// independently of a refresh promise (including a grant before .then(retry)).
// Native prompting/storage has its own suite and is not executed by this hook.
jest.mock('../src/lib/push/permission', () => ({
  getNotificationPermission: () => mockPermissionStatus,
  refreshNotificationPermission: () => mockRefreshPermission(),
  subscribeNotificationPermission: (listener: () => void) => {
    mockPermissionListeners.add(listener);
    mockPermissionCallbacks.push(listener);
    const stop = jest.fn(() => { mockPermissionListeners.delete(listener); });
    mockPermissionStops.push(stop); return stop;
  },
}));
// No acceptance guard in the spy: session.ts itself must gate every native post.
jest.mock('../src/lib/push/systemNotifications', () => ({
  showSystemNotification: (...args: unknown[]) => mockShowSystemNotification(...args),
  clearSystemNotifications: jest.fn(),
}));
jest.mock('../src/features/workspace/navigationIntent', () => ({
  ...jest.requireActual('../src/features/workspace/navigationIntent'), queueNavigationIntent: jest.fn(),
}));

const mockQueue = queueNavigationIntent as jest.Mock;
const registrationId = '11111111-1111-4111-8111-111111111111';
const user = { tenant_id: 7, id: 31 };
const initialId = '22222222-2222-4222-8222-222222222222';
let tree: TestRenderer.ReactTestRenderer | null;

function runtime(overrides: Partial<ApiRuntime> = {}): ApiRuntime {
  return { id: 1, apiBaseUrl: 'http://172.16.153.237:8080', profileId: 'university', credentialBinding: 'activation-a',
    lanTest: true, active: true, sessionVersion: 1, ...overrides };
}
function message(registration = registrationId, type = 'appointment_confirmed'): PushMessage {
  return { data: { scope_version: '1', deployment_id: 'deployment-a', registration_id: registration, type, id: '31', unit: 'clinic' } };
}
function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => { resolve = yes; });
  return { promise, resolve };
}
function Probe({ status = 'signedIn', options }: { status?: PushSessionStatus; options: PushSessionOptions }) {
  usePushSession(status, options); return null;
}

function publishPermission(permission: PushPermission) {
  mockPermissionStatus = { permission, requesting: false, systemEnabled: permission === 'granted' };
  for (const listener of mockPermissionListeners) { listener(); }
}
function setupState(state: PushSetupState, runtimeId: number | null = 1) {
  publishPushSetupStatus({ state, runtimeId, code: state === 'warning' ? 'registration_failed' : null,
    warning: state === 'warning' ? 'Phone setup could not finish.' : null });
}
async function application(state: AppStateStatus) {
  await act(async () => {
    jest.replaceProperty(AppState, 'currentState', state);
    for (const listener of activityListeners) { listener(state); }
  });
}
async function network(online: boolean) {
  await act(async () => {
    onlineManager.setOnline(online);
    for (const listener of onlineListeners) { listener(online); }
  });
}

beforeEach(() => {
  __resetPushScopeForTests();
  __resetPushSetupStatusForTests();
  jest.resetAllMocks();
  mockMessages = []; mockOpened = []; mockMessageStops = []; mockOpenedStops = []; mockTokenStops = [];
  mockPermissionCallbacks = []; mockPermissionStops = []; mockPermissionListeners.clear();
  activityCallbacks = []; activityStops = []; activityListeners.clear();
  onlineCallbacks = []; onlineStops = []; onlineListeners.clear();
  scopeStops = []; scopeListeners.clear(); registrationAttempts = [];
  mockPermissionStatus = { permission: 'denied', requesting: false, systemEnabled: false };
  jest.replaceProperty(AppState, 'currentState', 'active');
  onlineManager.setOnline(true);
  jest.spyOn(AppState, 'addEventListener').mockImplementation((_event, listener) => {
    activityListeners.add(listener); activityCallbacks.push(listener);
    const remove = jest.fn(() => { activityListeners.delete(listener); });
    activityStops.push(remove); return { remove };
  });
  jest.spyOn(onlineManager, 'subscribe').mockImplementation(listener => {
    onlineListeners.add(listener); onlineCallbacks.push(listener);
    const stop = jest.fn(() => { onlineListeners.delete(listener); });
    onlineStops.push(stop); return stop;
  });
  const subscribeScope = pushScope.subscribePushScope;
  jest.spyOn(pushScope, 'subscribePushScope').mockImplementation(listener => {
    scopeListeners.add(listener);
    const unsubscribe = subscribeScope(listener);
    const stop = jest.fn(() => { scopeListeners.delete(listener); unsubscribe(); });
    scopeStops.push(stop); return stop;
  });
  tree = null;
  mockInitial.mockResolvedValue(null);
  mockWatch.mockImplementation(() => { const stop = jest.fn(); mockTokenStops.push(stop); return stop; });
  mockRefreshPermission.mockImplementation(async () => mockPermissionStatus.permission);
  mockRevoke.mockResolvedValue(undefined);
  mockRegister.mockImplementation((options: PushSessionOptions) => {
    const existing = currentPushOwner();
    if (!existing || !isPushOwnerCurrent(existing) || existing.runtimeId !== (options.runtime?.id ?? null)) { activatePushOwner(options); }
    setupState('checking', options.runtime?.id ?? null);
    const attempt = deferred<void>();
    registrationAttempts.push({ resolve: () => attempt.resolve() });
    return attempt.promise;
  });
});
afterEach(async () => {
  await act(async () => { tree?.unmount(); registrationAttempts.forEach(attempt => attempt.resolve()); });
  jest.restoreAllMocks();
  AppState.currentState = originalAppState;
  onlineManager.setOnline(originalOnline);
});

it('buffers the killed-app initial tap until the same-server saved scope is validated and published', async () => {
  const api = runtime();
  mockInitial.mockResolvedValue(message());
  const client = new QueryClient();
  await act(async () => { tree = TestRenderer.create(<Probe options={{ runtime: api, user, queryClient: client }} />); });
  expect(mockQueue).not.toHaveBeenCalled();
  const owner = currentPushOwner()!;
  await act(async () => { publishPushScope(owner, makePushScope(owner, 'deployment-a', registrationId)); });
  expect(mockQueue).toHaveBeenCalledTimes(1);
  expect(mockQueue).toHaveBeenCalledWith({ kind: 'appointment', id: 31 }, 1);
  await act(async () => { publishPushScope(owner, makePushScope(owner, 'deployment-a', registrationId)); });
  expect(mockQueue).toHaveBeenCalledTimes(1);
});

it.each(['empty', 'older'] as const)('keeps a newer opened tap when the delayed initial notification is %s', async initialKind => {
  const api = runtime();
  const initial = deferred<PushMessage | null>();
  mockInitial.mockReturnValueOnce(initial.promise);
  const client = new QueryClient();
  await act(async () => { tree = TestRenderer.create(<Probe options={{ runtime: api, user, queryClient: client }} />); });
  const owner = currentPushOwner()!;
  const opened = message();
  opened.data!.id = '32';
  await act(async () => { mockOpened[0](opened); });
  await act(async () => { initial.resolve(initialKind === 'empty' ? null : message()); });
  expect(mockQueue).not.toHaveBeenCalled();

  await act(async () => { publishPushScope(owner, makePushScope(owner, 'deployment-a', registrationId)); });

  expect(mockQueue).toHaveBeenCalledTimes(1);
  expect(mockQueue).toHaveBeenCalledWith({ kind: 'appointment', id: 32 }, 1);
});

it('does not route an older initial notification after a newer opened tap has already been delivered', async () => {
  const api = runtime();
  const initial = deferred<PushMessage>();
  mockInitial.mockReturnValueOnce(initial.promise);
  const client = new QueryClient();
  await act(async () => { tree = TestRenderer.create(<Probe options={{ runtime: api, user, queryClient: client }} />); });
  const owner = currentPushOwner()!;
  const opened = message();
  opened.data!.id = '32';
  await act(async () => {
    publishPushScope(owner, makePushScope(owner, 'deployment-a', registrationId));
    mockOpened[0](opened);
  });
  expect(mockQueue).toHaveBeenCalledWith({ kind: 'appointment', id: 32 }, 1);

  await act(async () => { initial.resolve(message()); });

  expect(mockQueue).toHaveBeenCalledTimes(1);
});

it('drops a delayed initial-notification completion after runtime/account switch even with identical resource ids', async () => {
  const old = runtime();
  const initial = deferred<PushMessage>();
  mockInitial.mockReturnValueOnce(initial.promise).mockResolvedValueOnce(null);
  const client = new QueryClient();
  await act(async () => { tree = TestRenderer.create(<Probe options={{ runtime: old, user, queryClient: client }} />); });
  const owner = currentPushOwner()!;
  await act(async () => { await retirePushOwner(owner); });
  (old as { active: boolean }).active = false;
  const next = runtime({ id: 2, credentialBinding: 'activation-b' });
  await act(async () => { tree!.update(<Probe options={{ runtime: next, user, queryClient: client }} />); });
  const nextOwner = currentPushOwner()!;
  await act(async () => { publishPushScope(nextOwner, makePushScope(nextOwner, 'deployment-a', initialId)); });
  await act(async () => { initial.resolve(message()); });
  expect(mockQueue).not.toHaveBeenCalled();
});

it('ignores stale foreground/open callbacks before invalidation or queuing and uses the selected query client', async () => {
  const api = runtime();
  const client = new QueryClient();
  const invalidate = jest.spyOn(client, 'invalidateQueries').mockResolvedValue();
  await act(async () => { tree = TestRenderer.create(<QueryClientProvider client={client}><Probe options={{ runtime: api, user }} /></QueryClientProvider>); });
  const owner = currentPushOwner()!;
  await act(async () => { publishPushScope(owner, makePushScope(owner, 'deployment-a', registrationId)); });
  await act(async () => { mockMessages[0](message(initialId, 'team_task')); mockOpened[0](message(initialId)); });
  expect(invalidate).not.toHaveBeenCalled();
  expect(mockQueue).not.toHaveBeenCalled();
  await act(async () => { mockMessages[0](message(registrationId, 'team_task')); });
  expect(invalidate).toHaveBeenCalledWith({ queryKey: ['notifications'], exact: false });
  expect(invalidate).toHaveBeenCalledWith({ queryKey: ['team'], exact: false });
  invalidate.mockClear();
  await act(async () => { await retirePushOwner(owner); });
  await act(async () => { mockMessages[0](message(registrationId, 'team_task')); mockOpened[0](message()); });
  expect(invalidate).not.toHaveBeenCalled();
  expect(mockQueue).not.toHaveBeenCalled();
});

it('revalidates the current auth callback before any delayed initial routing or foreground hint', async () => {
  let authenticated = true;
  const api = runtime();
  const initial = deferred<PushMessage>();
  mockInitial.mockReturnValueOnce(initial.promise);
  const client = new QueryClient();
  const invalidate = jest.spyOn(client, 'invalidateQueries').mockResolvedValue();
  await act(async () => { tree = TestRenderer.create(<Probe options={{ runtime: api, user, queryClient: client, isCurrent: () => authenticated }} />); });
  const owner = currentPushOwner()!;
  await act(async () => { publishPushScope(owner, makePushScope(owner, 'deployment-a', registrationId)); });
  authenticated = false;
  await act(async () => { initial.resolve(message()); mockMessages[0](message()); mockOpened[0](message()); });
  expect(mockQueue).not.toHaveBeenCalled();
  expect(invalidate).not.toHaveBeenCalled();
});

it('preserves old single-status normal mode with the provider query client and queued legacy taps', async () => {
  function LegacyProbe() { usePushSession('signedIn'); return null; }
  const client = new QueryClient();
  mockInitial.mockResolvedValue({ data: { type: 'announcement_published', id: '31' } });
  await act(async () => { tree = TestRenderer.create(<QueryClientProvider client={client}><LegacyProbe /></QueryClientProvider>); });
  expect(mockQueue).toHaveBeenCalledWith({ kind: 'announcement', id: 31 }, undefined);
});

it('never subscribes token or initial callbacks while signed out and does not revoke on ordinary hook unmount', async () => {
  const api = runtime();
  const client = new QueryClient();
  await act(async () => { tree = TestRenderer.create(<Probe status="signedOut" options={{ runtime: api, user, queryClient: client }} />); });
  expect(mockRegister).not.toHaveBeenCalled();
  expect(mockInitial).not.toHaveBeenCalled();
  expect(mockWatch).not.toHaveBeenCalled();
  expect(mockRevoke).toHaveBeenCalledWith({ runtime: api });
  mockRevoke.mockClear();
  await act(async () => { tree!.update(<Probe options={{ runtime: api, user, queryClient: client }} />); });
  await act(async () => { tree!.unmount(); tree = null; });
  expect(mockRevoke).not.toHaveBeenCalled();
  expect(mockPause).toHaveBeenCalledWith(api);
});

it.each(['resume', 'reconnect'] as const)('retries incomplete setup on %s without duplicating checking work', async trigger => {
  const api = runtime();
  const client = new QueryClient();
  await act(async () => { tree = TestRenderer.create(<Probe options={{ runtime: api, user, queryClient: client }} />); });
  expect(mockRegister).toHaveBeenCalledTimes(1);
  await act(async () => { registrationAttempts[0].resolve(); setupState('warning'); });
  if (trigger === 'resume') { await application('background'); await application('active'); }
  else { await network(false); await network(true); }
  expect(mockRegister).toHaveBeenCalledTimes(2);
  if (trigger === 'resume') { await application('background'); await application('active'); }
  else { await network(false); await network(true); }
  expect(mockRegister).toHaveBeenCalledTimes(2);
  await act(async () => { registrationAttempts[1].resolve(); setupState('ready'); });
  await application('background'); await application('active');
  await network(false); await network(true);
  expect(mockRegister).toHaveBeenCalledTimes(2);
});

it('retries a permission grant once even when settings return also runs the resume callback', async () => {
  const api = runtime();
  const client = new QueryClient();
  await act(async () => { tree = TestRenderer.create(<Probe options={{ runtime: api, user, queryClient: client }} />); });
  await act(async () => { registrationAttempts[0].resolve(); setupState('warning'); });
  mockRefreshPermission.mockImplementation(async () => { publishPermission('granted'); return 'granted'; });
  await application('background'); await application('active');
  expect(mockRefreshPermission).toHaveBeenCalledTimes(1);
  expect(mockRegister).toHaveBeenCalledTimes(2);
  await act(async () => { publishPermission('granted'); });
  expect(mockRegister).toHaveBeenCalledTimes(2);
});

it('does not retry while backgrounded or offline and catches up on a foreground reconnection', async () => {
  const api = runtime();
  const client = new QueryClient();
  await act(async () => { tree = TestRenderer.create(<Probe options={{ runtime: api, user, queryClient: client }} />); });
  await act(async () => { registrationAttempts[0].resolve(); setupState('warning'); });
  await application('background');
  await network(false); await network(true);
  await act(async () => { publishPermission('granted'); });
  expect(mockRegister).toHaveBeenCalledTimes(1);
  await network(false); await application('active');
  expect(mockRegister).toHaveBeenCalledTimes(1);
  await network(true);
  expect(mockRegister).toHaveBeenCalledTimes(2);
});

it('posts only accepted foreground messages and never posts notification-open callbacks', async () => {
  const api = runtime();
  const client = new QueryClient();
  await act(async () => { tree = TestRenderer.create(<Probe options={{ runtime: api, user, queryClient: client }} />); });
  const owner = currentPushOwner()!;
  const accepted = { ...message(), messageId: 'foreground-1', notification: { title: 'Update', body: 'Open the app' } };
  await act(async () => { mockMessages[0](accepted); });
  expect(mockShowSystemNotification).not.toHaveBeenCalled();
  await act(async () => { publishPushScope(owner, makePushScope(owner, 'deployment-a', registrationId)); });
  await act(async () => { mockMessages[0](message(initialId)); mockOpened[0](accepted); });
  expect(mockShowSystemNotification).not.toHaveBeenCalled();
  await act(async () => { mockMessages[0](accepted); });
  expect(mockShowSystemNotification).toHaveBeenCalledTimes(1);
  expect(mockShowSystemNotification).toHaveBeenCalledWith(accepted, owner);
  await act(async () => { await retirePushOwner(owner); mockMessages[0](accepted); });
  expect(mockShowSystemNotification).toHaveBeenCalledTimes(1);
});

it.each(['signout', 'unmount', 'runtime'] as const)('rejects delayed resume and old event callbacks after %s', async boundary => {
  const api = runtime();
  const client = new QueryClient();
  await act(async () => { tree = TestRenderer.create(<Probe options={{ runtime: api, user, queryClient: client }} />); });
  const owner = currentPushOwner()!;
  await act(async () => { publishPushScope(owner, makePushScope(owner, 'deployment-a', registrationId)); setupState('warning'); });
  const refresh = deferred<PushPermission>();
  mockRefreshPermission.mockReturnValueOnce(refresh.promise);
  await application('background'); await application('active');
  const oldMessage = mockMessages[0];
  const oldOnline = onlineCallbacks[0];
  const oldPermission = mockPermissionCallbacks[0];
  if (boundary === 'signout') {
    await act(async () => { tree!.update(<Probe status="signedOut" options={{ runtime: api, user, queryClient: client }} />); });
  } else if (boundary === 'unmount') {
    await act(async () => { tree!.unmount(); tree = null; });
  } else {
    (api as { active: boolean }).active = false;
    const next = runtime({ id: 2, credentialBinding: 'new-activation' });
    await act(async () => { tree!.update(<Probe options={{ runtime: next, user, queryClient: client }} />); });
  }
  mockRegister.mockClear();
  await act(async () => {
    refresh.resolve('granted'); oldOnline(true); oldPermission(); oldMessage(message());
  });
  expect(mockRegister).not.toHaveBeenCalled();
  expect(mockShowSystemNotification).not.toHaveBeenCalled();
  expect(activityStops[0]).toHaveBeenCalledTimes(1);
  expect(onlineStops[0]).toHaveBeenCalledTimes(1);
  expect(mockPermissionStops[0]).toHaveBeenCalledTimes(1);
  expect(mockMessageStops[0]).toHaveBeenCalledTimes(1);
  expect(mockOpenedStops[0]).toHaveBeenCalledTimes(1);
  expect(mockTokenStops[0]).toHaveBeenCalledTimes(1);
  expect(scopeStops[0]).toHaveBeenCalledTimes(1);
});

it('exposes immutable warning status through a lightweight event/hook without starting push', async () => {
  let status = getPushSetupStatus();
  function StatusProbe() { status = usePushSetupStatus(); return null; }
  await act(async () => { tree = TestRenderer.create(<StatusProbe />); });
  await act(async () => { publishPushSetupStatus({ state: 'warning', warning: 'Server push setup unavailable.', code: 'unsupported', runtimeId: 1 }); });
  expect(status).toEqual({ state: 'warning', warning: 'Server push setup unavailable.', code: 'unsupported', runtimeId: 1 });
  expect(Object.isFrozen(status)).toBe(true);
  expect(mockRegister).not.toHaveBeenCalled();
  expect(mockInitial).not.toHaveBeenCalled();
});
