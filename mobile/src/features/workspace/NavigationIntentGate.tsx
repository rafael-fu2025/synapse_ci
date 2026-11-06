import { useEffect } from 'react';
import { useAuth } from '../../store/auth';
import { navigationRef, navigateToAppointmentDetail, navigateToWorkspaceIntent } from '../../navigation/navigationRef';
import { useWorkspace } from './WorkspaceContext';
import {
  queueNavigationIntent,
  subscribeNavigationIntent,
  takePendingNavigationIntent,
  type NavigationIntent,
  workUnitForIntent,
} from './navigationIntent';

export function navigateAuthorizedIntent(intent: NavigationIntent, profile: ReturnType<typeof useAuth>['profile'], openUnit: (unit: 'clinic' | 'guidance' | 'facilities') => boolean): boolean {
  if (!navigationRef.isReady()) { queueNavigationIntent(intent); return false; }
  if (intent.kind === 'appointment') { navigateToAppointmentDetail(intent.id); return true; }
  if (intent.kind === 'announcement') { navigationRef.navigate('AnnouncementDetail', { id: intent.id }); return true; }
  if (intent.kind === 'survey') { navigationRef.navigate('SurveyForm', { id: intent.id }); return true; }
  const unit = workUnitForIntent(profile, intent);
  if (unit === null || !openUnit(unit)) { return false; }
  navigateToWorkspaceIntent(intent);
  return true;
}

/** Flushes one pending cold-start/inbox/push target once auth and navigation are ready. */
export function NavigationIntentGate() {
  const { status, profile } = useAuth();
  const { openAuthorizedUnit } = useWorkspace();
  useEffect(() => {
    if (status !== 'signedIn' || !profile) { return undefined; }
    const navigate = (intent: NavigationIntent) =>
      navigateAuthorizedIntent(intent, profile, openAuthorizedUnit);
    const unsubscribe = subscribeNavigationIntent(navigate);
    if (navigationRef.isReady()) {
      const intent = takePendingNavigationIntent();
      if (intent) { navigate(intent); }
    }
    return unsubscribe;
  }, [openAuthorizedUnit, profile, status]);
  return null;
}
