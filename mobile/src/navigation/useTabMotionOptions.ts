import { Easing, useWindowDimensions } from 'react-native';
import type { BottomTabNavigationOptions } from '@react-navigation/bottom-tabs';
import { useTheme } from '../theme/ThemeProvider';
import { useReducedMotion } from '../lib/useReducedMotion';

export const TAB_TRANSITION_MS = 240;

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
    transitionSpec: { animation: 'timing', config: { duration: reduced ? 0 : TAB_TRANSITION_MS, easing: Easing.out(Easing.cubic) } },
  };
}
