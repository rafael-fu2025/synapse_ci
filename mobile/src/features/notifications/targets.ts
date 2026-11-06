import type { NotificationItem, NotificationTarget } from '../../api/endpoints/notifications';
import type { TeamUnit } from '../../api/types';

/**
 * Where a notice can be opened. The server decides which record the notice is
 * about; the client only decides which screen owns that record.
 */
export type NotificationDestination =
  | { kind: 'announcement'; id: number }
  | { kind: 'survey'; id: number }
  | { kind: 'appointment'; id: number }
  | { kind: 'team_task'; unit: TeamUnit; id: number }
  | { kind: 'team_event'; unit: TeamUnit; id: number }
  | { kind: 'team_chat_message'; unit: TeamUnit; id: number };

const PERSONAL_KINDS = ['announcement', 'survey', 'appointment'] as const;
const TEAM_KINDS = ['team_task', 'team_event', 'team_chat_message'] as const;
const TEAM_UNITS = ['clinic', 'guidance', 'facilities'] as const;

export function notificationDestination(item: NotificationItem): NotificationDestination | null {
  const target: NotificationTarget | null | undefined = item.target;
  if (!target || typeof target !== 'object') { return null; }
  const id = target.id;
  if (typeof id !== 'number' || !Number.isSafeInteger(id) || id < 1) { return null; }
  if (PERSONAL_KINDS.includes(target.kind as (typeof PERSONAL_KINDS)[number])) {
    return { kind: target.kind as 'announcement' | 'survey' | 'appointment', id };
  }
  if (!TEAM_KINDS.includes(target.kind as (typeof TEAM_KINDS)[number]) ||
      !('unit' in target) || !TEAM_UNITS.includes(target.unit as TeamUnit)) {
    return null;
  }
  return target as NotificationDestination;
}
