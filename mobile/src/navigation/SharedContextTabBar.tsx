import React, { useEffect, useRef } from 'react';
import { Animated, Easing, Pressable, StyleSheet, Text, View } from 'react-native';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { ArrowLeftRight } from 'lucide-react-native';
import { useWorkspace } from '../features/workspace/WorkspaceContext';
import { Theme, font, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';
import { useReducedMotion } from '../lib/useReducedMotion';
import { useKeyboardVisible } from '../lib/useKeyboardVisible';
import { useOverlayActivity } from '../components/OverlayProvider';
import { TAB_TRANSITION_MS } from './useTabMotionOptions';

/** Four real routes plus an optional standalone context action in the center. */
export function SharedContextTabBar({ state, descriptors, navigation, insets }: BottomTabBarProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const workspace = useWorkspace();
  const reduced = useReducedMotion();
  const keyboardVisible = useKeyboardVisible();
  const overlayActive = useOverlayActivity();
  const progress = useRef(new Animated.Value(workspace.mode === 'workspace' ? 1 : 0)).current;
  const pressedScale = useRef(new Animated.Value(1)).current;
  const switching = useRef(false);
  const switchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const contextLabel = workspace.mode === 'personal' ? 'Work' : 'Personal';
  const contextAccessibilityLabel = workspace.mode === 'personal'
    ? 'Switch to Team Workspace'
    : 'Switch to Personal Portal';
  const locked = overlayActive || workspace.pendingDiscard || workspace.hasPendingSubmission || workspace.unitPickerVisible;

  useEffect(() => {
    progress.stopAnimation();
    const toValue = workspace.mode === 'workspace' ? 1 : 0;
    if (reduced) { progress.setValue(toValue); return undefined; }
    const animation = Animated.timing(progress, {
      toValue, duration: TAB_TRANSITION_MS, easing: Easing.out(Easing.cubic), useNativeDriver: true,
    });
    animation.start();
    return () => animation.stop();
  }, [progress, reduced, workspace.mode]);

  useEffect(() => () => {
    if (switchTimer.current !== null) { clearTimeout(switchTimer.current); }
  }, []);

  function pressFeedback(toValue: number) {
    pressedScale.stopAnimation();
    if (reduced) { pressedScale.setValue(1); return; }
    Animated.timing(pressedScale, { toValue, duration: 100, useNativeDriver: true }).start();
  }

  const routeButtons = state.routes.map((route, index) => {
    const focused = state.index === index;
    const options = descriptors[route.key].options;
    const rawLabel = options.tabBarLabel ?? options.title ?? route.name;
    const label = typeof rawLabel === 'string' ? rawLabel : route.name;
    const color = focused ? theme.brandIcon : theme.ink400;
    const icon = options.tabBarIcon?.({ focused, color, size: 21 });
    return (
      <Pressable
        key={route.key}
        accessibilityRole="tab"
        accessibilityLabel={options.tabBarAccessibilityLabel ?? label}
        accessibilityState={{ selected: focused, disabled: locked }}
        disabled={locked}
        onPress={() => {
          if (locked) { return; }
          const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
          if (!focused && !event.defaultPrevented) { navigation.navigate(route.name, route.params); }
        }}
        onLongPress={() => navigation.emit({ type: 'tabLongPress', target: route.key })}
        style={({ pressed }) => [styles.route, pressed && styles.pressed, locked && styles.disabled]}>
        {icon}
        <Text numberOfLines={2} style={[styles.label, { color }]}>{label}</Text>
      </Pressable>
    );
  });

  if (workspace.canSwitchContext) {
    routeButtons.splice(2, 0,
      <Pressable
        key="context-action"
        accessibilityRole="button"
        accessibilityLabel={contextAccessibilityLabel}
        accessibilityState={{ disabled: locked }}
        disabled={locked}
        onPressIn={() => pressFeedback(0.92)}
        onPressOut={() => pressFeedback(1)}
        onPress={() => {
          if (switching.current || locked) { return; }
          switching.current = true;
          switchTimer.current = setTimeout(() => { switching.current = false; }, TAB_TRANSITION_MS);
          // Entry owns the guard so opening a multi-unit picker is never destructive.
          if (workspace.mode === 'personal') { workspace.enterWorkspace(); }
          else { workspace.enterPersonal(); }
        }}
        style={({ pressed }) => [styles.context, pressed && styles.pressed, locked && styles.disabled]}>
        <Animated.View style={[styles.contextIcon, { transform: [
          { scale: pressedScale },
          { rotate: progress.interpolate({ inputRange: [0, 1], outputRange: ['0deg', '180deg'] }) },
        ] }]}>
          <ArrowLeftRight size={21} color="#ffffff" strokeWidth={2.4} />
        </Animated.View>
        <Text style={styles.contextLabel}>{contextLabel}</Text>
      </Pressable>,
    );
  }

  if (keyboardVisible) { return null; }
  return (
    <View
      accessibilityRole="tablist"
      style={[styles.bar, { minHeight: 56 + insets.bottom, paddingBottom: Math.max(6, insets.bottom) }]}>
      {routeButtons}
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    bar: { flexDirection: 'row', alignItems: 'flex-start', paddingTop: 5, paddingHorizontal: 6, backgroundColor: theme.surface, borderTopColor: theme.line, borderTopWidth: 1 },
    route: { flex: 1, minWidth: 44, minHeight: 48, alignItems: 'center', justifyContent: 'center', gap: 2 },
    context: { flex: 1, minWidth: 52, minHeight: 48, alignItems: 'center', justifyContent: 'center', gap: 1 },
    contextIcon: { width: 40, height: 32, borderRadius: 16, alignItems: 'center', justifyContent: 'center', backgroundColor: theme.brand },
    contextLabel: { color: theme.brandText, fontFamily: font.body, fontWeight: '800', fontSize: 9.5 },
    label: { fontFamily: font.body, fontWeight: '600', fontSize: 9.5, textAlign: 'center' },
    pressed: { opacity: 0.68 },
    disabled: { opacity: 0.5 },
  });
}
const makeStyles = memoizeStyles(createStyles);
