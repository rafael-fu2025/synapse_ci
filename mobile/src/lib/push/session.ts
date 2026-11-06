import { useEffect } from 'react';
import { queryKeys } from '../../api/queryKeys';
import { queryClient } from '../queryClient';
import { initialPushMessage, onPushMessage, onPushOpened } from './fcm';
import { registerDevice, revokeDevice, watchPushToken } from './registration';
import { routePushTap } from './router';

export type PushSessionStatus = 'restoring' | 'signedOut' | 'signedIn' | 'expired';

/**
 * Keep the server's record of this device in step with the session.
 *
 * A device is registered when a session starts and withdrawn when it ends, so a
 * notice never reaches a device that has moved on to another account.
 */
export function usePushSession(status: PushSessionStatus): void {
  useEffect(() => {
    if (status === 'signedIn') {
      registerDevice().catch(() => undefined);
    }
  }, [status]);

  useEffect(() => {
    if (status === 'signedOut' || status === 'expired') {
      revokeDevice().catch(() => undefined);
    }
  }, [status]);

  useEffect(() => watchPushToken(), []);

  useEffect(() => {
    // A foreground push is a hint that something changed. The server stays the
    // source of truth; the realtime channel says the same thing when it is up.
    return onPushMessage(() => {
      queryClient.invalidateQueries({ queryKey: queryKeys.notifications, exact: false }).catch(() => undefined);
    });
  }, []);

  useEffect(() => {
    // Taps only route into a signed-in session: the protected screens do not
    // exist while signed out, so the tap falls back to the inbox on sign-in.
    if (status !== 'signedIn') {
      return undefined;
    }
    initialPushMessage().then(routePushTap).catch(() => undefined);
    return onPushOpened(routePushTap);
  }, [status]);
}
