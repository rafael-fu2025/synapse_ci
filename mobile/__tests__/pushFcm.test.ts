import { checkPushPermission, openPushSettings, requestPushPermission } from '../src/lib/push/fcm';

const mockCheck = jest.fn();
const mockRequest = jest.fn();
const mockSettings = jest.fn();
jest.mock('react-native-permissions', () => ({
  checkNotifications: () => mockCheck(), requestNotifications: () => mockRequest(),
  openSettings: (...args: unknown[]) => mockSettings(...args),
}));

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => { resolve = yes; });
  return { promise, resolve };
}

beforeEach(() => {
  jest.clearAllMocks();
  mockCheck.mockResolvedValue({ status: 'granted' });
  mockRequest.mockResolvedValue({ status: 'granted' });
  mockSettings.mockResolvedValue(undefined);
});

it('never launches a permission prompt after a delayed check belongs to a retired owner', async () => {
  let current = true;
  const check = deferred<{ status: string }>();
  mockCheck.mockReturnValueOnce(check.promise);
  const pending = requestPushPermission(() => current);
  current = false;
  check.resolve({ status: 'denied' });
  await expect(pending).resolves.toBe('unavailable');
  expect(mockRequest).not.toHaveBeenCalled();
});

it('drops a prompt completion after its auth/runtime owner changes', async () => {
  let current = true;
  const prompt = deferred<{ status: string }>();
  mockCheck.mockResolvedValue({ status: 'denied' });
  mockRequest.mockReturnValueOnce(prompt.promise);
  const pending = requestPushPermission(() => current);
  await Promise.resolve();
  current = false;
  prompt.resolve({ status: 'granted' });
  await expect(pending).resolves.toBe('unavailable');
});

it('does not inspect native permission at all for an already retired owner', async () => {
  await expect(requestPushPermission(() => false)).resolves.toBe('unavailable');
  expect(mockCheck).not.toHaveBeenCalled();
  expect(mockRequest).not.toHaveBeenCalled();
});

it('preserves legacy optional-guard permission behavior', async () => {
  await expect(requestPushPermission()).resolves.toBe('granted');
  mockCheck.mockResolvedValue({ status: 'denied' });
  mockRequest.mockResolvedValue({ status: 'blocked' });
  await expect(requestPushPermission()).resolves.toBe('blocked');
});

it.each(['granted', 'blocked', 'unavailable'] as const)('does not request native permission when the check is %s', async status => {
  mockCheck.mockResolvedValue({ status });
  await expect(requestPushPermission()).resolves.toBe(status);
  expect(mockRequest).not.toHaveBeenCalled();
});

it('preserves unavailable instead of reporting a native failure as user denial', async () => {
  mockCheck.mockResolvedValue({ status: 'denied' });
  mockRequest.mockResolvedValue({ status: 'unavailable' });
  await expect(requestPushPermission()).resolves.toBe('unavailable');
  mockCheck.mockRejectedValue(new Error('native unavailable'));
  await expect(checkPushPermission()).resolves.toBe('unavailable');
});

it('coalesces concurrent requests and drops only the retired callers completion', async () => {
  const check = deferred<{ status: string }>();
  mockCheck.mockReturnValueOnce(check.promise);
  let oldCurrent = true;
  const old = requestPushPermission(() => oldCurrent);
  const next = requestPushPermission(() => true);
  oldCurrent = false;
  check.resolve({ status: 'denied' });
  await expect(Promise.all([old, next])).resolves.toEqual(['unavailable', 'granted']);
  expect(mockCheck).toHaveBeenCalledTimes(1);
  expect(mockRequest).toHaveBeenCalledTimes(1);
});

it('reports the request start only when the native prompt is actually requested', async () => {
  const started = jest.fn();
  await requestPushPermission(() => true, started);
  expect(started).not.toHaveBeenCalled();
  mockCheck.mockResolvedValue({ status: 'denied' });
  await requestPushPermission(() => true, started);
  expect(started).toHaveBeenCalledTimes(1);
});

it('checks permission without ever requesting and opens notification-specific settings explicitly', async () => {
  mockCheck.mockResolvedValue({ status: 'denied' });
  await expect(checkPushPermission()).resolves.toBe('denied');
  expect(mockRequest).not.toHaveBeenCalled();
  await expect(openPushSettings()).resolves.toBe(true);
  expect(mockSettings).toHaveBeenCalledWith('notifications');
  mockSettings.mockRejectedValue(new Error('native launch failed'));
  await expect(openPushSettings()).resolves.toBe(false);
});
