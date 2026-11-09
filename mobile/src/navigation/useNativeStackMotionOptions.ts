import type { NativeStackNavigationOptions } from '@react-navigation/native-stack';
import { useReducedMotion } from '../lib/useReducedMotion';

/** Native push/pop timing and gestures remain owned by each platform. */
export function useNativeStackMotionOptions(): NativeStackNavigationOptions {
  const reduced = useReducedMotion();
  return { headerShown: false, animation: reduced ? 'none' : 'default' };
}
