import * as Keychain from 'react-native-keychain';
import {
  acceptsPushMessage, activatePushOwner, clearPushScope, currentPushScope, isPushOwnerCurrent,
  makePushScope, publishPushScope, readPushScope, retirePushOwner, savePushScope, validDeploymentId,
  __resetPushScopeForTests, type PushRuntimeIdentity,
} from '../src/lib/push/scope';

jest.mock('../src/lib/config', () => ({ config: { lanTest: true } }));
jest.mock('react-native-keychain', () => ({
  ACCESSIBLE: { WHEN_UNLOCKED_THIS_DEVICE_ONLY: 'device-only' },
  getGenericPassword: jest.fn(),
  setGenericPassword: jest.fn(),
  resetGenericPassword: jest.fn(),
}));

const registrationId = '11111111-1111-4111-8111-111111111111';
const user = { tenant_id: 7, id: 31 };
let stored: string | null;

function runtime(overrides: Partial<PushRuntimeIdentity> = {}): PushRuntimeIdentity {
  return {
    id: 1, apiBaseUrl: 'http://172.16.153.237:8080', profileId: 'university',
    credentialBinding: 'activation-a', lanTest: true, active: true, sessionVersion: 1,
    ...overrides,
  };
}

function message(deployment = 'deployment-a', registration = registrationId) {
  return { data: { scope_version: '1', deployment_id: deployment, registration_id: registration,
    type: 'appointment_confirmed', id: '31' } };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => { resolve = yes; });
  return { promise, resolve };
}

beforeEach(() => {
  __resetPushScopeForTests();
  jest.clearAllMocks();
  stored = null;
  (Keychain.getGenericPassword as jest.Mock).mockImplementation(async () => stored === null ? false : { password: stored });
  (Keychain.setGenericPassword as jest.Mock).mockImplementation(async (_username: string, password: string) => {
    stored = password;
    return { service: 'test' };
  });
  (Keychain.resetGenericPassword as jest.Mock).mockImplementation(async () => { stored = null; return true; });
});

it('matches the backend deployment-id alphabet and length constraints', () => {
  expect(validDeploymentId('campus.test-1')).toBe(true);
  expect(validDeploymentId('home_test-1')).toBe(true);
  expect(validDeploymentId('a'.repeat(128))).toBe(true);
  expect(validDeploymentId('.invalid-first')).toBe(false);
  expect(validDeploymentId('_invalid-first')).toBe(false);
  expect(validDeploymentId('short')).toBe(false);
  expect(validDeploymentId('a'.repeat(129))).toBe(false);
});

it('restores a saved same-server/account/credential binding under a different cold-start runtime id', async () => {
  const first = activatePushOwner({ runtime: runtime(), user });
  const binding = makePushScope(first, 'deployment-a', registrationId);
  await savePushScope(first, binding, true);
  publishPushScope(first, binding);
  expect(acceptsPushMessage(message(), first)).toBe(true);

  __resetPushScopeForTests(); // Module memory dies; native Keychain remains on disk.
  const restored = activatePushOwner({ runtime: runtime({ id: 82 }), user });
  expect(acceptsPushMessage(message(), restored)).toBe(false);
  const saved = await readPushScope(restored);
  expect(saved).toEqual(binding);
  expect(saved).not.toHaveProperty('token');
  expect(saved).not.toHaveProperty('runtime_id');
  publishPushScope(restored, saved!);
  expect(acceptsPushMessage(message(), restored)).toBe(true);
  expect(Keychain.setGenericPassword).toHaveBeenCalledWith('synapse-lan-push', JSON.stringify({ ...binding, acknowledged: true }), {
    service: 'com.humus.synapse.lantest.push', accessible: 'device-only',
  });
});

it.each([undefined, false, 'true'] as const)('never restores a saved binding without an exact acknowledged flag (%s)', async acknowledged => {
  const selected = activatePushOwner({ runtime: runtime(), user });
  const binding = makePushScope(selected, 'deployment-a', registrationId);
  stored = JSON.stringify({ ...binding, acknowledged });

  await expect(readPushScope(selected)).resolves.toBeNull();
  expect(acceptsPushMessage(message(), selected)).toBe(false);
});

