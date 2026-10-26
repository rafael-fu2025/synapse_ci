import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { QueueBoardScreen } from '../../features/queue/screens/QueueBoardScreen';
import { QueueStackParamList } from '../types';

const Stack = createNativeStackNavigator<QueueStackParamList>();

export function QueueStack() {
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      <Stack.Screen name="QueueBoard" component={QueueBoardScreen} />
    </Stack.Navigator>
  );
}
