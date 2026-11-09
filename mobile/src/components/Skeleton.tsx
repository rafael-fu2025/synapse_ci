import React, { createContext, useCallback, useContext, useEffect, useRef, useSyncExternalStore } from 'react';
import { Animated, AppState, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { useOverlayActivity } from './OverlayProvider';
import { useReducedMotion } from '../lib/useReducedMotion';
import { useTheme } from '../theme/ThemeProvider';
import { memoizeStyles, type Theme } from '../theme/tokens';

type Navigation = NonNullable<React.ContextType<NonNullable<typeof NavigationContext>>> | undefined;
const OptionalNavigationContext = NavigationContext ?? createContext<Navigation>(undefined);
export type SkeletonVariant = 'card' | 'list' | 'detail' | 'form' | 'chat' | 'queue' | 'calendar';

/** Decorative loading geometry never replaces already loaded content or accepts input. */
export function Skeleton({ label, variant = 'list', rows = 3, active = true, style, testID }: {
  label: string;
  variant?: SkeletonVariant;
  rows?: number;
  active?: boolean;
  style?: StyleProp<ViewStyle>;
  testID?: string;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const reduced = useReducedMotion();
  const navigation = useContext(OptionalNavigationContext);
  const covered = useOverlayActivity();
  const subscribe = useCallback((changed: () => void) => {
    const app = AppState.addEventListener('change', changed);
    const focus = navigation?.addListener('focus', changed);
    const blur = navigation?.addListener('blur', changed);
    return () => { app.remove(); focus?.(); blur?.(); };
  }, [navigation]);
  const snapshot = useCallback(() => (AppState.currentState == null || AppState.currentState === 'active') &&
    (!navigation || navigation.isFocused()), [navigation]);
  const visible = useSyncExternalStore(subscribe, snapshot, () => false);
  const opacity = useRef(new Animated.Value(1)).current;
  useEffect(() => {
    if (!active || !visible || covered || reduced) { opacity.setValue(1); return; }
    const animation = Animated.loop(Animated.sequence([
      Animated.timing(opacity, { toValue: 0.55, duration: 700, useNativeDriver: true, isInteraction: false }),
      Animated.timing(opacity, { toValue: 1, duration: 700, useNativeDriver: true, isInteraction: false }),
    ]));
    animation.start();
    return () => { animation.stop(); opacity.stopAnimation(); opacity.setValue(1); };
  }, [active, covered, opacity, reduced, visible]);
  const count = Math.max(1, Math.min(6, Number.isFinite(rows) ? Math.round(rows) : 3));
  const block = (height: number, width: ViewStyle['width'], round = false) => {
    const shape = { height, width, borderRadius: round ? height / 2 : 5 };
    return <View style={[styles.block, shape]} />;
  };
  const card = (index: number) => (
    <View key={index} style={styles.card}>
      {block(36, 36, true)}
      <View style={styles.copy}>{block(14, '68%')}{block(10, '90%')}{block(10, '54%')}</View>
    </View>
  );
  return (
    <View testID={testID ?? `skeleton-${variant}`} style={[styles.host, variant === 'chat' && styles.chatHost, style]}
      accessible accessibilityRole="progressbar" accessibilityLabel={label} accessibilityState={{ busy: true }} pointerEvents="none">
      <Animated.View style={[variant === 'chat' && styles.chat, { opacity }]} accessible={false}
        accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
        {variant === 'chat' ? Array.from({ length: count }, (_, index) => (
          <View key={index} style={[styles.bubble, index % 2 !== 0 && styles.ownBubble]}>{block(12, '72%')}{block(10, '92%')}{block(10, '50%')}</View>
        )) : variant === 'detail' || variant === 'form' ? (
          <View style={styles.detail}>
            {block(40, 40, true)}{block(20, '72%')}{block(12, '48%')}
            {Array.from({ length: count }, (_, index) => <View key={index} style={styles.field}>{block(10, '28%')}{block(variant === 'form' ? 40 : 16, '100%')}</View>)}
          </View>
        ) : variant === 'queue' ? (
          <View style={styles.detail}>{block(16, '45%')}{block(42, '65%')}{block(12, '72%')}{card(0)}</View>
        ) : variant === 'calendar' ? (
          <View style={styles.detail}>{block(18, '55%')}<View style={styles.week}>{Array.from({ length: 7 }, (_, index) => <View key={index} style={styles.day}>{block(34, '100%')}</View>)}</View>{Array.from({ length: count }, (_, index) => card(index))}</View>
        ) : Array.from({ length: variant === 'card' ? 1 : count }, (_, index) => card(index))}
      </Animated.View>
    </View>
  );
}

const makeStyles = memoizeStyles((theme: Theme) => StyleSheet.create({
  host: { width: '100%' },
  block: { backgroundColor: theme.line },
  card: { minHeight: 104, flexDirection: 'row', alignItems: 'center', gap: 14, padding: 16, marginBottom: 12, borderRadius: 15, backgroundColor: theme.surface },
  copy: { flex: 1, gap: 10 },
  detail: { gap: 16, padding: 18, borderRadius: 15, backgroundColor: theme.surface, minHeight: 228 },
  field: { gap: 10, paddingVertical: 8 },
  chatHost: { flex: 1, backgroundColor: theme.canvas },
  chat: { flex: 1, justifyContent: 'flex-end', padding: 16, gap: 14 },
  bubble: { width: '72%', padding: 14, gap: 10, borderRadius: 16, backgroundColor: theme.surface },
  ownBubble: { alignSelf: 'flex-end', width: '62%' },
  week: { flexDirection: 'row', gap: 6 },
  day: { flex: 1 },
}));
