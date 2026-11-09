import { useEffect } from 'react';
import { onlineManager, useQueryClient } from '@tanstack/react-query';
import { useApiClient } from '../../api/runtime';
import { RealtimeIdentity, startUserRealtime } from './userRealtime';

/**
 * One realtime lifetime while signed in, foreground, and online. The gate component
 * owns foreground tracking; sign-out/expiry unmounts it and closes the socket.
 */
export function useUserRealtime(active: boolean, identity: RealtimeIdentity | null): void {
  const client = useQueryClient();
  const api = useApiClient();
  useEffect(() => {
    if (!active || !identity) { return; }
    let stop: (() => void) | undefined;
    const update = () => {
      if (onlineManager.isOnline()) {
        stop ??= startUserRealtime(client, identity, api);
      } else {
        stop?.();
        stop = undefined;
      }
    };
    const unsubscribe = onlineManager.subscribe(update);
    update();
    return () => {
      unsubscribe();
      stop?.();
    };
    // The identity is stable while the gate is mounted; the effect re-runs per session.
  }, [active, api, identity, client]);
}
