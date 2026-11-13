import { NativeModules, Platform } from 'react-native';
import type { PushMessage } from './fcm';
import { acceptsPushMessage, isPushOwnerCurrent, type PushOwner } from './scope';

interface SystemNotificationsBridge {
  post(ownerKey: string, messageId: string, title: string, body: string): void;
  clear(ownerKey: string): void;
  areNotificationsEnabled(): Promise<boolean>;
}

// Epochs are monotonic within a React context, matching the native retirement
// watermark. Never serialize an owner, origin, account, credential or scope.
const OWNER_KEY_PREFIX = 'synapse-push-owner-';
const retiredOwners = new WeakSet<PushOwner>();

function ownerKey(owner: PushOwner): string | null {
  return Number.isSafeInteger(owner.epoch) && owner.epoch > 0 ? `${OWNER_KEY_PREFIX}${owner.epoch}` : null;
}

/** Native capability only; permission and channel availability are checked separately. */
export function hasSystemNotifications(): boolean {
  if (Platform.OS !== 'android') { return false; }
  try {
    return typeof NativeModules.SystemNotifications?.post === 'function';
  } catch { return false; }
}

/** Check-only: null means the optional bridge cannot determine availability. */
export async function checkSystemNotifications(): Promise<boolean | null> {
  if (Platform.OS !== 'android') { return null; }
  try {
    const native = NativeModules.SystemNotifications as Partial<SystemNotificationsBridge> | undefined;
    if (typeof native?.areNotificationsEnabled !== 'function') { return null; }
    const enabled = await native.areNotificationsEnabled();
    return typeof enabled === 'boolean' ? enabled : null;
  } catch { return null; }
}

/** Optional Android system notice for an already accepted foreground FCM message. */
export function showSystemNotification(message: PushMessage, owner: PushOwner): void {
  if (Platform.OS !== 'android') { return; }
  try {
    const native = NativeModules.SystemNotifications as Partial<SystemNotificationsBridge> | undefined;
    const key = ownerKey(owner);
    const messageId = message.messageId;
    const notification = message.notification;
    if (typeof native?.post !== 'function' || key === null || retiredOwners.has(owner) ||
        typeof messageId !== 'string' || messageId.trim().length === 0 ||
        !notification || typeof notification !== 'object') { return; }
    const title = owner.lanTest ? 'Synapse LAN Test' :
      typeof notification.title === 'string' ? notification.title : 'Synapse';
    const body = owner.lanTest ? 'Open the app to check for updates' :
      typeof notification.body === 'string' ? notification.body : '';
    // Defense in depth: neither the native call nor its displayed text may
    // precede the captured owner's current activation and LAN scope checks.
    if (!isPushOwnerCurrent(owner) || !acceptsPushMessage(message, owner)) { return; }
    native.post(key, messageId, title, body);
  } catch {
    // Older binaries, disabled notifications and optional bridge failures must
    // not interrupt the guarded inbox/invalidation/foreground handler.
  }
}

/** Retire even a stale owner, but never clear a newer owner's or FCM's notices. */
export function clearSystemNotifications(owner: PushOwner): void {
  retiredOwners.add(owner);
  if (Platform.OS !== 'android') { return; }
  try {
    const key = ownerKey(owner);
    const native = NativeModules.SystemNotifications as Partial<SystemNotificationsBridge> | undefined;
    if (key !== null && typeof native?.clear === 'function') { native.clear(key); }
  } catch {
    // Cleanup is best-effort; native retirement also blocks delayed queued posts.
  }
}
