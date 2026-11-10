import React, { useContext, useEffect, useRef, useState } from 'react';
import { Animated, Easing, Pressable, StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import { BottomTabBarHeightCallbackContext } from '@react-navigation/bottom-tabs';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { BriefcaseBusiness, Home } from 'lucide-react-native';
import { useWorkspace } from '../features/workspace/WorkspaceContext';
import type { AppContextMode } from '../features/workspace/WorkspaceContext';
import { Theme, font, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';
import { useReducedMotion } from '../lib/useReducedMotion';
import { motion } from '../lib/motion';
import { useKeyboardVisible } from '../lib/useKeyboardVisible';
import { useOverlayActivity } from '../components/OverlayProvider';
import { useSystemNavigationBar } from '../components/SystemNavigationBar';
import { CONTEXT_BAR_RAISED_SPACE } from './contextTabBarMetrics';
import { TAB_TRANSITION_MS } from './useTabMotionOptions';

export const CONTEXT_BAR_TRANSITION_MS = motion.context.barMs;
const CTA_SIZE = 56;
const CTA_RING_SIZE = 64;
const CTA_SLOT = 76;

type ContextBindings = Partial<Record<AppContextMode, BottomTabBarProps>>;
type Props = BottomTabBarProps & { available?: boolean; contextBindings?: ContextBindings };

/** One anchored bar; only its decorative layers animate between accepted contexts. */
export function SharedContextTabBar({ available = true, contextBindings, ...props }: Props) {
  const { state, descriptors, navigation, insets } = props;
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const workspace = useWorkspace();
  const mode = workspace.mode === 'workspace' || workspace.personalEnabled === false ? 'workspace' : 'personal';
  const reduced = useReducedMotion();
  const keyboardVisible = useKeyboardVisible();
  useSystemNavigationBar(mode, !keyboardVisible);
  const overlayActive = useOverlayActivity();
  const reportHeight = useContext(BottomTabBarHeightCallbackContext);
  const window = useWindowDimensions();
  const captionHeight = Math.max(18, Math.ceil(14 * window.fontScale));
  const rowHeight = Math.max(62, 37 + Math.ceil(14 * window.fontScale) * 2,
    workspace.canSwitchContext ? CTA_RING_SIZE + 2 + captionHeight - CONTEXT_BAR_RAISED_SPACE + 6 : 0);
  const [layout, setLayout] = useState({ width: window.width, height: rowHeight + 2 + Math.max(6, insets.bottom) });
  const progress = useRef(new Animated.Value(mode === 'workspace' ? 1 : 0)).current;
  const pressedScale = useRef(new Animated.Value(1)).current;
  const animation = useRef<Animated.CompositeAnimation | null>(null);
  const animationEpoch = useRef(0);
  const alive = useRef(true);
  const switching = useRef(false);
  const switchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const retainedBindings = useRef<ContextBindings>({});
  if (contextBindings) { retainedBindings.current = contextBindings; }
  else if (available) { retainedBindings.current[mode] = props; }
  const locked = !available || keyboardVisible || overlayActive || workspace.pendingDiscard || workspace.hasPendingSubmission || workspace.unitPickerVisible;
  const latest = useRef({ mode, locked, reduced, workspace, state, navigation });
  latest.current = { mode, locked, reduced, workspace, state, navigation };
  const raised = workspace.canSwitchContext ? CONTEXT_BAR_RAISED_SPACE : 0;
  const centerX = (layout.width + insets.left - insets.right) / 2;
  const originY = CTA_RING_SIZE / 2 - CONTEXT_BAR_RAISED_SPACE;
  const radius = Math.hypot(Math.max(centerX, layout.width - centerX), Math.max(originY, layout.height - originY)) + 1;
  const personalOpacity = progress.interpolate({ inputRange: [0, 0.16, 0.48, 1], outputRange: [1, 1, 0, 0], extrapolate: 'clamp' });
  const workOpacity = progress.interpolate({ inputRange: [0, 0.58, 0.9, 1], outputRange: [0, 0, 1, 1], extrapolate: 'clamp' });
  const ctaPersonalOpacity = progress.interpolate({ inputRange: [0, 0.25, 0.75, 1], outputRange: [1, 1, 0, 0], extrapolate: 'clamp' });
  const ctaWorkOpacity = progress.interpolate({ inputRange: [0, 0.25, 0.75, 1], outputRange: [0, 0, 1, 1], extrapolate: 'clamp' });

  useEffect(() => {
    const epoch = ++animationEpoch.current;
    animation.current?.stop();
    const toValue = mode === 'workspace' ? 1 : 0;
    progress.stopAnimation(value => {
      if (epoch !== animationEpoch.current) { return; }
      if (reduced || Math.abs(toValue - value) < 0.001) { progress.setValue(toValue); return; }
      const next = Animated.timing(progress, {
        toValue, duration: Math.max(160, CONTEXT_BAR_TRANSITION_MS * Math.abs(toValue - value)),
        easing: Easing.inOut(Easing.cubic), useNativeDriver: true,
      });
      animation.current = next;
      next.start(() => { if (epoch === animationEpoch.current) { animation.current = null; } });
    });
    return () => { animationEpoch.current += 1; animation.current?.stop(); progress.stopAnimation(); };
  }, [mode, progress, reduced]);

  useEffect(() => {
    if (reduced) { pressedScale.stopAnimation(); pressedScale.setValue(1); }
  }, [pressedScale, reduced]);

  useEffect(() => {
    if (keyboardVisible) { reportHeight?.(0); }
  }, [keyboardVisible, reportHeight]);

  useEffect(() => {
    if (switchTimer.current !== null) { clearTimeout(switchTimer.current); switchTimer.current = null; }
    switching.current = false;
  }, [mode]);

  useEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
      if (switchTimer.current !== null) { clearTimeout(switchTimer.current); }
      pressedScale.stopAnimation();
    };
  }, [pressedScale]);

  function pressFeedback(toValue: number) {
    if (!alive.current) { return; }
    pressedScale.stopAnimation();
    if (latest.current.reduced || latest.current.locked) { pressedScale.setValue(1); return; }
    Animated.timing(pressedScale, { toValue, duration: 100, useNativeDriver: true }).start();
  }

  function routeRow(binding: BottomTabBarProps, context: AppContextMode, interactive: boolean) {
    const buttons = binding.state.routes.map((route, index) => {
      const focused = binding.state.index === index;
      const options = binding.descriptors[route.key].options;
      const rawLabel = options.tabBarLabel ?? options.title ?? route.name;
      const label = typeof rawLabel === 'string' ? rawLabel : route.name;
      const color = context === 'workspace' ? focused ? '#ffffff' : '#e9c9c9' : focused ? theme.brandIcon : theme.ink500;
      const content = <View accessibilityElementsHidden importantForAccessibility="no-hide-descendants" style={[styles.routeContent, interactive && styles.invisible]}>
        {options.tabBarIcon?.({ focused, color, size: 21 })}
        <Text numberOfLines={2} style={[styles.label, { color }]}>{label}</Text>
      </View>;
      if (!interactive) { return <View key={route.key} style={styles.route}>{content}</View>; }
      const isCurrent = () => {
        const current = latest.current;
        return alive.current && !current.locked && current.mode === context && current.state === binding.state && current.navigation === binding.navigation
          && current.state.routes.some(item => item.key === route.key);
      };
      return (
        <Pressable
          key={route.key}
          accessibilityRole="tab"
          accessibilityLabel={options.tabBarAccessibilityLabel ?? label}
          accessibilityState={{ selected: focused, disabled: locked }}
          disabled={locked}
          onPress={() => {
            if (!isCurrent()) { return; }
            const event = binding.navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
            if (!focused && !event.defaultPrevented) { binding.navigation.navigate(route.name, route.params); }
          }}
          onLongPress={() => { if (isCurrent()) { binding.navigation.emit({ type: 'tabLongPress', target: route.key }); } }}
          style={({ pressed }) => [styles.route, pressed && styles.pressed]}>
          {content}
        </Pressable>
      );
    });
    if (!workspace.canSwitchContext) { return <View style={[styles.routeRow, { minHeight: rowHeight }]}>{buttons}</View>; }
    const split = Math.ceil(buttons.length / 2);
    return (
      <View style={[styles.routeRow, { minHeight: rowHeight }]}>
        <View style={styles.routeGroup}>{buttons.slice(0, split)}</View>
        <View testID={interactive ? 'context-cta-slot' : undefined} style={styles.centerSlot} />
        <View style={styles.routeGroup}>{buttons.slice(split)}</View>
      </View>
    );
  }

  if (keyboardVisible) { return null; }
  return (
    <View
      testID="context-tab-bar"
      pointerEvents="box-none"
      accessibilityRole="tablist"
      onLayout={event => reportHeight?.(Math.max(0, event.nativeEvent.layout.height - raised))}
      style={[styles.host, { paddingTop: raised, marginTop: -raised }]}>
      <View
        testID="context-bar-surface"
        onLayout={event => {
          const { width, height } = event.nativeEvent.layout;
          if (width > 0 && height > 0) { setLayout(previous => previous.width === width && previous.height === height ? previous : { width, height }); }
        }}
        style={[styles.surface, { paddingBottom: Math.max(6, insets.bottom) }]}>
        <View pointerEvents="none" style={[styles.backgroundClip, { backgroundColor: theme.surface }]}>
          <Animated.View
            testID="context-bar-reveal"
            style={[styles.reveal, { width: radius * 2, height: radius * 2, borderRadius: radius,
              left: centerX - radius, top: originY - radius,
              transform: [{ scale: progress.interpolate({ inputRange: [0, 0.12, 0.82, 1], outputRange: [0, 0, 1, 1], extrapolate: 'clamp' }) }] }]}
          />
        </View>
        <View style={[styles.rows, locked && styles.disabled, { paddingLeft: insets.left + 8, paddingRight: insets.right + 8 }]}>
          <Animated.View testID="context-personal-tabs" pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants" style={[styles.visualRow, { paddingLeft: insets.left + 8, paddingRight: insets.right + 8, opacity: personalOpacity }]}>
            {retainedBindings.current.personal ? routeRow(retainedBindings.current.personal, 'personal', false) : null}
          </Animated.View>
          <Animated.View testID="context-work-tabs" pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants" style={[styles.visualRow, { paddingLeft: insets.left + 8, paddingRight: insets.right + 8, opacity: workOpacity }]}>
            {retainedBindings.current.workspace ? routeRow(retainedBindings.current.workspace, 'workspace', false) : null}
          </Animated.View>
          {routeRow({ state, descriptors, navigation, insets }, mode, true)}
        </View>
      </View>
      {workspace.canSwitchContext ? (
        <Pressable
          testID="context-switcher"
          accessibilityRole="button"
          accessibilityLabel={mode === 'personal' ? 'Switch to Team Workspace' : 'Switch to Personal Portal'}
          accessibilityState={{ disabled: locked }}
          disabled={locked}
          onPressIn={() => pressFeedback(0.94)}
          onPressOut={() => pressFeedback(1)}
          onPress={() => {
            const current = latest.current;
            if (!alive.current || switching.current || current.locked || mode !== current.mode) { return; }
            switching.current = true;
            switchTimer.current = setTimeout(() => { switching.current = false; switchTimer.current = null; }, TAB_TRANSITION_MS);
            // Entry owns acceptance: neither a picker nor a cancelled guard recolors the bar.
            if (current.mode === 'personal') { current.workspace.enterWorkspace(); }
            else { current.workspace.enterPersonal(); }
          }}
          style={[styles.context, { left: centerX - CTA_SLOT / 2, minHeight: CTA_RING_SIZE + 2 + captionHeight }, locked && styles.disabled]}>
          <View testID="context-cta-ring" style={styles.contextRing}>
            <Animated.View testID="context-cta-disc" style={[styles.contextDisc, { transform: [{ scale: pressedScale }] }]}>
              <View style={[StyleSheet.absoluteFill, styles.personalDisc]} />
              <Animated.View style={[StyleSheet.absoluteFill, styles.workDisc, { opacity: ctaWorkOpacity }]} />
              <Animated.View pointerEvents="none" style={[styles.contextGlyph, { opacity: ctaPersonalOpacity }]}>
                <BriefcaseBusiness size={24} color="#ffffff" strokeWidth={2.2} />
              </Animated.View>
              <Animated.View pointerEvents="none" style={[styles.contextGlyph, { opacity: ctaWorkOpacity }]}>
                <Home size={24} color={theme.brand} strokeWidth={2.2} />
              </Animated.View>
            </Animated.View>
          </View>
          <View pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants" style={[styles.contextCaption, { minHeight: captionHeight }]}>
            <Animated.Text numberOfLines={1} style={[styles.contextLabel, { color: theme.brandText, opacity: personalOpacity }]}>Work</Animated.Text>
            <Animated.Text numberOfLines={1} style={[styles.contextLabel, styles.workLabel, { opacity: workOpacity }]}>Personal</Animated.Text>
          </View>
        </Pressable>
      ) : null}
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    host: { zIndex: 10, flexShrink: 0, overflow: 'visible' },
    surface: { paddingTop: 2 },
    backgroundClip: { ...StyleSheet.absoluteFill, overflow: 'hidden', borderTopLeftRadius: 20, borderTopRightRadius: 20 },
    reveal: { position: 'absolute', backgroundColor: theme.brand },
    rows: { minHeight: 62 },
    routeRow: { flexDirection: 'row', alignItems: 'stretch', minHeight: 62 },
    visualRow: { ...StyleSheet.absoluteFill, paddingLeft: 0, paddingRight: 0 },
    routeGroup: { flex: 1, flexDirection: 'row' },
    route: { flex: 1, minWidth: 44, minHeight: 48, alignItems: 'center', justifyContent: 'center', paddingVertical: 6 },
    routeContent: { alignItems: 'center', justifyContent: 'center', gap: 4 },
    centerSlot: { width: CTA_SLOT },
    context: { position: 'absolute', top: 0, width: CTA_SLOT, minHeight: 80, alignItems: 'center' },
    contextRing: { width: CTA_RING_SIZE, height: CTA_RING_SIZE, borderRadius: CTA_RING_SIZE / 2, padding: 4,
      backgroundColor: theme.canvas },
    contextDisc: { width: CTA_SIZE, height: CTA_SIZE, borderRadius: CTA_SIZE / 2, overflow: 'hidden', alignItems: 'center', justifyContent: 'center' },
    personalDisc: { backgroundColor: theme.brand },
    workDisc: { backgroundColor: '#ffffff' },
    contextGlyph: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center' },
    contextCaption: { alignSelf: 'stretch', minHeight: 18, marginTop: 2 },
    contextLabel: { position: 'absolute', left: 0, right: 0, textAlign: 'center', fontFamily: font.body, fontWeight: '800', fontSize: 10.5 },
    workLabel: { color: '#ffffff' },
    label: { fontFamily: font.body, fontWeight: '600', fontSize: 10.5, textAlign: 'center', paddingHorizontal: 2 },
    invisible: { opacity: 0 },
    pressed: { backgroundColor: '#80808018', borderRadius: 16 },
    disabled: { opacity: 0.5 },
  });
}
const makeStyles = memoizeStyles(createStyles);