it('keeps provisional scope untrusted across a cold restart until acknowledgment is persisted', async () => {
  const selected = activatePushOwner({ runtime: runtime(), user });
  const binding = makePushScope(selected, 'deployment-a', registrationId);
  await savePushScope(selected, binding, false);
  expect(JSON.parse(stored!)).toMatchObject({ acknowledged: false });
  await expect(readPushScope(selected)).resolves.toBeNull();

  __resetPushScopeForTests();
  const next = activatePushOwner({ runtime: runtime({ id: 2 }), user });
  await expect(readPushScope(next)).resolves.toBeNull();
  await savePushScope(next, binding, true);
  await expect(readPushScope(next)).resolves.toEqual(binding);
});

it('invalidates a previously acknowledged binding when a rejected update cannot overwrite native storage', async () => {
  const selected = activatePushOwner({ runtime: runtime(), user });
  const binding = makePushScope(selected, 'deployment-a', registrationId);
  await savePushScope(selected, binding, true);
  (Keychain.setGenericPassword as jest.Mock).mockResolvedValueOnce(false);

  await expect(savePushScope(selected, binding, false)).rejects.toThrow('Secure push binding storage is unavailable.');
  await expect(readPushScope(selected)).resolves.toBeNull();
  await savePushScope(selected, binding, true);
  await expect(readPushScope(selected)).resolves.toEqual(binding);
});

it.each([
  ['profile', { profileId: 'home' }, user],
  ['origin', { apiBaseUrl: 'http://192.168.1.11:8080' }, user],
  ['credential activation', { credentialBinding: 'activation-b' }, user],
  ['account', {}, { tenant_id: 7, id: 32 }],
  ['tenant', {}, { tenant_id: 8, id: 31 }],
] as const)('never restores a saved binding from a different %s', async (_label, overrides, account) => {
  const first = activatePushOwner({ runtime: runtime(), user });
  await savePushScope(first, makePushScope(first, 'deployment-a', registrationId), true);
  __resetPushScopeForTests();
  const next = activatePushOwner({ runtime: runtime({ id: 2, ...overrides }), user: account });
  await expect(readPushScope(next)).resolves.toBeNull();
  expect(acceptsPushMessage(message(), next)).toBe(false);
});

it('requires all scope fields before accepting any LAN hint', () => {
  const selected = activatePushOwner({ runtime: runtime(), user });
  publishPushScope(selected, makePushScope(selected, 'deployment-a', registrationId));
  expect(acceptsPushMessage(message(), selected)).toBe(true);
  expect(acceptsPushMessage(message('deployment-b'), selected)).toBe(false);
  expect(acceptsPushMessage(message('deployment-a', '22222222-2222-4222-8222-222222222222'), selected)).toBe(false);
  expect(acceptsPushMessage({ data: { type: 'appointment_confirmed', id: '31' } }, selected)).toBe(false);
  expect(acceptsPushMessage({ data: { ...message().data, scope_version: '2' } }, selected)).toBe(false);
  expect(acceptsPushMessage(null, selected)).toBe(false);
});

it('invalidates memory synchronously and deletes scope on signout', async () => {
  const selected = activatePushOwner({ runtime: runtime(), user });
  const binding = makePushScope(selected, 'deployment-a', registrationId);
  await savePushScope(selected, binding, true);
  publishPushScope(selected, binding);
  const clear = retirePushOwner(selected);
  expect(isPushOwnerCurrent(selected)).toBe(false);
  expect(currentPushScope(selected)).toBeNull();
  expect(acceptsPushMessage(message(), selected)).toBe(false);
  await clear;
  expect(stored).toBeNull();
});

