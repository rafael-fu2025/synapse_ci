import { useWindowDimensions } from 'react-native';
import type { BottomTabNavigationOptions } from '@react-navigation/bottom-tabs';
import { useTheme } from '../theme/ThemeProvider';
import { motion, motionEasing } from '../lib/motion';
import { useReducedMotion } from '../lib/useReducedMotion';

export const TAB_TRANSITION_MS = motion.context.sceneMs;

/** Personal and Work share opaque, full-width motion and native accessibility policy. */
export function useTabMotionOptions(): BottomTabNavigationOptions {
  const { width } = useWindowDimensions();
  const { theme } = useTheme();
  const reduced = useReducedMotion();
  return {
    headerShown: false,
    lazy: false,
    freezeOnBlur: true,
    animation: reduced ? 'none' : 'shift',
    sceneStyle: { flex: 1, backgroundColor: theme.canvas, overflow: 'hidden' },
    sceneStyleInterpolator: reduced ? undefined : ({ current }) => ({
      sceneStyle: {
        transform: [{ translateX: current.progress.interpolate({ inputRange: [-1, 0, 1], outputRange: [-width, 0, width], extrapolate: 'clamp' }) }],
      },
    }),
    transitionSpec: { animation: 'timing', config: { duration: reduced ? 0 : TAB_TRANSITION_MS, easing: motionEasing } },
  };
}

/** Peer destinations fade without inheriting the outer context slide. */
export function usePeerTabMotionOptions(): BottomTabNavigationOptions {
  const { theme } = useTheme();
  const reduced = useReducedMotion();
  return {
    headerShown: false,
    lazy: true,
    freezeOnBlur: true,
    animation: reduced ? 'none' : 'fade',
    sceneStyle: { flex: 1, backgroundColor: theme.canvas, overflow: 'hidden' },
    sceneStyleInterpolator: reduced ? undefined : ({ current }) => ({
      sceneStyle: { opacity: current.progress.interpolate({ inputRange: [-1, 0, 1], outputRange: [0, 1, 0], extrapolate: 'clamp' }) },
    }),
    transitionSpec: { animation: 'timing', config: { duration: reduced ? 0 : motion.tabs.durationMs, easing: motionEasing } },
  };
}
