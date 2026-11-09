import { useEffect, useRef } from 'react';
import { useQueryClient, type QueryClient } from '@tanstack/react-query';
import { pushNavigationIntent } from '../../features/workspace/navigationIntent';
import { queryKeys } from '../../api/queryKeys';
import { initialPushMessage, onPushMessage, onPushOpened, type PushMessage } from './fcm';
import { pausePushSession, registerDevice, revokeDevice, watchPushToken, type PushRegistrationOptions } from './registration';
import { routePushTap } from './router';
import { acceptsPushMessage, currentPushOwner, isPushOwnerCurrent, subscribePushScope } from './scope';

export { getPushSetupStatus, subscribePushSetupStatus, usePushSetupStatus } from './setupStatus';
export type { PushSetupStatus } from './setupStatus';

export type PushSessionStatus = 'restoring' | 'signedOut' | 'signedIn' | 'bootstrapError' | 'expired';
export interface PushSessionOptions extends PushRegistrationOptions {
  /** Legacy auth fixtures can pass their client without mounting a query provider. */
  queryClient?: QueryClient;
}

/** Push callbacks, cache hints and taps all belong to one captured runtime/auth activation. */
export function usePushSession(status: PushSessionStatus, options: PushSessionOptions = {}): void {
  const queryClient = useQueryClient(options.queryClient);
  const runtime = options.runtime;
  const tenantId = options.user?.tenant_id;
  const userId = options.user?.id;
  const latest = useRef({ status, runtime, tenantId, userId, isCurrent: options.isCurrent });
  latest.current = { status, runtime, tenantId, userId, isCurrent: options.isCurrent };

  useEffect(() => {
    if (status !== 'signedIn') {
      if (status === 'signedOut' || status === 'expired') {
        revokeDevice({ runtime }).catch(() => undefined);
      } else {
        // Bootstrap errors/restoring are not signout. Keep saved scope for a safe retry.
        pausePushSession(runtime);
      }
      return undefined;
    }
    let active = true;
    const stillSignedIn = () => active && latest.current.status === 'signedIn' &&
      latest.current.runtime === runtime && latest.current.tenantId === tenantId &&
      latest.current.userId === userId && (latest.current.isCurrent?.() ?? true);
    const user = tenantId === undefined || userId === undefined ? undefined : { tenant_id: tenantId, id: userId };
    const capturedOptions = { runtime, user, isCurrent: stillSignedIn };
    registerDevice(capturedOptions).catch(() => undefined);
    const owner = currentPushOwner();
    if (!owner) { return undefined; }
    const current = () => stillSignedIn() && isPushOwnerCurrent(owner);
    const stopTokens = watchPushToken(capturedOptions);
    const stopMessages = onPushMessage(message => {
      if (!current() || !acceptsPushMessage(message, owner)) { return; }
      queryClient.invalidateQueries({ queryKey: queryKeys.notifications, exact: false }).catch(() => undefined);
      const intent = pushNavigationIntent(message);
      if (!intent || !('unit' in intent)) { return; }
      const resource = intent.kind === 'team_task' ? 'team' : intent.kind === 'team_event' ? 'team-calendar' : 'team-chat';
      queryClient.invalidateQueries({ queryKey: [resource], exact: false }).catch(() => undefined);
    });

    // The native cold-start hint can resolve before the secure binding/capability check.
    // Hold just this owner's tap until a matching scope is restored or acknowledged.
    let pendingTap: PushMessage | null = null;
    let opened = false;
    const flushTap = () => {
      if (!current() || !pendingTap || !acceptsPushMessage(pendingTap, owner)) { return; }
      const message = pendingTap;
      pendingTap = null;
      routePushTap(message, owner);
    };
    const stopScope = subscribePushScope(flushTap);
    const stopOpened = onPushOpened(message => {
      if (!current()) { return; }
      opened = true;
      pendingTap = message;
      flushTap();
    });
    initialPushMessage().then(message => {
      if (!current() || opened || !message) { return; }
      pendingTap = message;
      flushTap();
    }).catch(() => undefined);

    return () => {
      active = false;
      pendingTap = null;
      stopTokens();
      stopMessages();
      stopOpened();
      stopScope();
      pausePushSession(runtime);
    };
  }, [status, runtime, runtime?.sessionVersion, tenantId, userId, queryClient]);
}
