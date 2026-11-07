import { useSyncExternalStore } from 'react';
import { AppState } from 'react-native';
import { useIsFocused } from '@react-navigation/native';
import { focusManager, onlineManager } from '@tanstack/react-query';
import { useOverlayActivity } from '../components/OverlayProvider';

function subscribeActivity(onChange: () => void) {
  const app = AppState.addEventListener('change', onChange);
  const focus = focusManager.subscribe(onChange);
  const online = onlineManager.subscribe(onChange);
  return () => { app.remove(); focus(); online(); };
}

function foregroundOnline() {
  return AppState.currentState === 'active' && focusManager.isFocused() && onlineManager.isOnline();
}

/** Uses native connectivity, including LAN-only APIs, rather than Internet reachability. */
export function useForegroundOnline(): boolean {
  return useSyncExternalStore(subscribeActivity, foregroundOnline, () => false);
}

/** A mounted/frozen route is not necessarily a visible, eligible query observer. */
export function useActiveScreen(enabled = true): boolean {
  const focused = useIsFocused();
  const active = useForegroundOnline();
  const overlay = useOverlayActivity();
  return enabled && focused && active && !overlay;
}
