import { useEffect, useMemo, useState } from 'react';
import { AppState } from 'react-native';
import { useAuth } from '../../store/auth';
import { RealtimeIdentity } from './userRealtime';
import { useUserRealtime } from './useUserRealtime';

/** Renders nothing; owns the signed-in user's realtime socket lifetime. */
export function UserRealtimeGate(): null {
  const { status, user } = useAuth();
  const [foreground, setForeground] = useState(AppState.currentState === 'active');
  useEffect(() => {
    const subscription = AppState.addEventListener('change', state => setForeground(state === 'active'));
    return () => subscription.remove();
  }, []);
  const identity = useMemo<RealtimeIdentity | null>(
    () => (status === 'signedIn' && user ? { tenantId: user.tenant_id, userId: user.id } : null),
    [status, user],
  );
  useUserRealtime(identity !== null && foreground, identity);
  return null;
}
