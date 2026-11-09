import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { DashboardScreen } from '../../features/dashboard/screens/DashboardScreen';
import { HomeStackParamList } from '../types';
import { useNativeStackMotionOptions } from '../useNativeStackMotionOptions';

const Stack = createNativeStackNavigator<HomeStackParamList>();

export function HomeStack() {
  const motion = useNativeStackMotionOptions();
  return (
    <Stack.Navigator screenOptions={motion}>
      <Stack.Screen name="Dashboard" component={DashboardScreen} />
    </Stack.Navigator>
  );
}
