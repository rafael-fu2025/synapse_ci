import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { QueueBoardScreen } from '../../features/queue/screens/QueueBoardScreen';
import { QueueStackParamList } from '../types';
import { useNativeStackMotionOptions } from '../useNativeStackMotionOptions';

const Stack = createNativeStackNavigator<QueueStackParamList>();

export function QueueStack() {
  const motion = useNativeStackMotionOptions();
  return (
    <Stack.Navigator screenOptions={motion}>
      <Stack.Screen name="QueueBoard" component={QueueBoardScreen} />
    </Stack.Navigator>
  );
}
