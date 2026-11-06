import { createNavigationContainerRef } from '@react-navigation/native';
import { RootStackParamList } from './types';

/**
 * The shared navigation container ref. Push taps arrive outside the React tree
 * and route through this ref instead of a useNavigation hook.
 */
export const navigationRef = createNavigationContainerRef<RootStackParamList>();

/** A tapped appointment push lands on the appointment's detail screen. */
export function navigateToAppointmentDetail(id: number): void {
  // The nested navigate cascades: root stack → AppTabs tab → Visits stack screen.
  navigationRef.navigate('AppTabs', {
    screen: 'Visits',
    params: { screen: 'AppointmentDetail', params: { id }, initial: false },
  });
}
