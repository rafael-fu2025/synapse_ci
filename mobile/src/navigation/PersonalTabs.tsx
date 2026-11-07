import React from 'react';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { CalendarDays, Home, ListChecks, UserRound } from 'lucide-react-native';
import { PersonalTabsParamList } from './types';
import { HomeStack } from './stacks/HomeStack';
import { ProfileStack } from './stacks/ProfileStack';
import { QueueStack } from './stacks/QueueStack';
import { VisitsStack } from './stacks/VisitsStack';
import { ContextTabBarBinding } from './ContextTabBarBinding';
import { useTabMotionOptions } from './useTabMotionOptions';

const Tab = createBottomTabNavigator<PersonalTabsParamList>();
const ICONS = { Home, Visits: CalendarDays, Queue: ListChecks, Profile: UserRound } as const;

export function PersonalTabs() {
  const motion = useTabMotionOptions();
  return (
    <Tab.Navigator
      tabBar={(props) => <ContextTabBarBinding mode="personal" {...props} />}
      screenOptions={({ route }) => ({
        ...motion,
        tabBarStyle: { height: 0 },
        tabBarIcon: ({ color, focused }) => {
          const Icon = ICONS[route.name];
          return <Icon size={21} color={color} strokeWidth={focused ? 2.5 : 2} />;
        },
      })}>
      <Tab.Screen name="Home" component={HomeStack} />
      <Tab.Screen name="Visits" component={VisitsStack} />
      <Tab.Screen name="Queue" component={QueueStack} />
      <Tab.Screen name="Profile" component={ProfileStack} />
    </Tab.Navigator>
  );
}
