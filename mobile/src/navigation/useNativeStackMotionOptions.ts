import { Platform } from 'react-native';
import type { NativeStackNavigationOptions } from '@react-navigation/native-stack';
import { useReducedMotion } from '../lib/useReducedMotion';

/** Android uses translation without fading elevated surfaces; native timing stays native. */
export function useNativeStackMotionOptions(): NativeStackNavigationOptions {
  const reduced = useReducedMotion();
  return { headerShown: false, animation: reduced ? 'none' : Platform.OS === 'android' ? 'slide_from_right' : 'default' };
}
