import { pushNavigationIntent, queueNavigationIntent } from '../../features/workspace/navigationIntent';
import { type PushMessage } from './fcm';
import { acceptsPushMessage, currentPushOwner, type PushOwner } from './scope';

/** A tap is only an owner-scoped pending hint; the entitlement-aware navigation gate executes it. */
export function routePushTap(message: PushMessage | null, owner?: PushOwner | null): void {
  const captured = owner === undefined ? currentPushOwner() : owner;
  if (!acceptsPushMessage(message, captured)) { return; }
  const intent = pushNavigationIntent(message);
  if (!intent) { return; }
  // Personal routes use the same gate as team routes: no bypass for a ready navigator.
  queueNavigationIntent(intent, captured?.runtimeId ?? undefined);
}
