import type { NotificationItem, NotificationTarget } from '../../api/endpoints/notifications';

/**
 * Where a notice can be opened. The server decides which record the notice is
 * about; the client only decides which screen owns that record.
 */
export type NotificationDestination =
  | { kind: 'announcement'; id: number }
  | { kind: 'survey'; id: number }
  | { kind: 'appointment'; id: number };

const KINDS = ['announcement', 'survey', 'appointment'] as const;

/**
 * Notices without a mobile surface (referrals, team events, team tasks, chat
 * mentions and workflow alerts) report no target and open nothing.
 */
export function notificationDestination(item: NotificationItem): NotificationDestination | null {
  const target: NotificationTarget | null | undefined = item.target;
  if (!target || typeof target !== 'object') {
    return null;
  }
  const id = target.id;
  if (typeof id !== 'number' || !Number.isSafeInteger(id) || id < 1) {
    return null;
  }
  if (!KINDS.includes(target.kind)) {
    return null;
  }
  return { kind: target.kind, id };
}
