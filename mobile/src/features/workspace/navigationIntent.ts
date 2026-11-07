import type { NotificationDestination } from '../notifications/targets';
import type { MeResponse, TeamUnit } from '../../api/types';
import type { PushMessage } from '../../lib/push/fcm';

export type NavigationIntent = NotificationDestination;

const TEAM_UNITS = ['clinic', 'guidance', 'facilities'] as const;
const PUSH_TEAM_TYPES = {
  team_task: 'team_task',
  team_event: 'team_event',
  team_chat_message: 'team_chat_message',
} as const;

export function workUnitForIntent(profile: MeResponse | null, intent: NavigationIntent): TeamUnit | null {
  if (!('unit' in intent) || profile?.entitlements.work.enabled !== true) { return null; }
  const feature = intent.kind === 'team_event' ? 'team_calendar' : 'team_chat';
  return profile.entitlements.work.units.some(item => item.unit === intent.unit && (intent.kind === 'team_task'
    ? item.features.team_board || item.features.my_tasks
    : item.features[feature])) ? intent.unit : null;
}

export function pushNavigationIntent(message: PushMessage | null): NavigationIntent | null {
  if (!message?.data) { return null; }
  const type = message.data.type;
  const id = Number(message.data.id);
  if (!Number.isSafeInteger(id) || id < 1) { return null; }
  if (type === 'appointment_confirmed') { return { kind: 'appointment', id }; }
  if (type === 'announcement_published') { return { kind: 'announcement', id }; }
  if (type === 'survey_published') { return { kind: 'survey', id }; }
  const kind = PUSH_TEAM_TYPES[type as keyof typeof PUSH_TEAM_TYPES];
  const unit = message.data.unit;
  if (!kind || !TEAM_UNITS.includes(unit as TeamUnit)) { return null; }
  return { kind, unit: unit as TeamUnit, id };
}

let pending: NavigationIntent | null = null;
const listeners = new Set<(intent: NavigationIntent) => boolean>();

export function queueNavigationIntent(intent: NavigationIntent | null): void {
  if (!intent) { return; }
  for (const listener of listeners) {
    if (listener(intent)) { pending = null; return; }
  }
  pending = intent;
}

export function subscribeNavigationIntent(listener: (intent: NavigationIntent) => boolean): () => void {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}

export function takePendingNavigationIntent(): NavigationIntent | null {
  const value = pending;
  pending = null;
  return value;
}

export function __resetNavigationIntentForTests(): void {
  pending = null;
  listeners.clear();
}
