import React, { createContext, useCallback, useContext, useLayoutEffect, useRef, useSyncExternalStore } from 'react';
import { Animated, AppState, Pressable, StyleSheet, View } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { useBottomSheetPresentationActive } from '../../../components/BottomSheet';
import { useOverlayActivity } from '../../../components/OverlayProvider';
import { motionEasing } from '../../../lib/motion';
import { usePressFeedback } from '../../../lib/usePressFeedback';
import { useReducedMotion } from '../../../lib/useReducedMotion';
import { useTheme } from '../../../theme/ThemeProvider';
import { memoizeStyles, type, type Theme } from '../../../theme/tokens';

type Navigation = NonNullable<React.ContextType<NonNullable<typeof NavigationContext>>> | undefined;
const OptionalNavigationContext = NavigationContext ?? createContext<Navigation>(undefined);
const SELECTION_MS = 180;

export function CalendarSegmentedControl<T extends string>({ label, values, selected, labelFor, onChange, enabled = true }: {
  label: string;
  values: readonly T[];
  selected: T;
  labelFor: (value: T) => string;
  onChange: (value: T) => void;
  enabled?: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const sheetActive = useBottomSheetPresentationActive();
  const overlay = useOverlayActivity();
  // A filter presenter stays interactive while the underlying query is paused.
  const interactive = enabled && sheetActive !== false;
  const motionActive = interactive && (sheetActive === true || !overlay);
  return (
    <View accessibilityRole="radiogroup" accessibilityLabel={label} style={styles.segments}>
      {values.map(value => <CalendarSegmentOption key={value} label={`${label}: ${labelFor(value)}`} text={labelFor(value)}
        active={value === selected} enabled={interactive} motionActive={motionActive} onPress={() => onChange(value)} />)}
    </View>
  );
}

function useCalendarOptionMotion(active: boolean, enabled: boolean, motionActive: boolean) {
  const reduced = useReducedMotion();
  const navigation = useContext(OptionalNavigationContext);
  const progress = useRef(new Animated.Value(active ? 1 : 0)).current;
  const animation = useRef<Animated.CompositeAnimation | null>(null);
  const alive = useRef(false);
  const revision = useRef(0);
  const previous = useRef(active);
  const latest = useRef({ active, enabled, animate: false });
  const stop = useCallback(() => {
    revision.current += 1;
    animation.current?.stop();
    animation.current = null;
    progress.stopAnimation();
  }, [progress]);
  const snap = useCallback(() => {
    stop();
    progress.setValue(latest.current.active ? 1 : 0);
  }, [progress, stop]);
  const foreground = useCallback(() => (AppState.currentState == null || AppState.currentState === 'active') &&
    (!navigation || navigation.isFocused()), [navigation]);
  const subscribe = useCallback((changed: () => void) => {
    const app = AppState.addEventListener('change', state => {
      if (state !== 'active') { snap(); }
      changed();
    });
    const focus = navigation?.addListener('focus', changed);
    const blur = navigation?.addListener('blur', () => { snap(); changed(); });
    return () => { app.remove(); focus?.(); blur?.(); };
  }, [navigation, snap]);
  const visible = useSyncExternalStore(subscribe, foreground, () => false);
  const interactive = enabled && visible;
  const animate = interactive && motionActive && !reduced;
  latest.current = { active, enabled, animate };
  const feedback = usePressFeedback(!animate);

  useLayoutEffect(() => {
    alive.current = true;
    return () => { alive.current = false; stop(); };
  }, [stop]);
  useLayoutEffect(() => {
    const changed = previous.current !== active;
    previous.current = active;
    if (!animate) { snap(); return undefined; }
    // Mount, refetch, theme, and returning to a presenter never replay selection.
    if (!changed) { return undefined; }
    const epoch = ++revision.current;
    const target = active ? 1 : 0;
    const valid = () => alive.current && revision.current === epoch && latest.current.active === active &&
      latest.current.animate && foreground();
    animation.current?.stop();
    animation.current = null;
    progress.stopAnimation(value => {
      if (!valid()) { return; }
      const current = Number.isFinite(value) ? Math.max(0, Math.min(1, value)) : target;
      const distance = Math.abs(target - current);
      progress.setValue(current);
      if (distance <= 0.001) { progress.setValue(target); return; }
      const next = Animated.timing(progress, {
        toValue: target, duration: Math.round(SELECTION_MS * distance), easing: motionEasing,
        useNativeDriver: true, isInteraction: false,
      });
      animation.current = next;
      next.start(({ finished }) => {
        if (finished && valid()) { animation.current = null; }
      });
    });
    return stop;
  }, [active, animate, foreground, progress, snap, stop]);

  const canPress = () => alive.current && latest.current.enabled && foreground();
  return { progress, feedback, interactive, canPress };
}

function CalendarSegmentOption({ label, text, active, enabled, motionActive, onPress }: {
  label: string;
  text: string;
  active: boolean;
  enabled: boolean;
  motionActive: boolean;
  onPress: () => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const { progress, feedback, interactive, canPress } = useCalendarOptionMotion(active, enabled, motionActive);
  return (
    <Pressable accessible accessibilityRole="radio" accessibilityLabel={label}
      accessibilityState={{ checked: active, disabled: !interactive }} disabled={!interactive}
      onPress={() => { if (canPress()) { onPress(); } }} onPressIn={feedback.onPressIn} onPressOut={feedback.onPressOut}
      style={styles.segment}>
      <Animated.View pointerEvents="none" accessible={false} accessibilityElementsHidden importantForAccessibility="no-hide-descendants"
        style={[styles.content, { transform: [{ scale: feedback.scale }] }]}>
        <Animated.View style={[StyleSheet.absoluteFill, styles.pill, {
          opacity: progress,
          transform: [{ scale: progress.interpolate({ inputRange: [0, 1], outputRange: [0.98, 1], extrapolate: 'clamp' }) }],
        }]} />
        <View style={styles.labels}>
          <Animated.Text accessible={false} importantForAccessibility="no" style={[styles.label, {
            opacity: progress.interpolate({ inputRange: [0, 1], outputRange: [1, 0], extrapolate: 'clamp' }),
          }]}>{text}</Animated.Text>
          <Animated.Text accessible={false} accessibilityElementsHidden importantForAccessibility="no-hide-descendants"
            style={[StyleSheet.absoluteFill, styles.label, styles.selectedLabel, { opacity: progress }]}>{text}</Animated.Text>
        </View>
      </Animated.View>
    </Pressable>
  );
}

const makeStyles = memoizeStyles((theme: Theme) => StyleSheet.create({
  segments: { minHeight: 44, flexDirection: 'row', padding: 3, borderRadius: 13, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
  segment: { minHeight: 44, minWidth: 0, flex: 1, borderRadius: 10 },
  content: { minHeight: 44, flexGrow: 1, justifyContent: 'center', paddingHorizontal: 6, paddingVertical: 8, borderRadius: 10 },
  pill: { borderRadius: 10, backgroundColor: theme.brand },
  labels: { minWidth: 0 },
  label: { ...type.caption, textAlign: 'center', color: theme.ink500, textTransform: 'capitalize' },
  selectedLabel: { color: '#ffffff' },
}));
