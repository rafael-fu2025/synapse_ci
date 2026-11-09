import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { useNativeStackMotionOptions } from '../../navigation/useNativeStackMotionOptions';
import { TeamCalendarHomeScreen } from './screens/TeamCalendarHomeScreen';
import { TeamCalendarEventDetailScreen } from './screens/TeamCalendarEventDetailScreen';
import { TeamCalendarEventFormScreen } from './screens/TeamCalendarEventFormScreen';
import {
  TaskDeadlineNavigationProvider,
  type OpenTaskDeadline,
  type TeamCalendarStackParamList,
} from './navigation';

const Stack = createNativeStackNavigator<TeamCalendarStackParamList>();

export function TeamCalendarStack({
  onOpenTaskDeadline,
}: {
  onOpenTaskDeadline?: OpenTaskDeadline;
} = {}) {
  const motion = useNativeStackMotionOptions();
  return (
    <TaskDeadlineNavigationProvider onOpenTaskDeadline={onOpenTaskDeadline}>
      <Stack.Navigator screenOptions={motion}>
        <Stack.Screen name="TeamCalendarHome" component={TeamCalendarHomeScreen} />
        <Stack.Screen name="TeamCalendarEventDetail" component={TeamCalendarEventDetailScreen} />
        <Stack.Screen
          name="TeamCalendarEventForm"
          component={TeamCalendarEventFormScreen}
          options={{ gestureEnabled: false }}
        />
      </Stack.Navigator>
    </TaskDeadlineNavigationProvider>
  );
}

/** Alias intended for direct WorkspaceTabs integration. */
export const TeamCalendarRoot = TeamCalendarStack;
