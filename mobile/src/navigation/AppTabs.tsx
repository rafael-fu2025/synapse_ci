import React, { useEffect, useState } from 'react';
import { AccessibilityInfo, Easing, TouchableOpacity, useWindowDimensions } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import type { BottomTabBarButtonProps } from '@react-navigation/bottom-tabs';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { CalendarDays, Home, ListChecks, UserRound } from 'lucide-react-native';
import { Theme, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';
import { AppTabsParamList } from './types';
import { HomeStack } from './stacks/HomeStack';
import { ProfileStack } from './stacks/ProfileStack';
import { QueueStack } from './stacks/QueueStack';
import { VisitsStack } from './stacks/VisitsStack';

const Tab = createBottomTabNavigator<AppTabsParamList>();

const TAB_ICONS = {
  Home: Home,
  Visits: CalendarDays,
  Queue: ListChecks,
  Profile: UserRound,
} as const;

function TabIcon({
  icon: Icon,
  color,
  focused,
}: {
  icon: (typeof TAB_ICONS)[keyof typeof TAB_ICONS];
  color: string;
  focused: boolean;
}) {
  return <Icon size={21} color={color} strokeWidth={focused ? 2.5 : 2} />;
}

// Avoid the default borderless Android ripple spilling over neighboring tabs.
function TabButton({ android_ripple: _androidRipple, ...props }: BottomTabBarButtonProps) {
  return <TouchableOpacity {...props} activeOpacity={0.7} />;
}

export function AppTabs() {
  const { theme } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const styles = makeStyles(theme);
  const [reduceMotion, setReduceMotion] = useState(true);

  useEffect(() => {
    let mounted = true;
    let preferenceChanged = false;
    const subscription = AccessibilityInfo.addEventListener('reduceMotionChanged', enabled => {
      preferenceChanged = true;
      setReduceMotion(enabled);
    });
    AccessibilityInfo.isReduceMotionEnabled().then(enabled => {
      if (mounted && !preferenceChanged) { setReduceMotion(enabled); }
    }).catch(() => { /* Keep transitions off if the preference cannot be read. */ });
    return () => {
      mounted = false;
      subscription.remove();
    };
  }, []);

  return (
    <Tab.Navigator
      screenOptions={({ route }) => ({
        headerShown: false,
        // Preserve each tab's state; the navigator keeps both scenes alive during transitions.
        lazy: false,
        freezeOnBlur: true,
        animation: reduceMotion ? 'none' : 'shift',
        sceneStyle: styles.scene,
        // Keep scenes opaque: cross-fading native stacks exposes overlapping cards and shadows.
        sceneStyleInterpolator: reduceMotion ? undefined : ({ current }) => ({
          sceneStyle: {
            transform: [{
              translateX: current.progress.interpolate({
                inputRange: [-1, 0, 1],
                outputRange: [-width, 0, width],
                extrapolate: 'clamp',
              }),
            }],
          },
        }),
        transitionSpec: {
          animation: 'timing',
          config: { duration: reduceMotion ? 0 : 240, easing: Easing.out(Easing.cubic) },
        },
        tabBarButton: TabButton,
        tabBarActiveTintColor: theme.brandIcon,
        tabBarInactiveTintColor: theme.ink400,
        tabBarStyle: [styles.bar, { height: 56 + insets.bottom, paddingBottom: Math.max(insets.bottom, 6) }],
        tabBarLabelStyle: styles.label,
        tabBarIcon: ({ color, focused }) => (
          <TabIcon icon={TAB_ICONS[route.name]} color={color} focused={focused} />
        ),
      })}>
      <Tab.Screen name="Home" component={HomeStack} options={{ tabBarLabel: 'Home' }} />
      <Tab.Screen name="Visits" component={VisitsStack} options={{ tabBarLabel: 'Visits' }} />
      <Tab.Screen name="Queue" component={QueueStack} options={{ tabBarLabel: 'Queue' }} />
      <Tab.Screen name="Profile" component={ProfileStack} options={{ tabBarLabel: 'Profile' }} />
    </Tab.Navigator>
  );
}

function createStyles(theme: Theme) {
  return {
    scene: { flex: 1, backgroundColor: theme.canvas, overflow: 'hidden' as const },
    bar: {

      paddingTop: 5,
      paddingHorizontal: 8,
      backgroundColor: theme.surface,
      borderTopColor: theme.line,
      borderTopWidth: 1,
    },
    label: { fontSize: 9.5, fontWeight: '600' as const },
  };
}

const makeStyles = memoizeStyles(createStyles);