it('rejects a delayed native read after runtime or auth ownership changes', async () => {
  const api = runtime();
  const selected = activatePushOwner({ runtime: api, user });
  const binding = makePushScope(selected, 'deployment-a', registrationId);
  const read = deferred<{ password: string }>();
  (Keychain.getGenericPassword as jest.Mock).mockReturnValueOnce(read.promise);
  const pending = readPushScope(selected);
  await Promise.resolve();
  (api as { sessionVersion: number }).sessionVersion += 1;
  read.resolve({ password: JSON.stringify(binding) });
  await expect(pending).resolves.toBeNull();
  expect(publishPushScope(selected, binding)).toBe(false);
});

it('guards queued old writes and orders a started old save → clear → new owner save', async () => {
  const first = activatePushOwner({ runtime: runtime(), user });
  const firstBinding = makePushScope(first, 'deployment-a', registrationId);
  const saving = deferred<void>();
  const started = deferred<void>();
  (Keychain.setGenericPassword as jest.Mock).mockImplementationOnce(async (_username: string, password: string) => {
    started.resolve();
    await saving.promise;
    stored = password;
    return { service: 'test' };
  });
  const oldSave = savePushScope(first, firstBinding, true);
  await started.promise;
  const queuedOldSave = savePushScope(first, firstBinding, true);
  const clear = retirePushOwner(first);
  const second = activatePushOwner({ runtime: runtime({ id: 2, credentialBinding: 'activation-b' }), user });
  const secondBinding = makePushScope(second, 'deployment-a', '22222222-2222-4222-8222-222222222222');
  const newSave = savePushScope(second, secondBinding, true);
  saving.resolve();
  await expect(oldSave).resolves.toBe(false);
  await expect(queuedOldSave).resolves.toBe(false);
  await clear;
  await expect(newSave).resolves.toBe(true);
  expect(JSON.parse(stored!)).toEqual({ ...secondBinding, acknowledged: true });
  expect(Keychain.setGenericPassword).toHaveBeenCalledTimes(2);
  expect(Keychain.resetGenericPassword).toHaveBeenCalledTimes(1);
  await clearPushScope(first);
  expect(JSON.parse(stored!)).toEqual({ ...secondBinding, acknowledged: true });
});

it('cannot route an old push after offline revoke and switching back to the same server and account ids', async () => {
  const first = activatePushOwner({ runtime: runtime(), user });
  const oldBinding = makePushScope(first, 'deployment-a', registrationId);
  await savePushScope(first, oldBinding, true);
  publishPushScope(first, oldBinding);
  const removed = retirePushOwner(first);
  const home = activatePushOwner({ runtime: runtime({ id: 2, profileId: 'home', credentialBinding: 'activation-b' }), user });
  await retirePushOwner(home);
  await removed;
  const back = activatePushOwner({ runtime: runtime({ id: 3, credentialBinding: 'activation-c' }), user });
  expect(await readPushScope(back)).toBeNull();
  const next = makePushScope(back, 'deployment-a', '33333333-3333-4333-8333-333333333333');
  publishPushScope(back, next);
  expect(acceptsPushMessage(message(), back)).toBe(false);
  expect(acceptsPushMessage(message('deployment-a', next.registration_id), back)).toBe(true);
});

it('never restores malformed scope or captures token fields from native storage', async () => {
  const selected = activatePushOwner({ runtime: runtime(), user });
  stored = '{"scope_version":1,"registration_id":"not-an-id"}';
  await expect(readPushScope(selected)).resolves.toBeNull();
  stored = JSON.stringify({ ...makePushScope(selected, 'deployment-a', registrationId), acknowledged: true, token: 'old-device-token' });
  const restored = await readPushScope(selected);
  expect(restored).not.toBeNull();
  expect(restored).not.toHaveProperty('token');
  expect(restored).not.toHaveProperty('acknowledged');
});

it('uses an explicit auth activation fallback guard when the runtime exposes no version', () => {
  let current = true;
  const selected = activatePushOwner({ runtime: runtime({ sessionVersion: undefined }), user, isCurrent: () => current });
  publishPushScope(selected, makePushScope(selected, 'deployment-a', registrationId));
  current = false;
  expect(acceptsPushMessage(message(), selected)).toBe(false);
});
