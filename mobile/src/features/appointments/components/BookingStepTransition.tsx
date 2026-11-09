import React, { createContext, useCallback, useContext, useLayoutEffect, useRef, useState, useSyncExternalStore } from 'react';
import { Animated, AppState, Keyboard, StyleSheet, View, type LayoutChangeEvent } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { motion, motionEasing } from '../../../lib/motion';
import { useReducedMotion } from '../../../lib/useReducedMotion';
import { useTheme } from '../../../theme/ThemeProvider';
import { memoizeStyles, type Theme } from '../../../theme/tokens';
import { BOOKING_STEPS, type BookingStep } from '../booking';

type Navigation = NonNullable<React.ContextType<NonNullable<typeof NavigationContext>>> | undefined;
const OptionalNavigationContext = NavigationContext ?? createContext<Navigation>(undefined);
interface Stage {
  from: BookingStep | null;
  to: BookingStep;
  target: BookingStep;
  direction: number;
  reset: number;
  progress: Animated.Value;
}
function settled(step: BookingStep, reset: number): Stage {
  return { from: null, to: step, target: step, direction: 1, reset, progress: new Animated.Value(1) };
}
function order(step: BookingStep): number {
  return step === 'success' ? BOOKING_STEPS.length : BOOKING_STEPS.indexOf(step);
}

/** Only step bodies move; logical navigation, drafts and submission stay with BookingScreen. */
export function BookingStepTransition({ step, reset = 0, renderStep }: {
  step: BookingStep;
  reset?: number;
  renderStep: (value: BookingStep, active: boolean) => React.ReactNode;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const reduced = useReducedMotion();
  const navigation = useContext(OptionalNavigationContext);
  const [width, setWidth] = useState(0);
  const [stage, setStage] = useState(() => settled(step, reset));
  const alive = useRef(false);
  const revision = useRef(0);
  const animation = useRef<Animated.CompositeAnimation | null>(null);
  const current = useRef(stage);
  current.current = stage;

  if (stage.reset !== reset) {
    setStage(settled(step, reset));
  } else if (stage.target !== step) {
    setStage(stage.from !== null && (step === stage.from || step === stage.to)
      ? { ...stage, target: step }
      : { from: stage.target, to: step, target: step, direction: order(step) > order(stage.target) ? 1 : -1,
        reset, progress: new Animated.Value(0) });
  }

  const stop = useCallback(() => {
    revision.current += 1;
    animation.current?.stop();
    animation.current = null;
    const value = current.current;
    value.progress.stopAnimation();
    value.progress.setValue(value.target === value.to ? 1 : 0);
    if (alive.current && value.from !== null) { setStage(settled(value.target, value.reset)); }
  }, []);
  const subscribe = useCallback((changed: () => void) => {
    const app = AppState.addEventListener('change', () => {
      if (AppState.currentState !== 'active') { stop(); }
      changed();
    });
    const focus = navigation?.addListener('focus', changed);
    const blur = navigation?.addListener('blur', () => { stop(); changed(); });
    return () => { app.remove(); focus?.(); blur?.(); };
  }, [navigation, stop]);
  const snapshot = useCallback(() => (AppState.currentState == null || AppState.currentState === 'active') &&
    (!navigation || navigation.isFocused()), [navigation]);
  const visible = useSyncExternalStore(subscribe, snapshot, () => false);
  const latest = useRef({ reduced, visible, width });
  latest.current = { reduced, visible, width };
  const previousWidth = useRef(width);
  const previousStep = useRef({ step, reset });

  useLayoutEffect(() => {
    alive.current = true;
    return () => { alive.current = false; stop(); };
  }, [stop]);
  useLayoutEffect(() => {
    const previous = previousStep.current;
    previousStep.current = { step, reset };
    if (previous.step !== step || previous.reset !== reset) { Keyboard.dismiss(); }
  }, [reset, step]);
  useLayoutEffect(() => {
    const resized = previousWidth.current !== width;
    previousWidth.current = width;
    if (stage.from === null) { return; }
    if (reduced || !visible || width <= 0 || resized) { stop(); return; }
    const epoch = ++revision.current;
    const valid = () => alive.current && revision.current === epoch && current.current === stage &&
      !latest.current.reduced && latest.current.visible && latest.current.width === width;
    const target = stage.target === stage.to ? 1 : 0;
    animation.current?.stop();
    stage.progress.stopAnimation(value => {
      if (!valid()) { return; }
      const progress = Number.isFinite(value) ? Math.max(0, Math.min(1, value)) : target;
      const distance = Math.abs(target - progress);
      stage.progress.setValue(progress);
      if (distance <= 0.001) { setStage(settled(stage.target, stage.reset)); return; }
      const next = Animated.timing(stage.progress, {
        toValue: target, duration: Math.round(motion.context.sceneMs * distance), easing: motionEasing,
        useNativeDriver: true, isInteraction: false,
      });
      animation.current = next;
      next.start(({ finished }) => {
        if (!valid() || !finished) { return; }
        animation.current = null;
        setStage(settled(stage.target, stage.reset));
      });
    });
    return () => {
      revision.current += 1;
      animation.current?.stop();
      animation.current = null;
      stage.progress.stopAnimation();
    };
  }, [reduced, stage, stop, visible, width]);

  const onLayout = useCallback((event: LayoutChangeEvent) => {
    const value = event.nativeEvent.layout.width;
    if (Number.isFinite(value) && value >= 0) { setWidth(value); }
  }, []);
  const panels = stage.from === null ? [stage.to] : [stage.from, stage.to];
  return (
    <View testID="booking-step-viewport" style={styles.viewport} onLayout={onLayout}>
      {panels.map(value => {
        const active = value === step;
        const translateX = stage.from === null ? 0 : stage.progress.interpolate({
          inputRange: [0, 1], outputRange: value === stage.from ? [0, -stage.direction * width] : [stage.direction * width, 0],
          extrapolate: 'clamp',
        });
        return <Animated.View key={value} testID={`booking-step-panel-${value}`} style={[styles.panel, { transform: [{ translateX }] }]}
          pointerEvents={active ? 'auto' : 'none'} accessibilityElementsHidden={!active}
          importantForAccessibility={active ? 'auto' : 'no-hide-descendants'}>
          {renderStep(value, active)}
        </Animated.View>;
      })}
    </View>
  );
}

const makeStyles = memoizeStyles((theme: Theme) => StyleSheet.create({
  viewport: { flex: 1, overflow: 'hidden', backgroundColor: theme.canvas },
  panel: { position: 'absolute', top: 0, bottom: 0, left: 0, right: 0, backgroundColor: theme.canvas },
}));
