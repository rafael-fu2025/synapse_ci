import React, { useMemo } from 'react';
import { StyleSheet, View } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import type { Theme as NavigationTheme } from '@react-navigation/native';
import { font } from '../theme/tokens';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { useNetInfo } from '@react-native-community/netinfo';
import { AnnouncementsScreen } from '../features/announcements/screens/AnnouncementsScreen';
import { AnnouncementDetailScreen } from '../features/announcements/screens/AnnouncementDetailScreen';
import { NotificationsScreen } from '../features/notifications/screens/NotificationsScreen';
import { SurveyFormScreen } from '../features/surveys/screens/SurveyFormScreen';
import { SurveysScreen } from '../features/surveys/screens/SurveysScreen';
import { BootstrapErrorScreen } from '../features/auth/screens/BootstrapErrorScreen';
import { SessionExpiredScreen } from '../features/auth/screens/SessionExpiredScreen';
import { SignInScreen } from '../features/auth/screens/SignInScreen';
import { SplashScreen } from '../features/auth/screens/SplashScreen';
import { OfflineBanner } from '../components/OfflineBanner';
import { PushBanner } from '../components/PushBanner';
import { useAuth } from '../store/auth';
import { useTheme } from '../theme/ThemeProvider';
import { NavigationIntentGate } from '../features/workspace/NavigationIntentGate';
import { UserRealtimeGate } from '../features/realtime/UserRealtimeGate';
import { navigationRef } from './navigationRef';
import { ContextTabs } from './ContextTabs';
import { AccountStack } from './stacks/AccountStack';
import { WorkspaceNotice } from '../features/workspace/WorkspaceNotice';
import { queueNavigationIntent, takePendingNavigationIntent } from '../features/workspace/navigationIntent';
import { RootStackParamList } from './types';
import { useNativeStackMotionOptions } from './useNativeStackMotionOptions';

const Stack = createNativeStackNavigator<RootStackParamList>();

/** Root gate: splash while restoring, sign-in when unauthenticated, the app when signed in. */
export function RootNavigator() {
  const { theme } = useTheme();
  const motion = useNativeStackMotionOptions();
  const { status, profile } = useAuth();
  const personalEnabled = profile?.entitlements.personal.enabled === true;
  const network = useNetInfo();
  const offline = network.isConnected === false;

  const navigationTheme = useMemo<NavigationTheme>(
    () => ({
      dark: theme.mode === 'dark',
      colors: {
        primary: theme.brandText,
        background: theme.canvas,
        card: theme.surface,
        text: theme.ink900,
        border: theme.line,
        notification: theme.brand,
      },
      fonts: {
        regular: { fontFamily: font.body, fontWeight: '400' },
        medium: { fontFamily: font.body, fontWeight: '600' },
        bold: { fontFamily: font.body, fontWeight: '800' },
        heavy: { fontFamily: font.body, fontWeight: '800' },
      },
    }),
    [theme],
  );

  return (
    <NavigationContainer theme={navigationTheme} ref={navigationRef} onReady={() => {
      const pending = takePendingNavigationIntent();
      if (pending) { queueNavigationIntent(pending); }
    }}>
      {status === 'restoring' ? (
        <SplashScreen />
      ) : status === 'bootstrapError' ? (
        <BootstrapErrorScreen />
      ) : status === 'signedOut' ? (
        <SignInScreen />
      ) : status === 'expired' ? (
        <SessionExpiredScreen />
      ) : (
        <View style={styles.flex}>
          <UserRealtimeGate />
          <NavigationIntentGate />
          {offline ? <OfflineBanner /> : null}
          <PushBanner />
          <WorkspaceNotice />
          <Stack.Navigator screenOptions={motion}>
            <Stack.Screen name="AppTabs" component={ContextTabs} />
            {personalEnabled ? (
              <Stack.Group navigationKey="personal">
                <Stack.Screen name="Announcements" component={AnnouncementsScreen} />
                <Stack.Screen name="AnnouncementDetail" component={AnnouncementDetailScreen} />
                <Stack.Screen name="Surveys" component={SurveysScreen} />
                <Stack.Screen name="SurveyForm" component={SurveyFormScreen} options={{ gestureEnabled: false }} />
              </Stack.Group>
            ) : null}
            <Stack.Screen
              name="Notifications"
              component={NotificationsScreen}
            />
            <Stack.Screen name="AccountSettings" component={AccountStack} />
          </Stack.Navigator>
        </View>
      )}
    </NavigationContainer>
  );
}

const styles = StyleSheet.create({ flex: { flex: 1 } });
