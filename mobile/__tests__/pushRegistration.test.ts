import * as Keychain from 'react-native-keychain';
import { request, type ApiRuntime } from '../src/api/client';
import {
  capturePushCleanup, getPushSetupStatus, pausePushSession, registerDevice, retryPushSetup, revokeDevice, watchPushToken,
  __resetPushRegistrationForTests,
} from '../src/lib/push/registration';
import { acceptsPushMessage, currentPushOwner, currentPushScope, __resetPushScopeForTests } from '../src/lib/push/scope';

const mockGetToken = jest.fn();
const mockPermission = jest.fn();
const mockRequestPermission = jest.fn();
let mockPlatform: 'android' | 'ios' = 'android';
const mockInstallation = jest.fn();
const mockTokenRefresh = jest.fn();
const mockCleanupRequest = jest.fn();
const mockNonce = jest.fn();
let mockStored: string | null = null;
let mockTokenHandler: ((token: string) => void) | null = null;
let mockLegacyRuntime: ApiRuntime;

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test', lanTest: false } }));
jest.mock('../src/lib/secureNonce', () => ({ secureClientNonce: () => mockNonce() }));
jest.mock('../src/lib/keychain', () => ({ getOrCreateInstallationId: (...args: unknown[]) => mockInstallation(...args) }));
jest.mock('../src/lib/push/permission', () => ({
  refreshNotificationPermission: (...args: unknown[]) => mockPermission(...args),
}));
jest.mock('../src/lib/push/fcm', () => ({
  requestPushPermission: (...args: unknown[]) => mockRequestPermission(...args),
  currentDeviceToken: () => mockGetToken(),
  currentPushPlatform: () => mockPlatform,
  onPushTokenRefresh: (handler: (token: string) => void) => {
    mockTokenHandler = handler;
    return mockTokenRefresh;
  },
}));
jest.mock('react-native-keychain', () => ({
  ACCESSIBLE: { WHEN_UNLOCKED_THIS_DEVICE_ONLY: 'device-only' },
  getGenericPassword: jest.fn(), setGenericPassword: jest.fn(), resetGenericPassword: jest.fn(),
}));
jest.mock('../src/api/client', () => {
  const mockedRequest = jest.fn();
  return {
    request: mockedRequest,
    bindApiClient: (provided?: ApiRuntime) => {
      const selectedRuntime = provided ?? mockLegacyRuntime;
      const version = selectedRuntime.sessionVersion;
      return {
        runtime: selectedRuntime, sessionVersion: version,
        isCurrent: () => selectedRuntime.active && selectedRuntime.sessionVersion === version,
        request: (path: string, options?: unknown) => mockedRequest(path, options, selectedRuntime),
      };
    },
    captureRuntimeCleanup: (provided?: ApiRuntime) => {
      const selectedRuntime = provided ?? mockLegacyRuntime;
      const snapshot = { runtimeId: selectedRuntime.id, apiBaseUrl: selectedRuntime.apiBaseUrl, sessionVersion: selectedRuntime.sessionVersion };
      return { request: (...args: unknown[]) => mockCleanupRequest(...args, snapshot) };
    },
  };
});

const mockRequest = request as jest.Mock;
const registrationId = '11111111-1111-4111-8111-111111111111';
const user = { tenant_id: 7, id: 31 };

function runtime(overrides: Partial<ApiRuntime> = {}): ApiRuntime {
  return {
    id: 1, apiBaseUrl: 'http://172.16.153.237:8080', profileId: 'university',
    credentialBinding: 'activation-a', lanTest: true, active: true, sessionVersion: 1, ...overrides,
  };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => { resolve = yes; });
  return { promise, resolve };
}

async function tick(): Promise<void> { for (let i = 0; i < 12; i += 1) { await Promise.resolve(); } }

function scopedMessage(id = registrationId) {
  return { data: { scope_version: '1', deployment_id: 'deployment-a', registration_id: id, type: 'appointment_confirmed', id: '31' } };
}

