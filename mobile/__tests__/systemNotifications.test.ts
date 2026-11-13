import { NativeModules, Platform } from 'react-native';
import type { PushMessage } from '../src/lib/push/fcm';
import {
  __resetPushScopeForTests, activatePushOwner, makePushScope, publishPushScope, retirePushOwner,
  type PushOwner, type PushRuntimeIdentity,
} from '../src/lib/push/scope';
import {
  checkSystemNotifications, clearSystemNotifications, hasSystemNotifications, showSystemNotification,
} from '../src/lib/push/systemNotifications';

jest.mock('../src/lib/config', () => ({ config: { lanTest: false } }));
jest.mock('react-native-keychain', () => ({
  ACCESSIBLE: { WHEN_UNLOCKED_THIS_DEVICE_ONLY: 'device-only' },
  getGenericPassword: jest.fn(async () => false),
  setGenericPassword: jest.fn(async () => true),
  resetGenericPassword: jest.fn(async () => true),
}));

const post = jest.fn();
const clear = jest.fn();
const areNotificationsEnabled = jest.fn();
const registrationId = '11111111-1111-4111-8111-111111111111';
const deploymentId = 'deployment-a';

function runtime(overrides: Partial<PushRuntimeIdentity> = {}): PushRuntimeIdentity {
  return {
    id: 43, apiBaseUrl: 'https://private-origin.example.test/api', profileId: 'private-profile',
    credentialBinding: 'private-credential-binding', lanTest: false, active: true, sessionVersion: 1,
    ...overrides,
  };
}

function owner(overrides: Partial<PushRuntimeIdentity> = {}): PushOwner {
  return activatePushOwner({ runtime: runtime(overrides), user: { tenant_id: 71, id: 931 } });
}

function message(overrides: Partial<PushMessage> = {}): PushMessage {
  return {
    messageId: '0:notification-id', notification: { title: 'Appointment confirmed', body: 'See your appointment details' },
    data: { type: 'appointment_confirmed', id: '31', scope_version: '1', deployment_id: deploymentId, registration_id: registrationId },
    ...overrides,
  };
}

beforeEach(() => {
  __resetPushScopeForTests();
  jest.resetAllMocks();
  jest.replaceProperty(Platform, 'OS', 'android');
  areNotificationsEnabled.mockResolvedValue(true);
  NativeModules.SystemNotifications = { post, clear, areNotificationsEnabled };
});

afterEach(() => {
  delete NativeModules.SystemNotifications;
  jest.restoreAllMocks();
});

it('posts notification-bearing messages with an unchanged FCM id and a stable opaque epoch-only owner key', () => {
  const captured = owner();
  const received = message();
  expect(showSystemNotification(received, captured)).toBeUndefined();
  showSystemNotification(message({ messageId: '0:second-id' }), captured);
  expect(post).toHaveBeenNthCalledWith(1, `synapse-push-owner-${captured.epoch}`, '0:notification-id',
    'Appointment confirmed', 'See your appointment details');
  expect(post).toHaveBeenNthCalledWith(2, post.mock.calls[0][0], '0:second-id',
    'Appointment confirmed', 'See your appointment details');
  const key = post.mock.calls[0][0] as string;
  for (const sensitive of ['private-origin', 'private-profile', 'private-credential-binding', '931', '71', 'deployment-a', registrationId]) {
    expect(key).not.toContain(sensitive);
  }
  expect(received).toEqual(message());
});

it('passes duplicate ids to the native owner-scoped bounded dedup policy, not a second JS cache', () => {
  const captured = owner();
  showSystemNotification(message(), captured);
  showSystemNotification(message(), captured);
  expect(post).toHaveBeenCalledTimes(2);
  expect(post.mock.calls[0]).toEqual(post.mock.calls[1]);
});

it.each([undefined, '', '  \t\n', null, 42])('ignores missing or invalid FCM message ids (%s)', messageId => {
  showSystemNotification(message({ messageId } as Partial<PushMessage>), owner());
  expect(post).not.toHaveBeenCalled();
});

it.each([undefined, null, 'not-a-notification'])('never manufactures a system notice for data-only or malformed notification payloads (%s)', notification => {
  showSystemNotification(message({ notification } as Partial<PushMessage>), owner());
  expect(post).not.toHaveBeenCalled();
});

it('keeps the original FCM id (including surrounding whitespace) for RNFirebase lookup', () => {
  const captured = owner();
  showSystemNotification(message({ messageId: '  exact-fcm-id  ' }), captured);
  expect(post.mock.calls[0][1]).toBe('  exact-fcm-id  ');
});

it('uses safe defaults for omitted production text without copying data or links', () => {
  const captured = owner();
  showSystemNotification(message({ notification: {} }), captured);
  expect(post).toHaveBeenCalledWith(`synapse-push-owner-${captured.epoch}`, '0:notification-id', 'Synapse', '');
});

it('redacts accepted LAN title/body before crossing the native boundary and preserves the original message', () => {
  const captured = owner({ lanTest: true });
  publishPushScope(captured, makePushScope(captured, deploymentId, registrationId));
  const received = message({ notification: { title: 'Private tenant name', body: 'Sensitive survey or appointment content' } });
  showSystemNotification(received, captured);
  expect(post).toHaveBeenCalledWith(`synapse-push-owner-${captured.epoch}`, '0:notification-id',
    'Synapse LAN Test', 'Open the app to check for updates');
  expect(received.notification).toEqual({ title: 'Private tenant name', body: 'Sensitive survey or appointment content' });
  expect(post.mock.calls[0]).toHaveLength(4);
});

