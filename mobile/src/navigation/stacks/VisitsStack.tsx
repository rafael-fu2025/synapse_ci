import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { AppointmentDetailScreen } from '../../features/appointments/screens/AppointmentDetailScreen';
import { AppointmentsScreen } from '../../features/appointments/screens/AppointmentsScreen';
import { BookingScreen } from '../../features/appointments/screens/BookingScreen';
import { CancelAppointmentScreen } from '../../features/appointments/screens/CancelAppointmentScreen';
import { VisitsStackParamList } from '../types';

const Stack = createNativeStackNavigator<VisitsStackParamList>();

export function VisitsStack() {
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      <Stack.Screen name="Appointments" component={AppointmentsScreen} />
      <Stack.Screen name="AppointmentDetail" component={AppointmentDetailScreen} />
      <Stack.Screen name="Booking" component={BookingScreen} options={{ gestureEnabled: false }} />
      <Stack.Screen name="CancelAppointment" component={CancelAppointmentScreen} />
    </Stack.Navigator>
  );
}
