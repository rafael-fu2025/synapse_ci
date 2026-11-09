import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Animated, Pressable, StyleSheet } from 'react-native';
import { ArrowDown } from 'lucide-react-native';
import { motion, motionEasing } from '../../../lib/motion';
import { useReducedMotion } from '../../../lib/useReducedMotion';
import { Theme, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';

/** Retain only the visual exit; hidden controls stop accepting input immediately. */
export function ChatScrollButton({ visible, disabled, busy, history, onPress }: {
  visible: boolean; disabled: boolean; busy: boolean; history: boolean; onPress: () => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const reduced = useReducedMotion();
  const opacity = useRef(new Animated.Value(visible ? 1 : 0)).current;
  const [retained, setRetained] = useState(visible);
  const revision = useRef(0);
  const alive = useRef(false);
  const latest = useRef({ visible, disabled, onPress });
  latest.current = { visible, disabled, onPress };

  useLayoutEffect(() => {
    alive.current = true;
    return () => { alive.current = false; revision.current += 1; opacity.stopAnimation(); };
  }, [opacity]);
  useEffect(() => {
    const epoch = ++revision.current;
    opacity.stopAnimation();
    if (visible) { setRetained(true); }
    if (reduced) {
      opacity.setValue(visible ? 1 : 0);
      setRetained(visible);
      return;
    }
    const animation = Animated.timing(opacity, {
      toValue: visible ? 1 : 0, duration: motion.visibility.durationMs,
      easing: motionEasing, useNativeDriver: true, isInteraction: false,
    });
    animation.start(({ finished }) => {
      if (finished && alive.current && revision.current === epoch && !latest.current.visible) { setRetained(false); }
    });
    return () => { revision.current += 1; animation.stop(); };
  }, [opacity, reduced, visible]);
  const activate = useCallback(() => {
    if (alive.current && latest.current.visible && !latest.current.disabled) { latest.current.onPress(); }
  }, []);
  if (!visible && !retained) { return null; }
  return <Animated.View style={[styles.host, { opacity }]} pointerEvents={visible ? 'box-none' : 'none'}
    accessibilityElementsHidden={!visible} importantForAccessibility={visible ? 'auto' : 'no-hide-descendants'}>
    <Pressable testID="team-chat-scroll-bottom" accessibilityRole="button" accessibilityLabel="Scroll to latest messages"
      accessibilityHint={history ? 'Returns to the live conversation and scrolls to the bottom.' : 'Scrolls to the bottom without changing your message.'}
      accessibilityState={{ disabled: disabled || !visible, busy }} disabled={disabled || !visible} onPress={activate}
      style={({ pressed }) => [styles.button, disabled && styles.disabled, pressed && styles.pressed]}>
      <ArrowDown size={22} color={theme.brandIcon} />
    </Pressable>
  </Animated.View>;
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    host: { position: 'absolute', right: 16, bottom: 12, width: 48, height: 48 },
    button: { width: 48, height: 48, borderRadius: 24, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface, alignItems: 'center', justifyContent: 'center' },
    disabled: { opacity: 0.45 }, pressed: { opacity: 0.68 },
  });
}
const makeStyles = memoizeStyles(createStyles);
