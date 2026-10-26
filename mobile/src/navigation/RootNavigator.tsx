import React from 'react';
import { StyleSheet, View } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { useNetInfo } from '@react-native-community/netinfo';
import { AnnouncementsScreen } from '../features/announcements/screens/AnnouncementsScreen';
import { AnnouncementDetailScreen } from '../features/announcements/screens/AnnouncementDetailScreen';
import { NotificationsScreen } from '../features/notifications/screens/NotificationsScreen';
import { SurveyFormScreen } from '../features/surveys/screens/SurveyFormScreen';
import { SurveysScreen } from '../features/surveys/screens/SurveysScreen';
import { SessionExpiredScreen } from '../features/auth/screens/SessionExpiredScreen';
import { SignInScreen } from '../features/auth/screens/SignInScreen';
import { SplashScreen } from '../features/auth/screens/SplashScreen';
import { OfflineBanner } from '../components/OfflineBanner';
import { useAuth } from '../store/auth';
import { useTheme } from '../theme/ThemeProvider';
import { AppTabs } from './AppTabs';
import { RootStackParamList } from './types';

const Stack = createNativeStackNavigator<RootStackParamList>();

/** Root gate: splash while restoring, sign-in when unauthenticated, the app when signed in. */
export function RootNavigator() {
  const { theme } = useTheme();
  const { status } = useAuth();
  const network = useNetInfo();
  const offline = network.isConnected === false;

  return (
    <NavigationContainer
      theme={{
        dark: theme.mode === 'dark',
        colors: {
          primary: theme.brand,
          background: theme.canvas,
          card: theme.surface,
          text: theme.ink900,
          border: theme.line,
          notification: theme.brand,
        },
        fonts: {
          regular: { fontFamily: 'Nunito Sans', fontWeight: '400' },
          medium: { fontFamily: 'Nunito Sans', fontWeight: '600' },
          bold: { fontFamily: 'Nunito Sans', fontWeight: '800' },
          heavy: { fontFamily: 'Nunito Sans', fontWeight: '800' },
        },
      }}>
      {status === 'restoring' ? (
        <SplashScreen />
      ) : status === 'signedOut' ? (
        <SignInScreen />
      ) : status === 'expired' ? (
        <SessionExpiredScreen />
      ) : (
        <View style={styles.flex}>
          {offline ? <OfflineBanner /> : null}
          <Stack.Navigator screenOptions={{ headerShown: false }}>
            <Stack.Screen name="AppTabs" component={AppTabs} />
            <Stack.Screen name="Announcements" component={AnnouncementsScreen} />
            <Stack.Screen name="AnnouncementDetail" component={AnnouncementDetailScreen} />
            <Stack.Screen name="Surveys" component={SurveysScreen} />
            <Stack.Screen name="SurveyForm" component={SurveyFormScreen} options={{ gestureEnabled: false }} />
            <Stack.Screen name="Notifications" component={NotificationsScreen} />
          </Stack.Navigator>
        </View>
      )}
    </NavigationContainer>
  );
}

const styles = StyleSheet.create({ flex: { flex: 1 } });