beforeEach(() => {
  __resetPushRegistrationForTests();
  __resetPushScopeForTests();
  jest.resetAllMocks();
  mockStored = null;
  mockTokenHandler = null;
  mockLegacyRuntime = runtime({ id: 0, apiBaseUrl: 'http://api.test', profileId: null, credentialBinding: null, lanTest: false });
  mockPermission.mockResolvedValue('granted');
  mockRequestPermission.mockResolvedValue('granted');
  mockPlatform = 'android';
  mockGetToken.mockResolvedValue('device-token-1');
  mockInstallation.mockResolvedValue('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
  mockNonce.mockReturnValue(registrationId);
  mockCleanupRequest.mockResolvedValue(undefined);
  (Keychain.getGenericPassword as jest.Mock).mockImplementation(async () => mockStored === null ? false : { password: mockStored });
  (Keychain.setGenericPassword as jest.Mock).mockImplementation(async (_name: string, password: string) => {
    mockStored = password; return { service: 'test' };
  });
  (Keychain.resetGenericPassword as jest.Mock).mockImplementation(async () => { mockStored = null; return true; });
  mockRequest.mockImplementation(async (path: string, options?: { body?: Record<string, unknown> }) => {
    if (path.endsWith('capabilities')) {
      return { data: { scope_version: 1, deployment_id: 'deployment-a', audiences: ['lan-test'] } };
    }
    return { data: { registered: true, scope_version: 1, deployment_id: 'deployment-a', registration_id: options?.body?.registration_id } };
  });
});

describe('legacy normal device lifecycle', () => {
  it('preserves normal registration without LAN capability or audience fields', async () => {
    await registerDevice();
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'POST', body: { token: 'device-token-1', platform: 'android',
        installation_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', capabilities: ['team_workspace'], device_label: undefined },
    }, mockLegacyRuntime);
    expect(mockRequest).toHaveBeenCalledTimes(1);
  });

  it('never registers without permission or a platform token', async () => {
    mockPermission.mockResolvedValue('denied');
    await registerDevice();
    expect(mockGetToken).not.toHaveBeenCalled();
    expect(mockRequest).not.toHaveBeenCalled();
    mockPermission.mockResolvedValue('granted');
    mockGetToken.mockResolvedValue(null);
    await registerDevice();
    expect(mockRequest).not.toHaveBeenCalled();
  });

  it('only checks Android permission during registration and never reopens a denied prompt', async () => {
    mockPermission.mockResolvedValue('denied');
    await registerDevice();
    await registerDevice();
    expect(mockRequestPermission).not.toHaveBeenCalled();
    expect(mockGetToken).not.toHaveBeenCalled();
    expect(mockRequest).not.toHaveBeenCalled();
  });

  it('preserves the existing signed-in permission request on iOS', async () => {
    mockPlatform = 'ios';
    await registerDevice();
    expect(mockRequestPermission).toHaveBeenCalledTimes(1);
    expect(mockPermission).not.toHaveBeenCalled();
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', expect.objectContaining({
      body: expect.objectContaining({ platform: 'ios' }),
    }), mockLegacyRuntime);
  });

  it('survives refused registration and withdrawal without propagating raw errors', async () => {
    mockRequest.mockRejectedValue(new Error('offline server URL/token should never be shown'));
    await expect(registerDevice()).resolves.toBeUndefined();
    expect(getPushSetupStatus().warning).not.toContain('server URL/token');
    mockCleanupRequest.mockRejectedValue(new Error('offline'));
    await expect(revokeDevice()).resolves.toBeUndefined();
  });

  it('withdraws exactly the session token once through no-refresh cleanup', async () => {
    await registerDevice();
    mockRequest.mockClear();
    await revokeDevice();
    await revokeDevice();
    expect(mockRequest).not.toHaveBeenCalled();
    expect(mockCleanupRequest).toHaveBeenCalledTimes(1);
    expect(mockCleanupRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'DELETE', body: { token: 'device-token-1' },
    }, { runtimeId: 0, apiBaseUrl: 'http://api.test', sessionVersion: 1 });
  });

  it('does not withdraw a never-registered token', async () => {
    await revokeDevice();
    expect(mockCleanupRequest).not.toHaveBeenCalled();
  });
});

