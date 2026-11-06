import { __resetNavigationIntentForTests, pushNavigationIntent, queueNavigationIntent, takePendingNavigationIntent, workUnitForIntent } from '../src/features/workspace/navigationIntent';
import { notificationDestination } from '../src/features/notifications/targets';
import type { MeResponse } from '../src/api/types';

const profile: MeResponse = {
  user: { id: 7, person_id: 8, tenant_id: 3, username: 'staff', email: null, name: 'Staff', avatar_initials: 'ST', identifier: 'staff', role: 'clinic_staff', is_teaching: false, permissions: [] },
  entitlements: {
    portal: true,
    staff_queue_units: [],
    personal: { enabled: true },
    work: { enabled: true, units: [{ unit: 'clinic', label: 'Clinic', can_manage: false, features: { team_board: true, my_tasks: true, team_chat: true, team_calendar: true } }] },
  },
  photo_url: 'api/v1/me/photo',
};

beforeEach(() => __resetNavigationIntentForTests());

test('parses privacy-safe Team push targets and rejects malformed units', () => {
  expect(pushNavigationIntent({ data: { type: 'team_task', unit: 'clinic', id: '11' } })).toEqual({ kind: 'team_task', unit: 'clinic', id: 11 });
  expect(pushNavigationIntent({ data: { type: 'team_event', unit: 'guidance', id: '12' } })).toEqual({ kind: 'team_event', unit: 'guidance', id: 12 });
  expect(pushNavigationIntent({ data: { type: 'team_chat_message', unit: 'facilities', id: '13' } })).toEqual({ kind: 'team_chat_message', unit: 'facilities', id: 13 });
  expect(pushNavigationIntent({ data: { type: 'team_task', unit: 'Clinic', id: '11' } })).toBeNull();
  expect(pushNavigationIntent({ data: { type: 'team_task', unit: 'clinic', id: '0' } })).toBeNull();
});

test('accepts only currently entitled Team units', () => {
  expect(workUnitForIntent(profile, { kind: 'team_task', unit: 'clinic', id: 1 })).toBe('clinic');
  expect(workUnitForIntent(profile, { kind: 'team_task', unit: 'guidance', id: 1 })).toBeNull();
  expect(workUnitForIntent(null, { kind: 'team_task', unit: 'clinic', id: 1 })).toBeNull();
});

test('queues one cold-start intent in memory and consumes it once', () => {
  queueNavigationIntent({ kind: 'team_event', unit: 'clinic', id: 4 });
  expect(takePendingNavigationIntent()).toEqual({ kind: 'team_event', unit: 'clinic', id: 4 });
  expect(takePendingNavigationIntent()).toBeNull();
});

test('validates native notification Team targets', () => {
  const base = { id: 1, title: 'Team task updated', message: 'Open Teams.', created_at: null, read_at: null };
  expect(notificationDestination({ ...base, target: { kind: 'team_task', unit: 'clinic', id: 5 } })).toEqual({ kind: 'team_task', unit: 'clinic', id: 5 });
  expect(notificationDestination({ ...base, target: { kind: 'team_event', unit: 'guidance', id: 6 } })).toEqual({ kind: 'team_event', unit: 'guidance', id: 6 });
  expect(notificationDestination({ ...base, target: { kind: 'team_chat_message', unit: 'facilities', id: 7 } })).toEqual({ kind: 'team_chat_message', unit: 'facilities', id: 7 });
  expect(notificationDestination({ ...base, target: { kind: 'team_task', unit: 'clinic', id: 0 } })).toBeNull();
});
