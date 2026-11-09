import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { SettingsScreen } from '../../features/profile/screens/SettingsScreen';
import { AppearanceScreen } from '../../features/profile/screens/AppearanceScreen';
import { PrivacySecurityScreen } from '../../features/profile/screens/PrivacySecurityScreen';
import type { AccountStackParamList } from '../types';
import { useNativeStackMotionOptions } from '../useNativeStackMotionOptions';

const Stack = createNativeStackNavigator<AccountStackParamList>();

/** Account preferences are shared without exposing Personal-only records or routes. */
export function AccountStack() {
  const motion = useNativeStackMotionOptions();
  return (
    <Stack.Navigator screenOptions={motion}>
      <Stack.Screen name="Settings" component={SettingsScreen} />
      <Stack.Screen name="Appearance" component={AppearanceScreen} />
      <Stack.Screen name="PrivacySecurity" component={PrivacySecurityScreen} />
    </Stack.Navigator>
  );
}
