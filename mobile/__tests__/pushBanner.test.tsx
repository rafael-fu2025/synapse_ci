import React from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { usePushNotice } from '../src/components/PushBanner';
import {
  activatePushOwner, makePushScope, publishPushScope, retirePushOwner, __resetPushScopeForTests,
} from '../src/lib/push/scope';
import type { PushMessage } from '../src/lib/push/fcm';

let mockMessageHandler: ((message: PushMessage) => void) | null = null;
jest.mock('../src/lib/config', () => ({ config: { lanTest: false } }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: { mode: 'light' } }) }));
jest.mock('../src/lib/push/fcm', () => ({
  onPushMessage: (handler: (message: PushMessage) => void) => {
    mockMessageHandler = handler; return () => { mockMessageHandler = null; };
  },
}));
jest.mock('react-native-keychain', () => ({
  getGenericPassword: jest.fn().mockResolvedValue(false), resetGenericPassword: jest.fn().mockResolvedValue(true),
}));
jest.mock('lucide-react-native', () => ({ BellRing: () => null }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: ({ children }: { children: React.ReactNode }) => children }));

const registrationId = '11111111-1111-4111-8111-111111111111';
const runtime = { id: 1, apiBaseUrl: 'http://172.16.153.237:8080', profileId: 'university', credentialBinding: 'activation-a',
  lanTest: true, active: true, sessionVersion: 1 };
const user = { tenant_id: 7, id: 31 };
let notice: ReturnType<typeof usePushNotice>;
let tree: TestRenderer.ReactTestRenderer | null;

function Probe() { notice = usePushNotice(); return null; }
function message(overrides: Partial<NonNullable<PushMessage['data']>> = {}): PushMessage {
  return { notification: { title: 'Private old patient title', body: 'Private old appointment body' },
    data: { scope_version: '1', deployment_id: 'deployment-a', registration_id: registrationId, ...overrides } as Record<string, string> };
}

beforeEach(() => {
  __resetPushScopeForTests();
  mockMessageHandler = null;
  tree = null;
  notice = null;
  jest.useFakeTimers();
});
afterEach(async () => { await act(async () => { tree?.unmount(); }); jest.useRealTimers(); });

it('never shows mismatched/unscoped LAN notices and redacts accepted foreground text', async () => {
  const owner = activatePushOwner({ runtime, user });
  publishPushScope(owner, makePushScope(owner, 'deployment-a', registrationId));
  await act(async () => { tree = TestRenderer.create(<Probe />); });
  await act(async () => { mockMessageHandler!(message({ deployment_id: 'deployment-b' })); });
  expect(notice).toBeNull();
  await act(async () => { mockMessageHandler!({ notification: { title: 'Private', body: 'Private' } }); });
  expect(notice).toBeNull();
  await act(async () => { mockMessageHandler!(message()); });
  expect(notice).toEqual({ title: 'Synapse LAN Test', body: 'Open the app to check for updates' });
});

it('hides an accepted old notice as soon as its scope retires and ignores later old callbacks', async () => {
  const owner = activatePushOwner({ runtime, user });
  publishPushScope(owner, makePushScope(owner, 'deployment-a', registrationId));
  await act(async () => { tree = TestRenderer.create(<Probe />); });
  await act(async () => { mockMessageHandler!(message()); });
  expect(notice).not.toBeNull();
  let clearing!: Promise<void>;
  await act(async () => { clearing = retirePushOwner(owner); });
  expect(notice).toBeNull();
  await clearing;
  await act(async () => {
    const next = activatePushOwner({ runtime: { ...runtime, id: 2, credentialBinding: 'activation-b' }, user });
    publishPushScope(next, makePushScope(next, 'deployment-a', '22222222-2222-4222-8222-222222222222'));
  });
  await act(async () => { mockMessageHandler!(message()); });
  expect(notice).toBeNull();
});

it('expires foreground notices and preserves legacy phone text outside a LAN runtime', async () => {
  await act(async () => { tree = TestRenderer.create(<Probe />); });
  await act(async () => { mockMessageHandler!({ notification: { title: 'Portal notification', body: 'A normal notice' } }); });
  expect(notice).toEqual({ title: 'Portal notification', body: 'A normal notice' });
  await act(async () => { jest.advanceTimersByTime(5_000); });
  expect(notice).toBeNull();
});