it('rejects LAN hints until acknowledged scope is available and when deployment/registration/version mismatches', () => {
  const captured = owner({ lanTest: true });
  showSystemNotification(message(), captured);
  expect(post).not.toHaveBeenCalled();
  publishPushScope(captured, makePushScope(captured, deploymentId, registrationId));
  const data = message().data!;
  for (const invalid of [undefined, { ...data, scope_version: '2' }, { ...data, deployment_id: 'deployment-b' },
    { ...data, registration_id: '22222222-2222-4222-8222-222222222222' }]) {
    showSystemNotification(message({ data: invalid }), captured);
  }
  expect(post).not.toHaveBeenCalled();
  showSystemNotification(message(), captured);
  expect(post).toHaveBeenCalledTimes(1);
});

it('rejects stale owners, inactive runtimes, changed sessions and auth activations', () => {
  const stale = owner();
  const current = owner();
  showSystemNotification(message(), stale);
  expect(post).not.toHaveBeenCalled();
  showSystemNotification(message(), current);
  expect(post).toHaveBeenCalledTimes(1);
  post.mockClear();

  const api = runtime();
  const captured = activatePushOwner({ runtime: api });
  (api as { active: boolean }).active = false;
  showSystemNotification(message(), captured);
  (api as { active: boolean }).active = true;
  (api as { sessionVersion: number }).sessionVersion = 2;
  showSystemNotification(message(), captured);
  let currentAuth = true;
  const authOwner = activatePushOwner({ isCurrent: () => currentAuth });
  currentAuth = false;
  showSystemNotification(message(), authOwner);
  expect(post).not.toHaveBeenCalled();
});

it('clears the captured retired owner even after the current owner changes, without targeting the newer key', async () => {
  const first = owner();
  showSystemNotification(message(), first);
  const second = owner();
  showSystemNotification(message(), second);
  expect(clearSystemNotifications(first)).toBeUndefined();
  clearSystemNotifications(first);
  expect(clear).toHaveBeenNthCalledWith(1, `synapse-push-owner-${first.epoch}`);
  expect(clear).toHaveBeenNthCalledWith(2, `synapse-push-owner-${first.epoch}`);
  showSystemNotification(message({ messageId: 'next-id' }), second);
  expect(post).toHaveBeenLastCalledWith(`synapse-push-owner-${second.epoch}`, 'next-id',
    'Appointment confirmed', 'See your appointment details');
  await retirePushOwner(second, false);
  clearSystemNotifications(second);
  expect(clear).toHaveBeenLastCalledWith(`synapse-push-owner-${second.epoch}`);
});

it('retires locally immediately so a delayed callback cannot post even before scope retirement or after native clear errors', () => {
  const captured = owner();
  clear.mockImplementationOnce(() => { throw new Error('native cleanup unavailable'); });
  expect(() => clearSystemNotifications(captured)).not.toThrow();
  showSystemNotification(message(), captured);
  expect(post).not.toHaveBeenCalled();
});

it.each([0, -1, NaN, Infinity, 1.5, Number.MAX_SAFE_INTEGER + 1])('does not serialize an invalid epoch (%s)', epoch => {
  const captured = { ...owner(), epoch };
  showSystemNotification(message(), captured);
  clearSystemNotifications(captured);
  expect(post).not.toHaveBeenCalled();
  expect(clear).not.toHaveBeenCalled();
});

it.each(['ios', 'web'] as const)('is a complete no-op on %s, including check-only capability', async platform => {
  jest.replaceProperty(Platform, 'OS', platform);
  const captured = owner();
  showSystemNotification(message(), captured);
  clearSystemNotifications(captured);
  expect(hasSystemNotifications()).toBe(false);
  await expect(checkSystemNotifications()).resolves.toBeNull();
  expect(post).not.toHaveBeenCalled();
  expect(clear).not.toHaveBeenCalled();
  expect(areNotificationsEnabled).not.toHaveBeenCalled();
});

it.each([undefined, null, {}, { post: false, clear: false }])('tolerates an older binary without the optional bridge (%s)', native => {
  NativeModules.SystemNotifications = native;
  const captured = owner();
  expect(() => showSystemNotification(message(), captured)).not.toThrow();
  expect(() => clearSystemNotifications(captured)).not.toThrow();
  expect(hasSystemNotifications()).toBe(false);
});

it('swallows native post failures so the existing guarded in-app foreground handler can continue', () => {
  post.mockImplementationOnce(() => { throw new Error('notifications unavailable'); });
  const captured = owner();
  expect(() => showSystemNotification(message(), captured)).not.toThrow();
  showSystemNotification(message({ messageId: 'retry-id' }), captured);
  expect(post).toHaveBeenCalledTimes(2);
});

it('checks native app/channel availability without posting, clearing or prompting', async () => {
  expect(hasSystemNotifications()).toBe(true);
  await expect(checkSystemNotifications()).resolves.toBe(true);
  areNotificationsEnabled.mockResolvedValueOnce(false);
  await expect(checkSystemNotifications()).resolves.toBe(false);
  expect(areNotificationsEnabled).toHaveBeenCalledTimes(2);
  expect(post).not.toHaveBeenCalled();
  expect(clear).not.toHaveBeenCalled();
});

it('returns null for unavailable, malformed or rejected native availability checks', async () => {
  NativeModules.SystemNotifications = { post, clear };
  expect(hasSystemNotifications()).toBe(true);
  await expect(checkSystemNotifications()).resolves.toBeNull();
  NativeModules.SystemNotifications = { post, clear, areNotificationsEnabled };
  areNotificationsEnabled.mockRejectedValueOnce(new Error('native check unavailable'));
  await expect(checkSystemNotifications()).resolves.toBeNull();
  areNotificationsEnabled.mockResolvedValueOnce('true');
  await expect(checkSystemNotifications()).resolves.toBeNull();
  delete NativeModules.SystemNotifications;
  await expect(checkSystemNotifications()).resolves.toBeNull();
});
