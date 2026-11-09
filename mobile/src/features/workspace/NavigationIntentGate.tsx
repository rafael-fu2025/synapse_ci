import { useEffect, useRef } from 'react';
import type { TeamUnit } from '../../api/types';
import { useAuth } from '../../store/auth';
import { useOptionalConnection } from '../connection/ConnectionContext';
import { navigationRef, navigateToAppointmentDetail, navigateToWorkspaceIntent } from '../../navigation/navigationRef';
import { useWorkspace } from './WorkspaceContext';
import { queueNavigationIntent, subscribeNavigationIntent, takePendingNavigationIntent, type NavigationIntent, workUnitForIntent } from './navigationIntent';

type OpenUnit = (unit: TeamUnit, afterOpen?: () => void) => boolean;
type RequestDiscard = (action: () => void) => boolean | void;
type IntentAccess = {
  getProfile: () => ReturnType<typeof useAuth>['profile'];
  onUnavailable: () => void;
};

export function navigateAuthorizedIntent(
  intent: NavigationIntent,
  profile: ReturnType<typeof useAuth>['profile'],
  openUnit: OpenUnit,
  requestDiscard?: RequestDiscard,
  enterPersonal?: () => void,
  access?: IntentAccess,
): boolean {
  if (!navigationRef.isReady()) { return false; }
  const currentProfile = () => {
    const next = access ? access.getProfile() : profile;
    return next?.user.id === profile?.user.id && next?.user.tenant_id === profile?.user.tenant_id ? next : null;
  };
  if ('unit' in intent) {
    const unit = workUnitForIntent(currentProfile(), intent);
    return unit !== null && openUnit(unit, () => {
      const next = currentProfile();
      if (!navigationRef.isReady() || workUnitForIntent(next, intent) !== unit) {
        access?.onUnavailable();
        return;
      }
      const features = next?.entitlements.work.units.find(item => item.unit === unit)?.features;
      if (intent.kind === 'team_task' && features?.team_board === false) { navigateToWorkspaceIntent(intent, 'MyTasks'); }
      else { navigateToWorkspaceIntent(intent); }
    });
  }
  if (!currentProfile()?.entitlements.personal.enabled) { return false; }
  const open = () => {
    if (!navigationRef.isReady() || !currentProfile()?.entitlements.personal.enabled) {
      access?.onUnavailable();
      return;
    }
    if (intent.kind === 'appointment') {
      enterPersonal?.();
      navigateToAppointmentDetail(intent.id);
    } else if (intent.kind === 'announcement') {
      navigationRef.navigate('AnnouncementDetail', { id: intent.id });
    } else if (intent.kind === 'survey') {
      navigationRef.navigate('SurveyForm', { id: intent.id });
    }
  };
  if (requestDiscard) { return requestDiscard(open) !== false; }
  open();
  return true;
}

/** Flush one pending target after auth and navigation are ready, without replay loops. */
export function NavigationIntentGate() {
  const { status, profile } = useAuth();
  const owner = useOptionalConnection()?.owner ?? null;
  const current = useRef({ status, profile, owner });
  const mounted = useRef(true);
  current.current = { status, profile, owner };
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);
  const { openAuthorizedUnit, requestDiscard, enterPersonal, showNotice } = useWorkspace();
  useEffect(() => {
    if (status !== 'signedIn' || !profile) { return undefined; }
    const navigate = (intent: NavigationIntent) => {
      if (!navigationRef.isReady()) { return false; }
      const unavailable = () => {
        if (mounted.current) { showNotice('This item is no longer available to your account, or an action is still finishing.'); }
      };
      if (!navigateAuthorizedIntent(intent, profile, openAuthorizedUnit, requestDiscard, enterPersonal, {
        getProfile: () => mounted.current && current.current.owner === owner && current.current.status === 'signedIn' ? current.current.profile : null,
        onUnavailable: unavailable,
      })) {
        unavailable();
      }
      // A rejected authorized-session target is consumed, not replayed on every refresh.
      return true;
    };
    const unsubscribe = subscribeNavigationIntent(navigate, owner);
    const intent = takePendingNavigationIntent(owner);
    if (intent && !navigate(intent)) { queueNavigationIntent(intent, owner); }
    return unsubscribe;
  }, [enterPersonal, openAuthorizedUnit, owner, profile, requestDiscard, showNotice, status]);
  return null;
}
