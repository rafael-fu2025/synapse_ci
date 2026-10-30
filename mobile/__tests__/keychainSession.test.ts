import * as Keychain from 'react-native-keychain';
import { clearRefreshToken, getRefreshToken, setRefreshToken } from '../src/lib/keychain';

jest.mock('react-native-keychain', () => ({
  ACCESSIBLE: { WHEN_UNLOCKED_THIS_DEVICE_ONLY: 'device-only' },
  STORAGE_TYPE: { AES_GCM_NO_AUTH: 'KeystoreAESGCM_NoAuth' },
  getGenericPassword: jest.fn(),
  setGenericPassword: jest.fn(),
  resetGenericPassword: jest.fn(),
}));

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => { resolve = yes; });
  return { promise, resolve };
}

// Observe failures immediately while subsequent writes are queued before asserting.
function captureRejection(pending: Promise<unknown>): Promise<unknown> {
  return pending.then(() => undefined, (error: unknown) => error);
}

beforeEach(() => {
  jest.resetAllMocks();
  jest.mocked(Keychain.setGenericPassword).mockResolvedValue({ service: 'com.humus.synapse.session', storage: Keychain.STORAGE_TYPE.AES_GCM_NO_AUTH });
  jest.mocked(Keychain.resetGenericPassword).mockResolvedValue(true);
  jest.mocked(Keychain.getGenericPassword).mockResolvedValue(false);
});

it('serializes rotation → clear → new sign-in, and reads only after all native writes finish', async () => {
  const rotation = deferred<void>();
  const deletion = deferred<void>();
  const signIn = deferred<void>();
  const rotating = deferred<void>();
  const deleting = deferred<void>();
  const signingIn = deferred<void>();
  const order: string[] = [];
  let stored: string | null = 'old-refresh';
  jest.mocked(Keychain.setGenericPassword)
    .mockImplementationOnce(async () => {
      order.push('rotation:start');
      rotating.resolve();
      await rotation.promise;
      stored = 'rotated-refresh';
      order.push('rotation:end');
      return { service: 'test', storage: Keychain.STORAGE_TYPE.AES_GCM_NO_AUTH };
    })
    .mockImplementationOnce(async () => {
      order.push('sign-in:start');
      signingIn.resolve();
      await signIn.promise;
      stored = 'new-user-refresh';
      order.push('sign-in:end');
      return { service: 'test', storage: Keychain.STORAGE_TYPE.AES_GCM_NO_AUTH };
    });
  jest.mocked(Keychain.resetGenericPassword).mockImplementationOnce(async () => {
    order.push('clear:start');
    deleting.resolve();
    await deletion.promise;
    stored = null;
    order.push('clear:end');
    return true;
  });
  jest.mocked(Keychain.getGenericPassword).mockImplementation(async () => {
    order.push('read');
    return stored === null ? false : { username: 'synapse-session', password: stored, service: 'test', storage: Keychain.STORAGE_TYPE.AES_GCM_NO_AUTH };
  });

  const rotate = setRefreshToken('rotated-refresh');
  const clear = clearRefreshToken();
  const login = setRefreshToken('new-user-refresh');
  const read = getRefreshToken();
  await rotating.promise;
  expect(order).toEqual(['rotation:start']);
  expect(Keychain.resetGenericPassword).not.toHaveBeenCalled();
  expect(Keychain.setGenericPassword).toHaveBeenCalledTimes(1);
  expect(Keychain.getGenericPassword).not.toHaveBeenCalled();

  rotation.resolve();
  await rotate;
  await deleting.promise;
  expect(order).toEqual(['rotation:start', 'rotation:end', 'clear:start']);
  expect(Keychain.setGenericPassword).toHaveBeenCalledTimes(1);
  expect(Keychain.getGenericPassword).not.toHaveBeenCalled();

  deletion.resolve();
  await clear;
  await signingIn.promise;
  expect(stored).toBeNull();
  expect(Keychain.getGenericPassword).not.toHaveBeenCalled();
  signIn.resolve();
  await login;
  await expect(read).resolves.toBe('new-user-refresh');
  expect(order).toEqual(['rotation:start', 'rotation:end', 'clear:start', 'clear:end', 'sign-in:start', 'sign-in:end', 'read']);
  expect(Keychain.setGenericPassword).toHaveBeenNthCalledWith(1, 'synapse-session', 'rotated-refresh', {
    service: 'com.humus.synapse.session', accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
  });
  expect(Keychain.setGenericPassword).toHaveBeenNthCalledWith(2, 'synapse-session', 'new-user-refresh', {
    service: 'com.humus.synapse.session', accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
  });
  expect(Keychain.resetGenericPassword).toHaveBeenCalledWith({ service: 'com.humus.synapse.session' });
});

it('rejects a false native save without poisoning subsequent clear and sign-in writes', async () => {
  jest.mocked(Keychain.setGenericPassword).mockResolvedValueOnce(false);
  const failed = setRefreshToken('unsaved-refresh');
  const rejected = captureRejection(failed);
  const clear = clearRefreshToken();
  const login = setRefreshToken('new-user-refresh');
  await expect(rejected).resolves.toMatchObject({
    message: expect.stringContaining('Secure session storage is unavailable'),
  });
  await expect(clear).resolves.toBeUndefined();
  await expect(login).resolves.toBeUndefined();
  expect(Keychain.resetGenericPassword).toHaveBeenCalledTimes(1);
  expect(Keychain.setGenericPassword).toHaveBeenCalledTimes(2);
});

it('propagates a rejected native save but still drains the next queued write', async () => {
  const error = new Error('native storage failed');
  jest.mocked(Keychain.setGenericPassword).mockRejectedValueOnce(error);
  const failed = setRefreshToken('unsaved-refresh');
  const rejected = captureRejection(failed);
  const next = setRefreshToken('new-user-refresh');
  await expect(rejected).resolves.toBe(error);
  await expect(next).resolves.toBeUndefined();
  expect(Keychain.setGenericPassword).toHaveBeenCalledTimes(2);
});

it('continues queued sign-in after a native deletion error', async () => {
  jest.mocked(Keychain.resetGenericPassword).mockRejectedValueOnce(new Error('native deletion failed'));
  const clear = clearRefreshToken();
  const next = setRefreshToken('new-user-refresh');
  await expect(clear).resolves.toBeUndefined();
  await expect(next).resolves.toBeUndefined();
  expect(Keychain.setGenericPassword).toHaveBeenCalledWith('synapse-session', 'new-user-refresh', expect.any(Object));
});