describe('scoped LAN registration', () => {
  it('checks authenticated scoped capability before checking permission/token and persists no token', async () => {
    const api = runtime();
    await registerDevice({ runtime: api, user });
    expect(mockRequest).toHaveBeenNthCalledWith(1, 'api/v1/push/capabilities', undefined, api);
    expect(mockRequest).toHaveBeenNthCalledWith(2, 'api/v1/push/token', {
      method: 'POST', body: { token: 'device-token-1', platform: 'android',
        installation_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', capabilities: ['team_workspace'], device_label: undefined,
        audience: 'lan-test', registration_id: registrationId, deployment_id: 'deployment-a' },
    }, api);
    expect(mockRequest.mock.invocationCallOrder[0]).toBeLessThan(mockGetToken.mock.invocationCallOrder[0]);
    expect(mockPermission.mock.invocationCallOrder[0]).toBeLessThan(mockGetToken.mock.invocationCallOrder[0]);
    expect(getPushSetupStatus()).toEqual({ state: 'ready', warning: null, code: null, runtimeId: 1 });
    expect(acceptsPushMessage(scopedMessage())).toBe(true);
    expect(mockStored).not.toContain('device-token-1');
    expect(JSON.parse(mockStored!)).toMatchObject({ registration_id: registrationId, credential_binding: 'activation-a', acknowledged: true });
  });

  it.each([
    ['unsupported', { data: { scope_version: 1, deployment_id: 'deployment-a', audiences: [] } }],
    ['wrong-version', { data: { scope_version: 2, deployment_id: 'deployment-a', audiences: ['lan-test'] } }],
    ['bad-deployment', { data: { scope_version: 1, deployment_id: 'a', audiences: ['lan-test'] } }],
    ['missing-data', {}],
  ])('never submits a token or falls back when capabilities are %s', async (_label, response) => {
    mockRequest.mockResolvedValue(response);
    await registerDevice({ runtime: runtime(), user });
    expect(mockGetToken).not.toHaveBeenCalled();
    expect(mockPermission).not.toHaveBeenCalled();
    expect(mockRequest).toHaveBeenCalledTimes(1);
    expect(getPushSetupStatus()).toMatchObject({ state: 'warning', code: 'unsupported', runtimeId: 1 });
  });

  it('shows a sanitized observable warning for capability errors without obtaining an FCM token', async () => {
    mockRequest.mockRejectedValue(new Error('503 http://old-server.test private credentials'));
    await registerDevice({ runtime: runtime(), user });
    expect(getPushSetupStatus()).toMatchObject({ state: 'warning', code: 'capability_check_failed' });
    expect(getPushSetupStatus().warning).not.toContain('old-server');
    expect(mockGetToken).not.toHaveBeenCalled();
  });

  it('does not register when runtime account or credential binding is unavailable', async () => {
    await registerDevice({ runtime: runtime({ credentialBinding: null }), user });
    expect(mockRequest).not.toHaveBeenCalled();
    expect(mockGetToken).not.toHaveBeenCalled();
    expect(getPushSetupStatus().code).toBe('binding_unavailable');
  });

  it('rejects mismatched response scope before accepting banners/routes', async () => {
    mockRequest.mockImplementation(async (path: string) => path.endsWith('capabilities')
      ? { data: { scope_version: 1, deployment_id: 'deployment-a', audiences: ['lan-test'] } }
      : { data: { registered: true, scope_version: 1, deployment_id: 'deployment-b', registration_id: registrationId } });
    await registerDevice({ runtime: runtime(), user });
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    expect(getPushSetupStatus().code).toBe('registration_mismatch');
    expect(mockCleanupRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'DELETE', body: { token: 'device-token-1', audience: 'lan-test', registration_id: registrationId },
    }, expect.any(Object));
  });

  it.each(['mismatch', 'outage'] as const)('does not trust a provisional scope after %s when setup retries or the app restarts', async failure => {
    const api = runtime();
    mockRequest.mockImplementation(async (path: string) => {
      if (path.endsWith('capabilities')) { return { data: { scope_version: 1, deployment_id: 'deployment-a', audiences: ['lan-test'] } }; }
      if (failure === 'outage') { throw new Error('temporary registration outage'); }
      return { data: { registered: true, scope_version: 1, deployment_id: 'deployment-b', registration_id: registrationId } };
    });
    await registerDevice({ runtime: api, user });
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    expect(JSON.parse(mockStored!)).toMatchObject({ acknowledged: false });

    for (const restart of [false, true]) {
      if (restart) {
        __resetPushRegistrationForTests();
        __resetPushScopeForTests();
      }
      const token = deferred<string>();
      mockGetToken.mockReturnValueOnce(token.promise);
      const pending = registerDevice({ runtime: restart ? runtime({ id: 8 }) : api, user });
      await tick();
      expect(acceptsPushMessage(scopedMessage())).toBe(false);
      expect(currentPushScope(currentPushOwner()!)).toBeNull();
      token.resolve('device-token-1');
      await pending;
      expect(acceptsPushMessage(scopedMessage())).toBe(false);
      expect(JSON.parse(mockStored!)).toMatchObject({ acknowledged: false });
    }
  });

  it('demotes an acknowledged scope after an incompatible renewal before the next cold restore', async () => {
    const api = runtime();
    await registerDevice({ runtime: api, user });
    mockRequest.mockImplementation(async (path: string) => path.endsWith('capabilities')
      ? { data: { scope_version: 1, deployment_id: 'deployment-a', audiences: ['lan-test'] } }
      : { data: { registered: true, scope_version: 1, deployment_id: 'deployment-b', registration_id: registrationId } });
    await registerDevice({ runtime: api, user });
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    expect(JSON.parse(mockStored!)).toMatchObject({ acknowledged: false });

    __resetPushRegistrationForTests();
    __resetPushScopeForTests();
    const token = deferred<string>();
    mockGetToken.mockReturnValueOnce(token.promise);
    const pending = registerDevice({ runtime: runtime({ id: 8 }), user });
    await tick();
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    token.resolve('device-token-1');
    await pending;
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
  });

  it('waits for acknowledged scope persistence before accepting a newly registered notice', async () => {
    const saved = deferred<void>();
    const saving = deferred<void>();
    (Keychain.setGenericPassword as jest.Mock).mockImplementation(async (_name: string, password: string) => {
      if ((JSON.parse(password) as { acknowledged: boolean }).acknowledged) {
        saving.resolve();
        await saved.promise;
      }
      mockStored = password;
      return { service: 'test' };
    });
    const pending = registerDevice({ runtime: runtime(), user });
    await saving.promise;
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    expect(JSON.parse(mockStored!)).toMatchObject({ acknowledged: false });

    saved.resolve();
    await pending;

    expect(acceptsPushMessage(scopedMessage())).toBe(true);
    expect(JSON.parse(mockStored!)).toMatchObject({ acknowledged: true });
  });

  it('keeps scope untrusted after acknowledgement persistence fails and on a later cold retry', async () => {
    (Keychain.setGenericPassword as jest.Mock).mockImplementationOnce(async (_name: string, password: string) => {
      mockStored = password;
      return { service: 'test' };
    }).mockResolvedValueOnce(false);
    await registerDevice({ runtime: runtime(), user });
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    expect(JSON.parse(mockStored!)).toMatchObject({ acknowledged: false });
    expect(getPushSetupStatus().code).toBe('registration_failed');

    __resetPushRegistrationForTests();
    __resetPushScopeForTests();
    const token = deferred<string>();
    mockGetToken.mockReturnValueOnce(token.promise);
    const pending = registerDevice({ runtime: runtime({ id: 8 }), user });
    await tick();
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    token.resolve('device-token-1');
    await pending;
    expect(acceptsPushMessage(scopedMessage())).toBe(true);
  });

  it('restores matching cold scope before token re-registration finishes or temporarily fails', async () => {
    await registerDevice({ runtime: runtime(), user });
    const saved = mockStored;
    __resetPushRegistrationForTests();
    __resetPushScopeForTests();
    mockStored = saved;
    const token = deferred<string>();
    mockGetToken.mockReturnValueOnce(token.promise);
    mockRequest.mockImplementation(async (path: string) => {
      if (path.endsWith('capabilities')) { return { data: { scope_version: 1, deployment_id: 'deployment-a', audiences: ['lan-test'] } }; }
      throw new Error('temporary registration outage');
    });
    const pending = registerDevice({ runtime: runtime({ id: 8 }), user });
    await tick();
    expect(acceptsPushMessage(scopedMessage())).toBe(true);
    expect(currentPushOwner()?.runtimeId).toBe(8);
    token.resolve('device-token-1');
    await pending;
    expect(acceptsPushMessage(scopedMessage())).toBe(true);
    expect(getPushSetupStatus().code).toBe('registration_failed');
  });

  it('creates a new binding after a server deployment reset and withdraws only the exact prior registration', async () => {
    const api = runtime();
    await registerDevice({ runtime: api, user });
    mockNonce.mockReturnValueOnce('22222222-2222-4222-8222-222222222222');
    mockRequest.mockImplementation(async (path: string, options?: { body?: Record<string, unknown> }) => path.endsWith('capabilities')
      ? { data: { scope_version: 1, deployment_id: 'deployment-b', audiences: ['lan-test'] } }
      : { data: { registered: true, scope_version: 1, deployment_id: 'deployment-b', registration_id: options?.body?.registration_id } });
    await registerDevice({ runtime: api, user });
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    const next = currentPushScope(currentPushOwner()!)!;
    expect(next).toMatchObject({ deployment_id: 'deployment-b', registration_id: '22222222-2222-4222-8222-222222222222' });
    expect(mockCleanupRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'DELETE', body: { token: 'device-token-1', audience: 'lan-test', registration_id: registrationId },
    }, expect.any(Object));
  });

  it('allocates a new registration on credential changes and same-ID switchback', async () => {
    const first = runtime();
    await registerDevice({ runtime: first, user });
    const remove = capturePushCleanup(first);
    mockCleanupRequest.mockRejectedValue(new Error('offline revoke'));
    await remove.clearScope;
    await remove();
    (first as { active: boolean }).active = false;
    mockNonce.mockReturnValueOnce('22222222-2222-4222-8222-222222222222');
    await registerDevice({ runtime: runtime({ id: 3, credentialBinding: 'activation-c' }), user });
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    expect(acceptsPushMessage(scopedMessage('22222222-2222-4222-8222-222222222222'))).toBe(true);
    expect(mockRequest.mock.calls.filter(call => call[0].endsWith('token')).at(-1)?.[1].body.registration_id)
      .toBe('22222222-2222-4222-8222-222222222222');
  });

  it('treats an absent native push entry as already cleared instead of requiring reset success on first setup', async () => {
    (Keychain.resetGenericPassword as jest.Mock).mockResolvedValue(false);
    await registerDevice({ runtime: runtime(), user });
    expect(getPushSetupStatus().state).toBe('ready');
    expect(Keychain.resetGenericPassword).not.toHaveBeenCalled();
  });

  it('does not send FCM token after secure scope save fails', async () => {
    (Keychain.setGenericPassword as jest.Mock).mockResolvedValueOnce(false);
    await registerDevice({ runtime: runtime(), user });
    expect(mockGetToken).not.toHaveBeenCalled();
    expect(mockRequest).toHaveBeenCalledTimes(1);
    expect(getPushSetupStatus().code).toBe('storage_unavailable');
  });

  it('captures old-origin scoped withdrawal and clears memory before native/network awaits', async () => {
    const api = runtime();
    await registerDevice({ runtime: api, user });
    const deleting = deferred<boolean>();
    (Keychain.resetGenericPassword as jest.Mock).mockReturnValueOnce(deleting.promise);
    const cleanup = capturePushCleanup(api);
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    expect(currentPushOwner()).toBeNull();
    expect(getPushSetupStatus().state).toBe('idle');
    (api as { active: boolean }).active = false;
    (api as { sessionVersion: number }).sessionVersion += 1;
    const revoke = cleanup();
    expect(mockCleanupRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'DELETE', body: { token: 'device-token-1', audience: 'lan-test', registration_id: registrationId },
    }, { runtimeId: 1, apiBaseUrl: 'http://172.16.153.237:8080', sessionVersion: 1 });
    await revoke;
    deleting.resolve(true);
    await cleanup.clearScope;
  });

  it('never restores a retired saved binding after native deletion failed and the same runtime/account signs in again', async () => {
    const api = runtime();
    await registerDevice({ runtime: api, user });
    (Keychain.resetGenericPassword as jest.Mock).mockRejectedValueOnce(new Error('native deletion failed'));
    const cleanup = capturePushCleanup(api);
    await expect(cleanup.clearScope).rejects.toThrow('native deletion failed');
    (api as { sessionVersion: number }).sessionVersion += 1;
    mockNonce.mockReturnValueOnce('22222222-2222-4222-8222-222222222222');
    await registerDevice({ runtime: api, user });
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    expect(acceptsPushMessage(scopedMessage('22222222-2222-4222-8222-222222222222'))).toBe(true);
    expect(currentPushScope(currentPushOwner()!)?.registration_id).toBe('22222222-2222-4222-8222-222222222222');
  });

  it('exposes an explicit false native deletion result as an unverified wipe', async () => {
    const api = runtime();
    await registerDevice({ runtime: api, user });
    (Keychain.resetGenericPassword as jest.Mock).mockResolvedValueOnce(false);
    const cleanup = capturePushCleanup(api);
    await expect(cleanup.clearScope).rejects.toThrow('Secure push binding could not be removed');
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
  });

  it('exposes secure deletion rejection instead of silently claiming the binding was wiped', async () => {
    const api = runtime();
    await registerDevice({ runtime: api, user });
    (Keychain.resetGenericPassword as jest.Mock).mockRejectedValueOnce(new Error('native deletion failed'));
    const cleanup = capturePushCleanup(api);
    await expect(cleanup.clearScope).rejects.toThrow('native deletion failed');
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    await cleanup();
  });
});

