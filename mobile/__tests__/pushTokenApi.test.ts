import { request } from '../src/api/client';
import { registerDeviceToken, revokeDeviceToken } from '../src/api/endpoints/push';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/api/client', () => ({ ...jest.requireActual('../src/api/client'), request: jest.fn() }));

const mockRequest = request as jest.Mock;

beforeEach(() => {
  mockRequest.mockReset();
});

describe('device token registration', () => {
  it('posts the token and the platform, and never expects the token back', async () => {
    mockRequest.mockResolvedValue({ data: { registered: true } });
    await registerDeviceToken('device-token-1', 'android', 'Pixel 8');
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'POST',
      body: { token: 'device-token-1', platform: 'android', device_label: 'Pixel 8' },
    });
  });

  it('omits an absent device label instead of sending an empty one', async () => {
    mockRequest.mockResolvedValue({ data: { registered: true } });
    await registerDeviceToken('device-token-2', 'ios');
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'POST',
      body: { token: 'device-token-2', platform: 'ios', device_label: undefined },
    });
  });

  it('withdraws a token with the same identity contract', async () => {
    mockRequest.mockResolvedValue(undefined);
    await revokeDeviceToken('device-token-3');
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'DELETE',
      body: { token: 'device-token-3' },
    });
  });
});
