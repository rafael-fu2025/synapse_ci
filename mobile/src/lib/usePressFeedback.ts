import { useCallback, useLayoutEffect, useRef } from 'react';
import { Animated } from 'react-native';
import { motion, motionEasing } from './motion';
import { useReducedMotion } from './useReducedMotion';

/** Move button content, never its hit target or activation time. */
export function usePressFeedback(disabled: boolean) {
  const reduced = useReducedMotion();
  const scale = useRef(new Animated.Value(1)).current;
  const animation = useRef<Animated.CompositeAnimation | null>(null);
  const revision = useRef(0);
  const alive = useRef(false);
  const latest = useRef({ disabled, reduced });
  latest.current = { disabled, reduced };

  useLayoutEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
      revision.current += 1;
      animation.current?.stop();
      scale.stopAnimation();
    };
  }, [scale]);

  useLayoutEffect(() => {
    if (!disabled && !reduced) { return; }
    revision.current += 1;
    animation.current?.stop();
    animation.current = null;
    scale.stopAnimation();
    scale.setValue(1);
  }, [disabled, reduced, scale]);

  const feedback = useCallback((pressed: boolean) => {
    if (!alive.current || latest.current.disabled || latest.current.reduced) { return; }
    const epoch = ++revision.current;
    animation.current?.stop();
    scale.stopAnimation(value => {
      if (!alive.current || epoch !== revision.current || latest.current.disabled || latest.current.reduced) { return; }
      scale.setValue(value);
      const next = Animated.timing(scale, {
        toValue: pressed ? motion.press.scale : 1,
        duration: pressed ? motion.press.pressMs : motion.press.releaseMs,
        easing: motionEasing, useNativeDriver: true, isInteraction: false,
      });
      animation.current = next;
      next.start(() => { if (epoch === revision.current) { animation.current = null; } });
    });
  }, [scale]);

  const onPressIn = useCallback(() => feedback(true), [feedback]);
  const onPressOut = useCallback(() => feedback(false), [feedback]);
  return { scale, onPressIn, onPressOut };
}
