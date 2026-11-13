import { useSyncExternalStore } from 'react';
import { Platform } from 'react-native';
import { checkPushPermission, requestPushPermission, type PushPermission } from './fcm';
import { checkSystemNotifications } from './systemNotifications';

const PROMPT_KEY = 'synapse.notifications.prompted.v1';
export interface NotificationPermissionStatus {
  readonly permission: PushPermission | 'unknown';
  readonly requesting: boolean;
  readonly systemEnabled: boolean | null;
}
let status: NotificationPermissionStatus = Object.freeze({ permission: 'unknown', requesting: false, systemEnabled: null });
const listeners = new Set<() => void>();
let pending: Promise<PushPermission> | null = null;
let pendingKind: 'check' | 'automatic' | 'explicit' | null = null;
let attempted = false;
const automaticCallers = new Set<() => boolean>();

export function getNotificationPermission(): NotificationPermissionStatus { return status; }
export function subscribeNotificationPermission(listener: () => void): () => void {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}
export function useNotificationPermission(): NotificationPermissionStatus {
  return useSyncExternalStore(subscribeNotificationPermission, getNotificationPermission, getNotificationPermission);
}
function publish(permission: NotificationPermissionStatus['permission'], requesting: boolean, systemEnabled = status.systemEnabled): void {
  status = Object.freeze({ permission, requesting, systemEnabled });
  for (const listener of listeners) { listener(); }
}

/** The marker is device-local, not an account/server preference or permission grant. */
async function promptAttempted(): Promise<boolean> {
  if (attempted) { return true; }
  try {
    const storage = require('@react-native-async-storage/async-storage').default;
    attempted = await storage.getItem(PROMPT_KEY) === '1';
  } catch { /* Native permission remains authoritative if preference storage is unavailable. */ }
  return attempted;
}
async function rememberAttempt(): Promise<void> {
  attempted = true;
  try {
    const storage = require('@react-native-async-storage/async-storage').default;
    await storage.setItem(PROMPT_KEY, '1');
  } catch { /* Still suppress repeated automatic prompts during this process. */ }
}

function run(operation: () => Promise<PushPermission>, kind: 'check' | 'automatic' | 'explicit' = 'check'): Promise<PushPermission> {
  if (pending) {
    // A check-only registration racing the first screen must not consume onboarding.
    if (pendingKind === 'check' && kind !== 'check') { return pending.then(() => run(operation, kind)); }
    return pending;
  }
  publish(status.permission, true);
  const work = operation().catch((): PushPermission => 'unavailable').then(async permission => {
    const systemEnabled = await checkSystemNotifications();
    publish(permission, false, systemEnabled);
    return permission;
  });
  pending = work;
  pendingKind = kind;
  work.finally(() => { if (pending === work) { pending = null; pendingKind = null; } });
  return work;
}

/** Returning to the foreground checks permission but must never reopen a denied prompt. */
export function refreshNotificationPermission(): Promise<PushPermission> {
  return run(checkPushPermission);
}

/** First Android launch does not depend on authentication, Firebase, or a LAN capability request. */
export function initializeNotificationPermission(isActive: () => boolean): Promise<PushPermission> {
  automaticCallers.add(isActive);
  const active = () => [...automaticCallers].some(value => value());
  const work = run(async () => {
    const current = await checkPushPermission();
    if (Platform.OS !== 'android' || Number(Platform.Version) < 33 || current !== 'denied' || !active()) { return current; }
    if (await promptAttempted() || !active()) { return current; }
    let saved: Promise<void> | undefined;
    const result = await requestPushPermission(active, () => { saved = rememberAttempt(); });
    // Save as the native request starts, even if the process closes while its dialog is open.
    // A background/unmount before that request must not consume first-launch onboarding.
    await saved;
    return result;
  }, 'automatic');
  return work.finally(() => { automaticCallers.delete(isActive); });
}

/** Only a deliberate enable action may request again after the first launch. */
export function enableNotificationPermission(isActive: () => boolean): Promise<PushPermission> {
  return run(async () => {
    if (!isActive()) { return 'unavailable'; }
    const result = await requestPushPermission(isActive);
    await rememberAttempt();
    return result;
  }, 'explicit');
}

export function __resetNotificationPermissionForTests(): void {
  status = Object.freeze({ permission: 'unknown', requesting: false, systemEnabled: null });
  pending = null;
  pendingKind = null;
  attempted = false;
  automaticCallers.clear();
  listeners.clear();
}
