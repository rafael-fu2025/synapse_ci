import { navigationRef, navigateToAppointmentDetail } from '../../navigation/navigationRef';
import { pushNavigationIntent, queueNavigationIntent } from '../../features/workspace/navigationIntent';
import { type PushMessage } from './fcm';

/** A push tap is only a pending navigation hint; entitlement-aware routing owns execution. */
export function routePushTap(message: PushMessage | null): void {
  const intent = pushNavigationIntent(message);
  if (!intent) { return; }
  if (!navigationRef.isReady()) {
    queueNavigationIntent(intent);
    return;
  }
  // Existing personal routes stay immediately available even before the gate subscribes.
  if (intent.kind === 'appointment') { navigateToAppointmentDetail(intent.id); return; }
  if (intent.kind === 'announcement') { navigationRef.navigate('AnnouncementDetail', { id: intent.id }); return; }
  if (intent.kind === 'survey') { navigationRef.navigate('Surveys'); return; }
  queueNavigationIntent(intent);
}
