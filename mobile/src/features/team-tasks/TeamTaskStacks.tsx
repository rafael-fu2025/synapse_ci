import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import type { MyTasksStackParamList, TeamTasksStackParamList } from '../../navigation/types';
import { TeamTaskDetailScreen } from './screens/TeamTaskDetailScreen';
import { TeamTaskFormScreen } from './screens/TeamTaskFormScreen';
import { TeamTaskListScreen } from './screens/TeamTaskListScreen';

const BoardStack = createNativeStackNavigator<TeamTasksStackParamList>();
const MineStack = createNativeStackNavigator<MyTasksStackParamList>();

export function TeamBoardStack() {
  return (
    <BoardStack.Navigator screenOptions={{ headerShown: false }}>
      <BoardStack.Screen name="TeamTaskList">{() => <TeamTaskListScreen scope="team" />}</BoardStack.Screen>
      <BoardStack.Screen name="TeamTaskDetail" component={TeamTaskDetailScreen} />
      <BoardStack.Screen name="TeamTaskForm" component={TeamTaskFormScreen} options={{ gestureEnabled: false }} />
    </BoardStack.Navigator>
  );
}

export function MyTasksStack() {
  return (
    <MineStack.Navigator screenOptions={{ headerShown: false }}>
      <MineStack.Screen name="MyTaskList">{() => <TeamTaskListScreen scope="mine" />}</MineStack.Screen>
      <MineStack.Screen name="TeamTaskDetail" component={TeamTaskDetailScreen} />
      <MineStack.Screen name="TeamTaskForm" component={TeamTaskFormScreen} options={{ gestureEnabled: false }} />
    </MineStack.Navigator>
  );
}
