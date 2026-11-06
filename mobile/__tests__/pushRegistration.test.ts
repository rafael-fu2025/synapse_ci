import { Platform } from 'react-native';
import { request } from '../src/api/client';
import { registerDevice, revokeDevice } from '../src/lib/push/registration';

const mockGetToken = jest.fn();
const mockOnTokenRefresh = jest.fn();
const mockOnMessage = jest.fn();
const mockCheck = jest.fn();
const mockRequestPermission = jest.fn();

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/api/client', () => ({ ...jest.requireActual('../src/api/client'), request: jest.fn() }));
// The v26 messaging API is modular: `getMessaging()` returns the instance and the
// free functions take it as their first argument.
jest.mock('@react-native-firebase/messaging', () => ({
  __esModule: true,
  getMessaging: () => ({}),
  getToken: (...args: unknown[]) => mockGetToken(...args),
  onTokenRefresh: (...args: unknown[]) => mockOnTokenRefresh(...args),
  onMessage: (...args: unknown[]) => mockOnMessage(...args),
}));
// react-native-permissions keeps notifications out of PERMISSIONS: the Android
// POST_NOTIFICATIONS runtime permission only exists from API 33.
jest.mock('react-native-permissions', () => ({
  __esModule: true,
  checkNotifications: () => mockCheck(),
  requestNotifications: () => mockRequestPermission(),
}));

const mockRequest = request as jest.Mock;
const platform = Platform.OS === 'ios' ? 'ios' : 'android';

beforeEach(() => {
  mockRequest.mockReset();
  mockGetToken.mockReset();
  mockCheck.mockReset().mockResolvedValue({ status: 'granted' });
  mockRequestPermission.mockReset().mockResolvedValue({ status: 'granted' });
  mockGetToken.mockResolvedValue('device-token-1');
});

describe('device registration', () => {
  it('registers the device once the permission is granted', async () => {
    await registerDevice();
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'POST',
      body: { token: 'device-token-1', platform, device_label: undefined },
    });
  });

  it('never registers a device whose permission was refused', async () => {
    mockCheck.mockResolvedValue({ status: 'denied' });
    mockRequestPermission.mockResolvedValue({ status: 'denied' });
    await registerDevice();
    expect(mockGetToken).not.toHaveBeenCalled();
    expect(mockRequest).not.toHaveBeenCalled();
  });

  it('never registers a device the platform cannot give a token for', async () => {
    mockGetToken.mockResolvedValue('');
    await registerDevice();
    expect(mockRequest).not.toHaveBeenCalled();
  });

  it('survives a refused registration', async () => {
    mockRequest.mockRejectedValue(new Error('offline'));
    await expect(registerDevice()).resolves.toBeUndefined();
  });
});

describe('device withdrawal', () => {
  it('withdraws exactly the token this session registered', async () => {
    await registerDevice();
    mockRequest.mockClear();
    await revokeDevice();
    expect(mockRequest).toHaveBeenCalledWith('api/v1/push/token', {
      method: 'DELETE',
      body: { token: 'device-token-1' },
    });
  });

  it('never withdraws a device this session did not register', async () => {
    await revokeDevice();
    expect(mockRequest).not.toHaveBeenCalled();
  });

  it('withdraws only once', async () => {
    await registerDevice();
    mockRequest.mockClear();
    await revokeDevice();
    await revokeDevice();
    expect(mockRequest).toHaveBeenCalledTimes(1);
  });

  it('survives a refused withdrawal', async () => {
    await registerDevice();
    mockRequest.mockRejectedValue(new Error('offline'));
    await expect(revokeDevice()).resolves.toBeUndefined();
  });
});
