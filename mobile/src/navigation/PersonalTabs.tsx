import React, { useEffect, useState } from 'react';
import { AccessibilityInfo, Easing, useWindowDimensions } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { CalendarDays, Home, ListChecks, UserRound } from 'lucide-react-native';
import { useTheme } from '../theme/ThemeProvider';
import { Theme, memoizeStyles } from '../theme/tokens';
import { PersonalTabsParamList } from './types';
import { HomeStack } from './stacks/HomeStack';
import { ProfileStack } from './stacks/ProfileStack';
import { QueueStack } from './stacks/QueueStack';
import { VisitsStack } from './stacks/VisitsStack';
import { SharedContextTabBar } from './SharedContextTabBar';

const Tab = createBottomTabNavigator<PersonalTabsParamList>();
const ICONS = { Home, Visits: CalendarDays, Queue: ListChecks, Profile: UserRound } as const;

export function PersonalTabs() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const { width } = useWindowDimensions();
  const [reduceMotion, setReduceMotion] = useState(true);

  useEffect(() => {
    let mounted = true;
    let changed = false;
    const subscription = AccessibilityInfo.addEventListener('reduceMotionChanged', enabled => {
      changed = true;
      setReduceMotion(enabled);
    });
    AccessibilityInfo.isReduceMotionEnabled().then(enabled => {
      if (mounted && !changed) { setReduceMotion(enabled); }
    }).catch(() => undefined);
    return () => { mounted = false; subscription.remove(); };
  }, []);

  return (
    <Tab.Navigator
      tabBar={(props) => <SharedContextTabBar {...props} />}
      screenOptions={({ route }) => ({
        headerShown: false,
        lazy: false,
        freezeOnBlur: true,
        animation: reduceMotion ? 'none' : 'shift',
        sceneStyle: styles.scene,
        sceneStyleInterpolator: reduceMotion ? undefined : ({ current }) => ({
          sceneStyle: { transform: [{ translateX: current.progress.interpolate({ inputRange: [-1, 0, 1], outputRange: [-width, 0, width], extrapolate: 'clamp' }) }] },
        }),
        transitionSpec: { animation: 'timing', config: { duration: reduceMotion ? 0 : 240, easing: Easing.out(Easing.cubic) } },
        tabBarIcon: ({ color, focused }) => {
          const Icon = ICONS[route.name];
          return <Icon size={21} color={color} strokeWidth={focused ? 2.5 : 2} />;
        },
      })}>
      <Tab.Screen name="Home" component={HomeStack} />
      <Tab.Screen name="Visits" component={VisitsStack} />
      <Tab.Screen name="Queue" component={QueueStack} />
      <Tab.Screen name="Profile" component={ProfileStack} />
    </Tab.Navigator>
  );
}

function createStyles(theme: Theme) {
  return { scene: { flex: 1, backgroundColor: theme.canvas, overflow: 'hidden' as const } };
}
const makeStyles = memoizeStyles(createStyles);
