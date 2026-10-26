import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { AppearanceScreen } from '../../features/profile/screens/AppearanceScreen';
import { HealthProfileScreen } from '../../features/profile/screens/HealthProfileScreen';
import { PrivacySecurityScreen } from '../../features/profile/screens/PrivacySecurityScreen';
import { ProfileHomeScreen } from '../../features/profile/screens/ProfileHomeScreen';
import { ProfilePhotoScreen } from '../../features/profile/screens/ProfilePhotoScreen';
import { RecordsScreen } from '../../features/profile/screens/RecordsScreen';
import { SettingsScreen } from '../../features/profile/screens/SettingsScreen';
import { StaffQueueScreen } from '../../features/staff-queue/screens/StaffQueueScreen';
import { ProfileStackParamList } from '../types';

const Stack = createNativeStackNavigator<ProfileStackParamList>();

export function ProfileStack() {
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      <Stack.Screen name="ProfileHome" component={ProfileHomeScreen} />
      <Stack.Screen name="HealthProfile" component={HealthProfileScreen} />
      <Stack.Screen name="ProfilePhoto" component={ProfilePhotoScreen} />
      <Stack.Screen name="Records" component={RecordsScreen} />
      <Stack.Screen name="Settings" component={SettingsScreen} />
      <Stack.Screen name="PrivacySecurity" component={PrivacySecurityScreen} />
      <Stack.Screen name="Appearance" component={AppearanceScreen} />
      <Stack.Screen name="StaffQueue" component={StaffQueueScreen} />
    </Stack.Navigator>
  );
}
