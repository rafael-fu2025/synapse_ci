import { navigationRef, navigateToAppointmentDetail } from '../../navigation/navigationRef';
import { PushMessage } from './fcm';

/**
 * Route a tapped push to its screen.
 *
 * The server's data payload carries `{type, id}` (PushPublisher's templates).
 * Anything unrouteable — a cold navigator, an unknown type, a non-numeric id —
 * is ignored: the in-app inbox remains the complete, always-correct surface.
 */
export function routePushTap(message: PushMessage | null): void {
  if (!message || !navigationRef.isReady()) {
    return;
  }
  const type = message.data?.type;
  const id = Number(message.data?.id);
  if (!Number.isSafeInteger(id) || id < 1) {
    return;
  }
  if (type === 'appointment_confirmed') {
    navigateToAppointmentDetail(id);
    return;
  }
  if (type === 'announcement_published') {
    navigationRef.navigate('AnnouncementDetail', { id });
    return;
  }
  if (type === 'survey_published') {
    navigationRef.navigate('Surveys');
  }
}
