import { useEffect } from 'react';
import { AppState } from 'react-native';
import NetInfo from '@react-native-community/netinfo';
import { focusManager, onlineManager } from '@tanstack/react-query';

/** React Query's browser focus/reconnect defaults need native lifecycle events. */
export function QueryLifecycle() {
  useEffect(() => {
    focusManager.setFocused(AppState.currentState === 'active');
    const appState = AppState.addEventListener('change', state => {
      focusManager.setFocused(state === 'active');
    });
    const unsubscribe = NetInfo.addEventListener(state => {
      // Local/LAN APIs work without public Internet; do not gate on the captive-portal probe.
      onlineManager.setOnline(state.isConnected !== false);
    });
    return () => {
      appState.remove();
      unsubscribe();
    };
  }, []);
  return null;
}
