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

type ScopedIntent = { intent: NavigationIntent; owner: number | null };
let activeOwner: number | null = null;
let pending: ScopedIntent | null = null;
const listeners = new Set<{ owner: number | null; listener: (intent: NavigationIntent) => boolean }>();

export function clearNavigationIntents(): void {
  pending = null;
  listeners.clear();
  activeOwner = null;
}

export function setNavigationIntentOwner(owner: number): void {
  if (owner !== activeOwner) { clearNavigationIntents(); }
  activeOwner = owner;
}

export function queueNavigationIntent(intent: NavigationIntent | null, owner = activeOwner): void {
  if (!intent || owner !== activeOwner) { return; }
  for (const entry of listeners) {
    if (entry.owner === owner && entry.listener(intent)) { pending = null; return; }
  }
  pending = { intent, owner };
}

export function subscribeNavigationIntent(listener: (intent: NavigationIntent) => boolean, owner = activeOwner): () => void {
  const entry = { owner, listener };
  listeners.add(entry);
  return () => { listeners.delete(entry); };
}

export function takePendingNavigationIntent(owner = activeOwner): NavigationIntent | null {
  const value = pending;
  if (owner !== activeOwner) { return null; }
  pending = null;
  return value?.owner === owner ? value.intent : null;
}

export function __resetNavigationIntentForTests(): void {
  clearNavigationIntents();
}
