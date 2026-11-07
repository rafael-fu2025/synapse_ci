import React from 'react';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { CalendarDays, ClipboardList, ListTodo, MessageCircle } from 'lucide-react-native';
import { TeamChatRoot } from '../features/team-chat';
import { TeamCalendarRoot } from '../features/team-calendar/TeamCalendarStack';
import { MyTasksStack, TeamBoardStack } from '../features/team-tasks/TeamTaskStacks';
import { WorkspaceTabsParamList } from './types';
import { SharedContextTabBar } from './SharedContextTabBar';
import { useTabMotionOptions } from './useTabMotionOptions';
import { useWorkspace } from '../features/workspace/WorkspaceContext';

const Tab = createBottomTabNavigator<WorkspaceTabsParamList>();
const ICONS = { TeamBoard: ClipboardList, MyTasks: ListTodo, TeamChat: MessageCircle, TeamCalendar: CalendarDays } as const;
const LABELS = { TeamBoard: 'Team Board', MyTasks: 'My Tasks', TeamChat: 'Team Chat', TeamCalendar: 'Team Calendar' } as const;

export function WorkspaceTabs() {
  const motion = useTabMotionOptions();
  const { activeUnit, workEnabled } = useWorkspace();
  const enabled = activeUnit?.features;
  if (workEnabled === false) { return null; }
  return (
    <Tab.Navigator
      key={activeUnit?.unit ?? 'no-unit'}
      tabBar={(props) => <SharedContextTabBar {...props} />}
      screenOptions={({ route }) => ({
        ...motion,
        tabBarLabel: LABELS[route.name],
        tabBarIcon: ({ color, focused }) => {
          const Icon = ICONS[route.name];
          return <Icon size={21} color={color} strokeWidth={focused ? 2.5 : 2} />;
        },
      })}>
      {enabled?.team_board !== false ? <Tab.Screen name="TeamBoard" component={TeamBoardStack} /> : null}
      {enabled?.my_tasks !== false ? <Tab.Screen name="MyTasks" component={MyTasksStack} /> : null}
      {enabled?.team_chat !== false ? <Tab.Screen name="TeamChat" component={TeamChatRoot} /> : null}
      {enabled?.team_calendar !== false ? <Tab.Screen name="TeamCalendar" component={TeamCalendarRoot} /> : null}
    </Tab.Navigator>
  );
}
