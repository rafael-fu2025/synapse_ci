import { Platform } from 'react-native';

export type PushPlatform = 'android' | 'ios';

export interface PushMessage {
  notification?: { title?: string; body?: string };
  data?: Record<string, string>;
}

type Unsubscribe = () => void;

/** The slice of the v26 modular messaging API this app uses. */
interface MessagingInstance {
  getToken(): Promise<string>;
}

interface MessagingModule {
  getMessaging(): MessagingInstance;
  getToken(messaging: MessagingInstance): Promise<string>;
  onTokenRefresh(messaging: MessagingInstance, listener: (token: string) => void): Unsubscribe;
  onMessage(messaging: MessagingInstance, listener: (message: PushMessage) => void): Unsubscribe;
  onNotificationOpenedApp(messaging: MessagingInstance, listener: (message: PushMessage) => void): Unsubscribe;
  getInitialNotification(messaging: MessagingInstance): Promise<PushMessage | null>;
}

interface PermissionResponse {
  status: string;
}

/**
 * react-native-permissions exposes notifications as a first-class pair rather
 * than as entries in PERMISSIONS: the Android POST_NOTIFICATIONS runtime
 * permission only exists from API 33 and older releases use a different check.
 */
interface PermissionsModule {
  checkNotifications(): Promise<PermissionResponse>;
  requestNotifications(): Promise<PermissionResponse>;
}

let messagingModule: MessagingModule | null | undefined;
let permissionsModule: PermissionsModule | null | undefined;

/**
 * The messaging module is loaded on demand.
 *
 * A build without google-services.json has no native messaging module, and the
 * app must keep working: push is an addition to the in-app inbox, never a
 * requirement for it.
 */
function loadMessagingModule(): MessagingModule | null {
  if (messagingModule === undefined) {
    try {
      messagingModule = require('@react-native-firebase/messaging') as MessagingModule;
    } catch {
      messagingModule = null;
    }
  }
  return messagingModule;
}

function loadPermissionsModule(): PermissionsModule | null {
  if (permissionsModule === undefined) {
    try {
      permissionsModule = require('react-native-permissions') as PermissionsModule;
    } catch {
      permissionsModule = null;
    }
  }
  return permissionsModule;
}

export function currentPushPlatform(): PushPlatform {
  return Platform.OS === 'ios' ? 'ios' : 'android';
}

export type PushPermission = 'granted' | 'denied' | 'blocked' | 'unavailable';

/**
 * Ask the platform for the notification permission.
 *
 * A device that has not been granted the permission is never worth a token.
 */
export async function requestPushPermission(): Promise<PushPermission> {
  const permissions = loadPermissionsModule();
  if (!permissions) {
    return 'unavailable';
  }
  try {
    const current = await permissions.checkNotifications();
    if (current.status === 'granted' || current.status === 'limited') {
      return 'granted';
    }
    const requested = await permissions.requestNotifications();
    if (requested.status === 'granted' || requested.status === 'limited') {
      return 'granted';
    }
    return requested.status === 'blocked' ? 'blocked' : 'denied';
  } catch {
    return 'unavailable';
  }
}

/** The device's messaging token, or null when the platform cannot produce one. */
export async function currentDeviceToken(): Promise<string | null> {
  const messaging = loadMessagingModule();
  if (!messaging) {
    return null;
  }
  try {
    const token = await messaging.getToken(messaging.getMessaging());
    return typeof token === 'string' && token.length > 0 ? token : null;
  } catch {
    return null;
  }
}

/** The platform rotates tokens; the registration must follow. */
export function onPushTokenRefresh(handler: (token: string) => void): Unsubscribe {
  const messaging = loadMessagingModule();
  if (!messaging) {
    return () => undefined;
  }
  try {
    return messaging.onTokenRefresh(messaging.getMessaging(), handler);
  } catch {
    return () => undefined;
  }
}

/**
 * A message that arrived while the app was in the foreground.
 *
 * The operating system only raises the banner for a backgrounded or killed app,
 * so this is the app's own chance to notice the notice.
 */
export function onPushMessage(handler: (message: PushMessage) => void): Unsubscribe {
  const messaging = loadMessagingModule();
  if (!messaging) {
    return () => undefined;
  }
  try {
    return messaging.onMessage(messaging.getMessaging(), handler);
  } catch {
    return () => undefined;
  }
}

/** The user tapped a system banner while the app was backgrounded (not killed). */
export function onPushOpened(handler: (message: PushMessage) => void): Unsubscribe {
  const messaging = loadMessagingModule();
  if (!messaging) {
    return () => undefined;
  }
  try {
    return messaging.onNotificationOpenedApp(messaging.getMessaging(), handler);
  } catch {
    return () => undefined;
  }
}

/** The push that launched the app from a killed state, if there was one. */
export async function initialPushMessage(): Promise<PushMessage | null> {
  const messaging = loadMessagingModule();
  if (!messaging) {
    return null;
  }
  try {
    const message = await messaging.getInitialNotification(messaging.getMessaging());
    return message ?? null;
  } catch {
    return null;
  }
}