describe('screen-triggered registration recovery', () => {
  it('keeps the session owner active when the Settings screen unmounts during a retry', async () => {
    const api = runtime();
    let authenticated = true;
    const options = { runtime: api, user, isCurrent: () => authenticated };
    await registerDevice(options);
    const owner = currentPushOwner();
    const stop = watchPushToken(options);
    const token = deferred<string>();
    mockGetToken.mockReturnValueOnce(token.promise);
    let screenMounted = true;
    const pending = retryPushSetup(api, user, () => screenMounted);
    await tick();
    screenMounted = false;
    token.resolve('retry-token');
    await pending;
    expect(currentPushOwner()).toBe(owner);
    expect(acceptsPushMessage(scopedMessage())).toBe(true);
    mockRequest.mockClear();
    mockTokenHandler!('rotated-after-settings');
    await tick();
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', expect.objectContaining({
      body: expect.objectContaining({ token: 'rotated-after-settings' }),
    }), api);
    authenticated = false;
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    stop();
  });

  it('does not create or retarget registration from a stale, unsigned, or wrong-account screen', async () => {
    const api = runtime();
    await retryPushSetup(api, user, () => true);
    expect(mockPermission).not.toHaveBeenCalled();
    await registerDevice({ runtime: api, user });
    const owner = currentPushOwner();
    mockRequest.mockClear(); mockPermission.mockClear();
    await retryPushSetup(api, user, () => false);
    await retryPushSetup(api, { tenant_id: 7, id: 99 }, () => true);
    await retryPushSetup(runtime({ id: 9 }), user, () => true);
    expect(mockRequest).not.toHaveBeenCalled();
    expect(mockPermission).not.toHaveBeenCalled();
    expect(currentPushOwner()).toBe(owner);
  });
});

