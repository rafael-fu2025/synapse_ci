import React, { createContext, useCallback, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { AppState, findNodeHandle, Keyboard, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, TextInput, View } from 'react-native';
import type { BlurEvent, FocusEvent, KeyboardEvent, KeyboardMetrics, LayoutChangeEvent, ScrollViewProps, StyleProp, TextInputProps, ViewStyle } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useKeyboardVisible } from '../lib/useKeyboardVisible';
import { useOverlayActivity } from './OverlayProvider';
import { Theme, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

/** ScreenShell equivalent: canvas background, safe-area padding, scrollable content. */
export function Screen({
  children, scroll = true, header, bodyStyle, keyboardAware = false,
  footer, footerStyle, bottomSafeArea = false, refreshControl,
}: {
  children: React.ReactNode;
  scroll?: boolean;
  /** Fixed, full-width content inside the top safe area, outside the body. */
  header?: React.ReactNode;
  /** Overrides the body only when scroll is false (for an owned list viewport). */
  bodyStyle?: StyleProp<ViewStyle>;
  keyboardAware?: boolean;
  /** Pinned below the viewport, above the keyboard and optional bottom safe area. */
  footer?: React.ReactNode;
  /** Overrides the pinned footer's surface and spacing without affecting the body. */
  footerStyle?: StyleProp<ViewStyle>;
  bottomSafeArea?: boolean;
  refreshControl?: ScrollViewProps['refreshControl'];
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const keyboardVisible = useKeyboardVisible();
  const reveal = useKeyboardViewport({ enabled: keyboardAware, scroll, keyboardVisible });
  const body = (
    <View style={[styles.content, scroll && header != null && styles.headerContent, !scroll && bodyStyle]}>
      {children}
    </View>
  );
  const content = (
    <>
      {scroll ? (
        <ScrollView
          style={styles.viewport}
          contentContainerStyle={styles.scroll}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode={keyboardAware ? 'on-drag' : undefined}
          automaticallyAdjustKeyboardInsets={keyboardAware ? false : undefined}
          refreshControl={refreshControl}
          {...(keyboardAware ? reveal.scrollProps : {})}>
          {body}
        </ScrollView>
      ) : body}
      {footer != null ? <View style={[styles.footer, footerStyle]}>{footer}</View> : null}
    </>
  );
  return (
    <SafeAreaView style={styles.safe} edges={bottomSafeArea && !keyboardVisible ? ['top', 'bottom'] : ['top']}>
      {header}
      {keyboardAware ? (
        <KeyboardAvoidingView
          style={styles.viewport}
          behavior={Platform.OS === 'ios' ? 'padding' : undefined}
          enabled={Platform.OS === 'ios'}>
          {Platform.OS === 'android' ? (
            <View ref={reveal.regionRef} style={styles.viewport} collapsable={false} onLayout={reveal.onRegionLayout}>
              <View style={[styles.viewport, reveal.overlapStyle]}>
                <KeyboardRevealContext.Provider value={reveal.context}>{content}</KeyboardRevealContext.Provider>
              </View>
            </View>
          ) : (
            <KeyboardRevealContext.Provider value={reveal.context}>{content}</KeyboardRevealContext.Provider>
          )}
        </KeyboardAvoidingView>
      ) : content}
    </SafeAreaView>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    safe: { flex: 1, backgroundColor: theme.canvas },
    viewport: { flex: 1 },
    scroll: { flexGrow: 1 },
    content: { flex: 1, paddingHorizontal: 18, paddingBottom: 20 },
    headerContent: { paddingTop: 18 },
    footer: { flexShrink: 0, paddingHorizontal: 18, paddingVertical: 12, borderTopWidth: 1, borderTopColor: theme.line, backgroundColor: theme.surface },
  });
}

const makeStyles = memoizeStyles(createStyles);

type NativeInput = NonNullable<ReturnType<typeof TextInput.State.currentlyFocusedInput>>;
type RegisteredInput = { input: NativeInput; offset: number; token: object };
type KeyboardRevealRegistration = {
  focus: (input: NativeInput, offset?: number) => object | undefined;
  blur: (token: object) => void;
  layout: (token: object) => void;
};
type KeyboardNavigation = NonNullable<React.ContextType<NonNullable<typeof NavigationContext>>> | undefined;
const KeyboardViewportNavigationContext = NavigationContext ?? createContext<KeyboardNavigation>(undefined);
export const KeyboardRevealContext = createContext<KeyboardRevealRegistration | null>(null);

type ViewportOptions = { enabled: boolean; scroll?: boolean; keyboardVisible: boolean; overlay?: boolean };
const finite = (...values: number[]) => values.every(Number.isFinite);

/**
 * Local geometry, not a full-window scroll responder guess: reveal only obscured
 * focused descendants, and compensate only real Android keyboard overlap.
 * TextFields register focus; native inputs can use this viewport's focus capture.
 */
export function useKeyboardViewport({ enabled, scroll = true, keyboardVisible, overlay = false }: ViewportOptions) {
  const navigation = useContext(KeyboardViewportNavigationContext);
  const overlayActive = useOverlayActivity();
  const scrollRef = useRef<React.ComponentRef<typeof ScrollView>>(null);
  const regionRef = useRef<React.ComponentRef<typeof View>>(null);
  const focused = useRef<RegisteredInput | null>(null);
  const viewport = useRef({ width: 0, height: 0 });
  const region = useRef({ width: 0, height: 0 });
  const offset = useRef({ x: 0, y: 0 });
  const contentHeight = useRef(0);
  const keyboard = useRef<KeyboardMetrics | null>(null);
  const alive = useRef(true);
  const revision = useRef(0);
  const frame = useRef<ReturnType<typeof requestAnimationFrame> | null>(null);
  const [overlap, setOverlap] = useState(0);
  const latest = useRef({ enabled, scroll, keyboardVisible, overlay, overlayActive });
  latest.current = { enabled, scroll, keyboardVisible, overlay, overlayActive };
  const eligible = useCallback(() => {
    const value = latest.current;
    return alive.current && value.enabled && (value.overlay || !value.overlayActive) && (AppState.currentState == null || AppState.currentState === 'active') && (!navigation || navigation.isFocused());
  }, [navigation]);
  const invalidate = useCallback(() => {
    revision.current += 1;
    if (frame.current !== null) { cancelAnimationFrame(frame.current); frame.current = null; }
  }, []);
  const currentInput = useCallback(() => {
    const registered = focused.current;
    if (!registered || !eligible()) { return null; }
    try {
      return TextInput.State.currentlyFocusedInput() === registered.input && registered.input.isConnected !== false ? registered : null;
    } catch { return null; }
  }, [eligible]);
  const measureReveal = useCallback((token: number) => {
    const registered = currentInput();
    const scrollView = scrollRef.current;
    const nativeScroll = scrollView?.getNativeScrollRef();
    if (!latest.current.scroll || !registered || !scrollView || !nativeScroll) { return; }
    const valid = () => token === revision.current && currentInput() === registered && scrollRef.current === scrollView;
    try {
      nativeScroll.measureInWindow((_x, viewY, width, viewHeight) => {
        if (!valid() || !finite(viewY, width, viewHeight) || width <= 0 || viewHeight <= 0) { return; }
        registered.input.measureInWindow((_inputX, inputY, inputWidth, inputHeight) => {
          if (!valid() || !finite(inputY, inputWidth, inputHeight) || inputWidth <= 0 || inputHeight <= 0) { return; }
          const keyboardTop = keyboard.current?.screenY;
          const bottom = keyboardTop != null ? Math.min(viewY + viewHeight, keyboardTop) : viewY + viewHeight;
          const visibleHeight = bottom - viewY;
          if (visibleHeight <= 0) { return; }
          const clearance = Math.min(registered.offset, Math.max(0, visibleHeight - Math.min(inputHeight, visibleHeight)));
          let delta = 0;
          // A tall multiline input cannot fit completely. Keep its top/caret area
          // reachable rather than alternating between its top and bottom.
          if (inputHeight + clearance > visibleHeight) {
            if (inputY < viewY || inputY >= bottom) { delta = inputY - viewY; }
          } else if (inputY < viewY + 8) {
            delta = inputY - viewY - 8;
          } else if (inputY + inputHeight + clearance > bottom) {
            delta = inputY + inputHeight + clearance - bottom;
          }
          if (Math.abs(delta) <= 1) { return; }
          let next = Math.max(0, offset.current.y + delta);
          if (contentHeight.current > 0) { next = Math.min(next, Math.max(0, contentHeight.current - viewHeight)); }
          if (Math.abs(next - offset.current.y) <= 1 || !valid()) { return; }
          offset.current = { ...offset.current, y: next };
          // Resize/keyboard already supplies its own native movement; an immediate
          // corrective scroll avoids a second animation fighting that movement.
          scrollView.scrollTo({ x: offset.current.x, y: next, animated: false });
        });
      });
    } catch {
      // A field or native viewport may detach between a focus event and measure.
    }
  }, [currentInput]);
  const measureOverlap = useCallback((token: number) => {
    if (Platform.OS !== 'android' || !eligible()) { return; }
    const target = regionRef.current;
    const metrics = keyboard.current;
    if (!target || !metrics || !finite(metrics.screenY, metrics.height) || metrics.height <= 0) { return; }
    try {
      target.measureInWindow((_x, y, width, height) => {
        if (token !== revision.current || !eligible() || regionRef.current !== target || keyboard.current !== metrics || !finite(y, width, height) || width <= 0 || height <= 0) { return; }
        const next = Math.max(0, Math.min(height, y + height - metrics.screenY));
        setOverlap(previous => Math.abs(previous - next) > 1 ? next : previous);
      });
    } catch {
      // Session/route changes invalidate asynchronous geometry work.
    }
  }, [eligible]);
  const schedule = useCallback(() => {
    invalidate();
    if (!eligible()) { return; }
    const token = revision.current;
    frame.current = requestAnimationFrame(() => {
      frame.current = null;
      if (token !== revision.current || !eligible()) { return; }
      measureOverlap(token);
      measureReveal(token);
    });
  }, [eligible, invalidate, measureOverlap, measureReveal]);
  const register = useCallback((input: NativeInput, revealOffset = 16) => {
    if (!eligible() || !latest.current.scroll) { return undefined; }
    const previous = focused.current;
    const token = previous?.input === input ? previous.token : {};
    focused.current = { input, offset: Math.max(0, Number.isFinite(revealOffset) ? revealOffset : 16), token };
    schedule();
    return token;
  }, [eligible, schedule]);
  const blur = useCallback((token: object) => {
    if (focused.current?.token !== token) { return; }
    focused.current = null;
    invalidate();
  }, [invalidate]);
  const inputLayout = useCallback((token: object) => {
    if (focused.current?.token === token && currentInput()) { schedule(); }
  }, [currentInput, schedule]);
  const context = useMemo(() => enabled && scroll ? { focus: register, blur, layout: inputLayout } : null, [blur, enabled, inputLayout, register, scroll]);
  const onFocusCapture = useCallback((event: FocusEvent) => {
    const input = TextInput.State.currentlyFocusedInput();
    if (!input) { return; }
    const eventTarget = event.nativeEvent.target;
    if (eventTarget !== findNodeHandle(input)) { return; }
    // Preserve a TextField's explicit inline-action clearance if it registered
    // before focus reached the viewport (native capture ordering can vary).
    register(input, focused.current?.input === input ? focused.current.offset : 16);
  }, [register]);
  const onBlurCapture = useCallback((event: BlurEvent) => {
    const registered = focused.current;
    if (registered && event.nativeEvent.target === findNodeHandle(registered.input)) { blur(registered.token); }
  }, [blur]);
  const onViewportLayout = useCallback((event: LayoutChangeEvent) => {
    const { width, height } = event.nativeEvent.layout;
    if (viewport.current.width === width && viewport.current.height === height) { return; }
    viewport.current = { width, height };
    schedule();
  }, [schedule]);
  const onRegionLayout = useCallback((event: LayoutChangeEvent) => {
    const { width, height } = event.nativeEvent.layout;
    if (region.current.width === width && region.current.height === height) { return; }
    region.current = { width, height };
    schedule();
  }, [schedule]);
  const onScroll = useCallback<NonNullable<ScrollViewProps['onScroll']>>(event => {
    const next = event.nativeEvent.contentOffset;
    if (offset.current.x !== next.x || offset.current.y !== next.y) { invalidate(); }
    offset.current = { x: next.x, y: next.y };
  }, [invalidate]);
  const onContentSizeChange = useCallback((_: number, height: number) => { contentHeight.current = height; }, []);
  useLayoutEffect(() => {
    alive.current = true;
    return () => { alive.current = false; focused.current = null; invalidate(); };
  }, [invalidate]);
  useEffect(() => {
    if (!enabled) { invalidate(); focused.current = null; setOverlap(0); return undefined; }
    const shown = (event: KeyboardEvent) => {
      const metrics = event.endCoordinates;
      if (metrics && finite(metrics.screenY, metrics.height)) { keyboard.current = metrics; }
      schedule();
    };
    const show = Keyboard.addListener('keyboardDidShow', shown);
    const changed = Platform.OS === 'ios' ? Keyboard.addListener('keyboardDidChangeFrame', shown) : null;
    const hide = Keyboard.addListener('keyboardDidHide', () => {
      keyboard.current = null;
      invalidate();
      if (alive.current) { setOverlap(0); }
    });
    keyboard.current = Keyboard.metrics() ?? null;
    if (keyboard.current) { schedule(); }
    const app = AppState.addEventListener('change', state => { if (state !== 'active') { invalidate(); } });
    const unfocus = navigation?.addListener('blur', () => { focused.current = null; invalidate(); });
    return () => { show.remove(); changed?.remove(); hide.remove(); app.remove(); unfocus?.(); invalidate(); };
  }, [enabled, invalidate, navigation, schedule]);
  useEffect(() => {
    if (!eligible()) { invalidate(); return; }
    if (keyboardVisible) {
      keyboard.current = Keyboard.metrics() ?? keyboard.current;
      schedule();
    } else { keyboard.current = null; setOverlap(0); }
  }, [eligible, invalidate, keyboardVisible, overlayActive, schedule]);
  useEffect(() => { if (overlap > 0 && currentInput()) { schedule(); } }, [currentInput, overlap, schedule]);
  return {
    regionRef,
    onRegionLayout,
    overlapStyle: { marginBottom: enabled && Platform.OS === 'android' ? overlap : 0 },
    context,
    scrollProps: { ref: scrollRef, onFocusCapture, onFocus: onFocusCapture, onBlurCapture, onLayout: onViewportLayout, onScroll, scrollEventThrottle: 16, onContentSizeChange },
  };
}

/** Internal TextField bridge preserving the native inputRef identity/type. */
export function useKeyboardInputReveal(offset = 16) {
  const context = useContext(KeyboardRevealContext);
  const registration = useRef<{ context: KeyboardRevealRegistration; token: object } | null>(null);
  const clear = useCallback(() => {
    const value = registration.current;
    registration.current = null;
    if (value) { value.context.blur(value.token); }
  }, []);
  useLayoutEffect(() => clear, [clear, context]);
  const focus = useCallback(() => {
    clear();
    const input = TextInput.State.currentlyFocusedInput();
    if (!context || !input) { return; }
    const token = context.focus(input, offset);
    if (token) { registration.current = { context, token }; }
  }, [clear, context, offset]);
  const layout = useCallback(() => {
    const value = registration.current;
    if (value) { value.context.layout(value.token); }
  }, []);
  return { onFocus: focus, onBlur: clear, onLayout: layout } satisfies Pick<TextInputProps, 'onFocus' | 'onBlur' | 'onLayout'>;
}
