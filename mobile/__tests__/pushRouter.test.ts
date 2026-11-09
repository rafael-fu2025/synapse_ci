import { routePushTap } from '../src/lib/push/router';
import { queueNavigationIntent } from '../src/features/workspace/navigationIntent';
import { activatePushOwner, makePushScope, publishPushScope, retirePushOwner, __resetPushScopeForTests } from '../src/lib/push/scope';

jest.mock('../src/lib/config', () => ({ config: { lanTest: false } }));
jest.mock('react-native-keychain', () => ({
  getGenericPassword: jest.fn().mockResolvedValue(false), resetGenericPassword: jest.fn().mockResolvedValue(true),
}));
jest.mock('../src/features/workspace/navigationIntent', () => ({
  ...jest.requireActual('../src/features/workspace/navigationIntent'), queueNavigationIntent: jest.fn(),
}));

const mockQueue = queueNavigationIntent as jest.Mock;
const registrationId = '11111111-1111-4111-8111-111111111111';
const runtime = { id: 17, apiBaseUrl: 'http://172.16.153.237:8080', profileId: 'university',
  credentialBinding: 'activation-a', lanTest: true, active: true, sessionVersion: 1 };
const user = { tenant_id: 7, id: 31 };

beforeEach(() => { jest.clearAllMocks(); __resetPushScopeForTests(); });

it.each([
  ['appointment_confirmed', '314', { kind: 'appointment', id: 314 }],
  ['announcement_published', '41', { kind: 'announcement', id: 41 }],
  ['survey_published', '9', { kind: 'survey', id: 9 }],
  ['team_task', '6', { kind: 'team_task', id: 6, unit: 'clinic' }],
  ['team_event', '7', { kind: 'team_event', id: 7, unit: 'clinic' }],
  ['team_chat_message', '8', { kind: 'team_chat_message', id: 8, unit: 'clinic' }],
])('queues legacy %s through the entitlement-aware navigation gate', (type, id, intent) => {
  routePushTap({ data: { type, id, unit: 'clinic' } });
  expect(mockQueue).toHaveBeenCalledWith(intent, undefined);
});

it('ignores unknown types, malformed/nonpositive ids, missing data and null messages', () => {
  routePushTap({ data: { type: 'unknown_type', id: '1' } });
  routePushTap({ data: { type: 'appointment_confirmed', id: 'abc' } });
  routePushTap({ data: { type: 'appointment_confirmed', id: '0' } });
  routePushTap({ data: { type: 'appointment_confirmed' } });
  routePushTap({ data: { type: 'team_task', id: '1', unit: 'unknown' } });
  routePushTap(null);
  expect(mockQueue).not.toHaveBeenCalled();
});

it('checks scope before queuing any personal or team hint and passes immutable runtime id', () => {
  const owner = activatePushOwner({ runtime, user });
  publishPushScope(owner, makePushScope(owner, 'deployment-a', registrationId));
  const data = { type: 'appointment_confirmed', id: '31', scope_version: '1', deployment_id: 'deployment-a', registration_id: registrationId };
  routePushTap({ data }, owner);
  expect(mockQueue).toHaveBeenCalledWith({ kind: 'appointment', id: 31 }, 17);
  mockQueue.mockClear();
  routePushTap({ data: { ...data, registration_id: '22222222-2222-4222-8222-222222222222' } }, owner);
  routePushTap({ data: { ...data, deployment_id: 'deployment-b' } }, owner);
  routePushTap({ data: { ...data, scope_version: '2' } }, owner);
  routePushTap({ data: { type: 'team_task', id: '31', unit: 'clinic' } }, owner);
  expect(mockQueue).not.toHaveBeenCalled();
});

it('queues normal-mode context pushes with the selected numeric runtime owner too', () => {
  const owner = activatePushOwner({ runtime: { ...runtime, lanTest: false }, user });
  routePushTap({ data: { type: 'announcement_published', id: '31' } }, owner);
  expect(mockQueue).toHaveBeenCalledWith({ kind: 'announcement', id: 31 }, 17);
});

it('never queues a retired account hint, including when a new owner has identical resource ids', async () => {
  const old = activatePushOwner({ runtime, user });
  publishPushScope(old, makePushScope(old, 'deployment-a', registrationId));
  const oldMessage = { data: { type: 'appointment_confirmed', id: '31', scope_version: '1', deployment_id: 'deployment-a', registration_id: registrationId } };
  await retirePushOwner(old);
  const next = activatePushOwner({ runtime: { ...runtime, id: 18, credentialBinding: 'activation-b' }, user });
  publishPushScope(next, makePushScope(next, 'deployment-a', '22222222-2222-4222-8222-222222222222'));
  routePushTap(oldMessage, old);
  routePushTap(oldMessage);
  expect(mockQueue).not.toHaveBeenCalled();
});