describe('delayed callbacks and token rotation', () => {
  it.each(['permission', 'token', 'installation'] as const)('drops stale %s completions after same-runtime auth changes', async stage => {
    const api = runtime();
    const delay = deferred<string>();
    if (stage === 'permission') { mockPermission.mockReturnValueOnce(delay.promise); }
    if (stage === 'token') { mockGetToken.mockReturnValueOnce(delay.promise); }
    if (stage === 'installation') { mockInstallation.mockReturnValueOnce(delay.promise); }
    const pending = registerDevice({ runtime: api, user });
    await tick();
    const selected = currentPushOwner();
    (api as { sessionVersion: number }).sessionVersion += 1;
    delay.resolve(stage === 'permission' ? 'granted' : stage === 'token' ? 'old-fcm' : 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    await pending;
    expect(mockRequest.mock.calls.filter(call => call[0] === 'api/v1/push/token')).toHaveLength(0);
    expect(selected && currentPushScope(selected)).toBeNull();
  });

  it('drops old capability completion after a new runtime is selected', async () => {
    const old = runtime();
    const capability = deferred<unknown>();
    mockRequest.mockReturnValueOnce(capability.promise);
    const pending = registerDevice({ runtime: old, user });
    const cleanup = capturePushCleanup(old);
    (old as { active: boolean }).active = false;
    await cleanup.clearScope;
    await registerDevice({ runtime: runtime({ id: 2, profileId: 'home', apiBaseUrl: 'http://192.168.1.11:8080', credentialBinding: 'activation-b' }), user });
    const current = getPushSetupStatus();
    capability.resolve({ data: { scope_version: 1, deployment_id: 'old-deployment', audiences: ['lan-test'] } });
    await pending;
    expect(getPushSetupStatus()).toBe(current);
    expect(mockRequest.mock.calls.filter(call => call[0].endsWith('token'))).toHaveLength(1);
  });

  it('withdraws a registration that finishes after retirement using only its captured old scope', async () => {
    const api = runtime();
    const response = deferred<unknown>();
    const submitted = deferred<void>();
    mockRequest.mockImplementation(async (path: string) => {
      if (path.endsWith('capabilities')) { return { data: { scope_version: 1, deployment_id: 'deployment-a', audiences: ['lan-test'] } }; }
      submitted.resolve();
      return response.promise;
    });
    const pending = registerDevice({ runtime: api, user });
    await submitted.promise;
    const cleanup = capturePushCleanup(api);
    (api as { active: boolean }).active = false;
    await cleanup();
    await cleanup.clearScope;
    response.resolve({ data: { registered: true, scope_version: 1, deployment_id: 'deployment-a', registration_id: registrationId } });
    await pending;
    expect(mockCleanupRequest).toHaveBeenCalledTimes(2); // Retry old exact binding after its delayed POST completion.
    expect(mockCleanupRequest.mock.calls.every(call => call[1].body.registration_id === registrationId)).toBe(true);
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    expect(getPushSetupStatus().state).toBe('idle');
  });

  it('drops delayed permission work on hook pause but keeps closed-app registration and persisted scope', async () => {
    const api = runtime();
    await registerDevice({ runtime: api, user });
    const stored = mockStored;
    const permission = deferred<string>();
    mockPermission.mockReturnValueOnce(permission.promise);
    const pending = registerDevice({ runtime: api, user });
    await tick();
    pausePushSession(api);
    permission.resolve('granted');
    await pending;
    expect(mockStored).toBe(stored);
    expect(mockCleanupRequest).not.toHaveBeenCalled();
    expect(acceptsPushMessage(scopedMessage())).toBe(false);
    await registerDevice({ runtime: api, user });
    expect(acceptsPushMessage(scopedMessage())).toBe(true);
    expect(mockStored).toBe(stored);
  });

  it('keeps refreshed tokens bound to the active scope and ignores retired token listeners', async () => {
    const api = runtime();
    await registerDevice({ runtime: api, user });
    const stop = watchPushToken({ runtime: api, user });
    const oldHandler = mockTokenHandler!;
    mockRequest.mockClear();
    oldHandler('device-token-2');
    await tick();
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', expect.objectContaining({
      method: 'POST', body: expect.objectContaining({ token: 'device-token-2', audience: 'lan-test', registration_id: registrationId }),
    }), api);
    const cleanup = capturePushCleanup(api);
    oldHandler('retired-token');
    await tick();
    expect(mockRequest.mock.calls.filter(call => call[1]?.body?.token === 'retired-token')).toHaveLength(0);
    await cleanup.clearScope;
    stop();
  });

  it('does not reuse delayed native permission work after a hook is detached and reattached', async () => {
    const api = runtime();
    await registerDevice({ runtime: api, user });
    const stored = mockStored;
    const stalePermission = deferred<string>();
    const prompted = deferred<void>();
    mockPermission.mockImplementationOnce(() => { prompted.resolve(); return stalePermission.promise; });
    const oldPending = registerDevice({ runtime: api, user });
    await prompted.promise;
    const oldOwner = currentPushOwner();
    pausePushSession(api);
    await registerDevice({ runtime: api, user });
    expect(currentPushOwner()).not.toBe(oldOwner);
    expect(mockStored).toBe(stored);
    expect(acceptsPushMessage(scopedMessage())).toBe(true);
    mockRequest.mockClear();
    stalePermission.resolve('granted');
    await oldPending;
    expect(mockRequest).not.toHaveBeenCalled();
    expect(mockCleanupRequest).not.toHaveBeenCalled();
  });

  it('never uses a refresh callback to bypass unsupported capability or denied permission', async () => {
    const api = runtime();
    mockRequest.mockResolvedValue({ data: { scope_version: 1, deployment_id: 'deployment-a', audiences: [] } });
    await registerDevice({ runtime: api, user });
    const stop = watchPushToken({ runtime: api, user });
    mockTokenHandler!('must-not-submit');
    await tick();
    expect(mockRequest.mock.calls.filter(call => call[0].endsWith('token'))).toHaveLength(0);
    stop();
  });
});
