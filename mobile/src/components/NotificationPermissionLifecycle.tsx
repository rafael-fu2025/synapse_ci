import { useEffect } from 'react';
import { AppState, Platform } from 'react-native';
import { initializeNotificationPermission } from '../lib/push/permission';

/** Device permission belongs to the app, not the selected server or signed-in account. */
export function NotificationPermissionLifecycle(): null {
  useEffect(() => {
    if (Platform.OS !== 'android') { return undefined; }
    let mounted = true;
    let state = AppState.currentState;
    const active = () => mounted && state === 'active';
    const check = () => { if (active()) { initializeNotificationPermission(active).catch(() => undefined); } };
    const listener = AppState.addEventListener('change', next => { state = next; check(); });
    check();
    return () => { mounted = false; listener.remove(); };
  }, []);
  return null;
}
