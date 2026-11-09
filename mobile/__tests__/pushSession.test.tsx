import React from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { usePushSession, type PushSessionOptions, type PushSessionStatus } from '../src/lib/push/session';
import {
  activatePushOwner, currentPushOwner, makePushScope, publishPushScope, retirePushOwner,
  __resetPushScopeForTests,
} from '../src/lib/push/scope';
import { queueNavigationIntent } from '../src/features/workspace/navigationIntent';
import { getPushSetupStatus, publishPushSetupStatus, usePushSetupStatus, __resetPushSetupStatusForTests } from '../src/lib/push/setupStatus';
import type { PushMessage } from '../src/lib/push/fcm';
import type { ApiRuntime } from '../src/api/client';

const mockRegister = jest.fn();
const mockRevoke = jest.fn();
const mockPause = jest.fn();
const mockWatch = jest.fn();
const mockInitial = jest.fn();
let mockMessages: Array<(message: PushMessage) => void> = [];
let mockOpened: Array<(message: PushMessage) => void> = [];

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
  onPushMessage: (handler: (message: PushMessage) => void) => { mockMessages.push(handler); return () => undefined; },
  onPushOpened: (handler: (message: PushMessage) => void) => { mockOpened.push(handler); return () => undefined; },
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

beforeEach(() => {
  __resetPushScopeForTests();
  __resetPushSetupStatusForTests();
  jest.resetAllMocks();
  mockMessages = [];
  mockOpened = [];
  tree = null;
  mockInitial.mockResolvedValue(null);
  mockWatch.mockReturnValue(() => undefined);
  mockRevoke.mockResolvedValue(undefined);
  mockRegister.mockImplementation((options: PushSessionOptions) => { activatePushOwner(options); return Promise.resolve(); });
});
afterEach(async () => { await act(async () => { tree?.unmount(); }); });

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
