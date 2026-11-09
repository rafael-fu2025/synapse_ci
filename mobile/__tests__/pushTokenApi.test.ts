import { request } from '../src/api/client';
import { createPushApi, pushCapabilities, registerDeviceToken, revokeDeviceToken } from '../src/api/endpoints/push';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/api/client', () => {
  const mockRequest = jest.fn();
  return {
    request: mockRequest,
    bindApiClient: (runtime: unknown) => ({
      runtime,
      request: (path: string, options?: unknown) => mockRequest(path, options, runtime),
    }),
  };
});

const mockRequest = request as jest.Mock;
const runtime = { id: 17, apiBaseUrl: 'http://172.16.153.237:8080', profileId: 'university' as const,
  credentialBinding: 'activation-a', lanTest: true, active: true, sessionVersion: 1 };
const scope = { audience: 'lan-test' as const, registration_id: '11111111-1111-4111-8111-111111111111', deployment_id: 'deployment-a' };

beforeEach(() => {
  mockRequest.mockReset();
});

describe('device token registration', () => {
  it('posts the token and the platform, and never expects the token back', async () => {
    mockRequest.mockResolvedValue({ data: { registered: true } });
    await registerDeviceToken('device-token-1', 'android', '11111111-1111-4111-8111-111111111111', ['team_workspace'], 'Pixel 8');
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'POST',
      body: { token: 'device-token-1', platform: 'android', installation_id: '11111111-1111-4111-8111-111111111111', capabilities: ['team_workspace'], device_label: 'Pixel 8' },
    });
  });

  it('omits an absent device label instead of sending an empty one', async () => {
    mockRequest.mockResolvedValue({ data: { registered: true } });
    await registerDeviceToken('device-token-2', 'ios', '22222222-2222-4222-8222-222222222222', ['team_workspace']);
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'POST',
      body: { token: 'device-token-2', platform: 'ios', installation_id: '22222222-2222-4222-8222-222222222222', capabilities: ['team_workspace'], device_label: undefined },
    });
  });

  it('withdraws a legacy token with the same identity contract', async () => {
    mockRequest.mockResolvedValue(undefined);
    await revokeDeviceToken('device-token-3');
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'DELETE', body: { token: 'device-token-3' },
    });
  });

  it('checks LAN capability through the explicit selected runtime', async () => {
    mockRequest.mockResolvedValue({ data: { scope_version: 1, deployment_id: 'deployment-a', audiences: ['lan-test'] } });
    await pushCapabilities(runtime);
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/capabilities', {}, runtime);
  });

  it('sends scoped LAN registration with audience/id and optional deployment validation', async () => {
    await registerDeviceToken('lan-token', 'android', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', ['team_workspace'], undefined, scope, runtime);
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'POST', body: { token: 'lan-token', platform: 'android', installation_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        capabilities: ['team_workspace'], device_label: undefined, ...scope },
    }, runtime);
  });

  it('revokes only the exact LAN registration and never includes a new deployment or account token', async () => {
    await revokeDeviceToken('lan-token', scope, runtime);
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'DELETE', body: { token: 'lan-token', audience: 'lan-test', registration_id: scope.registration_id },
    }, runtime);
  });

  it('captures a runtime-bound client for all registration operations', async () => {
    const api = createPushApi(runtime);
    await api.capabilities();
    await api.register('lan-token', 'ios', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', ['team_workspace'], scope);
    await api.revoke('lan-token', scope);
    expect(mockRequest.mock.calls.every(call => call[2] === runtime)).toBe(true);
    expect(mockRequest).toHaveBeenCalledTimes(3);
  });
});
