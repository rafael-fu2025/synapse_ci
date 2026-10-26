import React from 'react';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { CalendarDays, Home, ListChecks, UserRound } from 'lucide-react-native';
import { Theme } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';
import { AppTabsParamList } from './types';
import { HomeStack } from './stacks/HomeStack';
import { ProfileStack } from './stacks/ProfileStack';
import { QueueStack } from './stacks/QueueStack';
import { VisitsStack } from './stacks/VisitsStack';

const Tab = createBottomTabNavigator<AppTabsParamList>();

const TAB_ICONS = {
  Home: Home,
  Visits: CalendarDays,
  Queue: ListChecks,
  Profile: UserRound,
} as const;

function TabIcon({
  icon: Icon,
  color,
  focused,
}: {
  icon: (typeof TAB_ICONS)[keyof typeof TAB_ICONS];
  color: string;
  focused: boolean;
}) {
  return <Icon size={21} color={color} strokeWidth={focused ? 2.5 : 2} />;
}

export function AppTabs() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Tab.Navigator
      screenOptions={({ route }) => ({
        headerShown: false,
        tabBarActiveTintColor: theme.brand,
        tabBarInactiveTintColor: theme.ink400,
        tabBarStyle: styles.bar,
        tabBarLabelStyle: styles.label,
        tabBarIcon: ({ color, focused }) => (
          <TabIcon icon={TAB_ICONS[route.name]} color={color} focused={focused} />
        ),
      })}>
      <Tab.Screen name="Home" component={HomeStack} options={{ tabBarLabel: 'Home' }} />
      <Tab.Screen name="Visits" component={VisitsStack} options={{ tabBarLabel: 'Visits' }} />
      <Tab.Screen name="Queue" component={QueueStack} options={{ tabBarLabel: 'Queue' }} />
      <Tab.Screen name="Profile" component={ProfileStack} options={{ tabBarLabel: 'Profile' }} />
    </Tab.Navigator>
  );
}

function makeStyles(theme: Theme) {
  return {
    bar: {
      height: 68,
      paddingTop: 5,
      paddingHorizontal: 8,
      backgroundColor: theme.surface,
      borderTopColor: theme.line,
      borderTopWidth: 1,
    },
    label: { fontSize: 9.5, fontWeight: '600' as const },
  };
}
