import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { AppointmentDetailScreen } from '../../features/appointments/screens/AppointmentDetailScreen';
import { AppointmentsScreen } from '../../features/appointments/screens/AppointmentsScreen';
import { BookingScreen } from '../../features/appointments/screens/BookingScreen';
import { CancelAppointmentScreen } from '../../features/appointments/screens/CancelAppointmentScreen';
import { VisitsStackParamList } from '../types';
import { useNativeStackMotionOptions } from '../useNativeStackMotionOptions';

const Stack = createNativeStackNavigator<VisitsStackParamList>();

export function VisitsStack() {
  const motion = useNativeStackMotionOptions();
  return (
    <Stack.Navigator screenOptions={motion}>
      <Stack.Screen name="Appointments" component={AppointmentsScreen} />
      <Stack.Screen name="AppointmentDetail" component={AppointmentDetailScreen} />
      <Stack.Screen name="Booking" component={BookingScreen} options={{ gestureEnabled: false }} />
      <Stack.Screen name="CancelAppointment" component={CancelAppointmentScreen} />
    </Stack.Navigator>
  );
}
