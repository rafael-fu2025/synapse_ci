import { notificationDestination } from '../src/features/notifications/targets';
import type { NotificationItem } from '../src/api/endpoints/notifications';

function item(target: unknown): NotificationItem {
  return {
    id: 81,
    title: 'New announcement',
    message: 'A campus announcement is available in your portal.',
    created_at: '2026-10-26T08:30:00Z',
    read_at: null,
    target: target as NotificationItem['target'],
  };
}

describe('notification deep-link targets', () => {
  it('maps every kind the server can send to its own destination', () => {
    expect(notificationDestination(item({ kind: 'appointment', id: 42 }))).toEqual({ kind: 'appointment', id: 42 });
    expect(notificationDestination(item({ kind: 'announcement', id: 41 }))).toEqual({ kind: 'announcement', id: 41 });
    expect(notificationDestination(item({ kind: 'survey', id: 7 }))).toEqual({ kind: 'survey', id: 7 });
  });

  it('opens nothing for notices without a mobile surface', () => {
    expect(notificationDestination(item(null))).toBeNull();
    expect(notificationDestination(item(undefined))).toBeNull();
    expect(notificationDestination(item({ kind: 'referral', id: 5 }))).toBeNull();
    expect(notificationDestination(item({ kind: 'workflow_alert' }))).toBeNull();
  });

  it('refuses malformed identifiers instead of navigating to a broken record', () => {
    expect(notificationDestination(item({ kind: 'appointment', id: 0 }))).toBeNull();
    expect(notificationDestination(item({ kind: 'appointment', id: -3 }))).toBeNull();
    expect(notificationDestination(item({ kind: 'appointment', id: 1.5 }))).toBeNull();
    expect(notificationDestination(item({ kind: 'appointment', id: '42' }))).toBeNull();
    expect(notificationDestination(item({ kind: 'appointment' }))).toBeNull();
    expect(notificationDestination(item({ kind: 'appointment', id: Number.MAX_SAFE_INTEGER + 2 }))).toBeNull();
  });
});
