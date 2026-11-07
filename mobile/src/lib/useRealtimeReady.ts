import { useCallback, useSyncExternalStore } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useAuth } from '../store/auth';
import { isUserRealtimeReady, subscribeUserRealtime } from './realtime/transportState';

/** Polling is the default; only this session's authenticated, healthy socket can replace it. */
export function useRealtimeReady(): boolean {
  const client = useQueryClient();
  const { user } = useAuth();
  const tenantId = user?.tenant_id ?? 0;
  const userId = user?.id ?? 0;
  const subscribe = useCallback((listener: () => void) => subscribeUserRealtime(client, listener), [client]);
  const snapshot = useCallback(() => isUserRealtimeReady(client, { tenantId, userId }), [client, tenantId, userId]);
  return useSyncExternalStore(subscribe, snapshot, () => false);
}
