import { requestPushPermission } from '../src/lib/push/fcm';

const mockCheck = jest.fn();
const mockRequest = jest.fn();
jest.mock('react-native-permissions', () => ({
  checkNotifications: () => mockCheck(), requestNotifications: () => mockRequest(),
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
