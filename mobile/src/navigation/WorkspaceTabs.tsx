import React from 'react';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { CalendarDays, ClipboardList, ListTodo, MessageCircle } from 'lucide-react-native';
import { TeamChatRoot } from '../features/team-chat';
import { TeamCalendarRoot } from '../features/team-calendar/TeamCalendarStack';
import { MyTasksStack, TeamBoardStack } from '../features/team-tasks/TeamTaskStacks';
import { WorkspaceTabsParamList } from './types';
import { SharedContextTabBar } from './SharedContextTabBar';

const Tab = createBottomTabNavigator<WorkspaceTabsParamList>();
const ICONS = { TeamBoard: ClipboardList, MyTasks: ListTodo, TeamChat: MessageCircle, TeamCalendar: CalendarDays } as const;
const LABELS = { TeamBoard: 'Team Board', MyTasks: 'My Tasks', TeamChat: 'Team Chat', TeamCalendar: 'Team Calendar' } as const;

export function WorkspaceTabs() {
  return (
    <Tab.Navigator
      tabBar={(props) => <SharedContextTabBar {...props} />}
      screenOptions={({ route }) => ({
        headerShown: false,
        lazy: false,
        freezeOnBlur: true,
        tabBarLabel: LABELS[route.name],
        tabBarIcon: ({ color, focused }) => {
          const Icon = ICONS[route.name];
          return <Icon size={21} color={color} strokeWidth={focused ? 2.5 : 2} />;
        },
      })}>
      <Tab.Screen name="TeamBoard" component={TeamBoardStack} />
      <Tab.Screen name="MyTasks" component={MyTasksStack} />
      <Tab.Screen name="TeamChat" component={TeamChatRoot} />
      <Tab.Screen name="TeamCalendar" component={TeamCalendarRoot} />
    </Tab.Navigator>
  );
}
