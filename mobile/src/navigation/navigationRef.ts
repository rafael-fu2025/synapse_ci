import type { NavigationIntent } from '../features/workspace/navigationIntent';
import { createNavigationContainerRef } from '@react-navigation/native';
import { RootStackParamList } from './types';

/**
 * The shared navigation container ref. Push taps arrive outside the React tree
 * and route through this ref instead of a useNavigation hook.
 */
export const navigationRef = createNavigationContainerRef<RootStackParamList>();

export function navigateToWorkspaceIntent(intent: Extract<NavigationIntent, { unit: unknown }>): void {
  const params = intent.kind === 'team_task'
    ? { screen: 'TeamBoard' as const, params: { screen: 'TeamTaskDetail' as const, params: { id: intent.id }, initial: false } }
    : intent.kind === 'team_event'
      ? { screen: 'TeamCalendar' as const, params: { screen: 'TeamCalendarEventDetail' as const, params: { id: intent.id }, initial: false } }
      : { screen: 'TeamChat' as const, params: { targetMessageId: intent.id } };
  navigationRef.navigate('AppTabs', {
    screen: 'WorkspaceContext',
    params: { ...params, initial: false } as never,
    initial: false,
  });
}

/** A tapped appointment push lands on the appointment's detail screen. */
export function navigateToAppointmentDetail(id: number): void {
  // The nested navigate cascades: root stack → AppTabs tab → Visits stack screen.
  navigationRef.navigate('AppTabs', {
    screen: 'PersonalContext',
    params: {
      screen: 'Visits',
      params: { screen: 'AppointmentDetail', params: { id }, initial: false },
      initial: false,
    },
  });
}
