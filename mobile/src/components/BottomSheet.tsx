import React, { useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Animated, KeyboardAvoidingView, Modal, PanResponder, Platform, Pressable, ScrollView, StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { X } from 'lucide-react-native';
import { useKeyboardVisible } from '../lib/useKeyboardVisible';
import { useReducedMotion } from '../lib/useReducedMotion';
import { useTheme } from '../theme/ThemeProvider';
import { Theme, memoizeStyles, type } from '../theme/tokens';
import { IconAction } from './IconAction';
import { useOverlayFocus, useOverlayHost } from './OverlayProvider';
import { KeyboardRevealContext, useKeyboardViewport } from './Screen';

export type BottomSheetProps = {
  visible: boolean;
  title: string;
  description?: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
  onClose: () => void;
  dismissible?: boolean;
  closeLabel?: string;
  onDismiss?: () => void;
  testID?: string;
};

export function BottomSheet(props: BottomSheetProps) {
  return useOverlayHost(props.visible, props.onDismiss, presentation => (
    <BottomSheetSurface
      {...props}
      key={presentation.cycle}
      visible={props.visible && presentation.nativeVisible}
      nativeVisible={presentation.nativeVisible}
      progress={presentation.progress}
      onNativeDismiss={presentation.onNativeDismiss}
      abandoned={presentation.abandoned}
    />
  ));
}

type SurfaceProps = BottomSheetProps & {
  nativeVisible: boolean;
  progress: Animated.Value;
  onNativeDismiss: () => void;
  abandoned: boolean;
};

function BottomSheetSurface({ visible, nativeVisible, progress, onNativeDismiss, abandoned, title, description, children, footer, onClose, dismissible = true, closeLabel = 'Close sheet', testID }: SurfaceProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const insets = useSafeAreaInsets();
  const { height } = useWindowDimensions();
  const keyboard = useKeyboardVisible();
  const reveal = useKeyboardViewport({ enabled: visible && !abandoned, keyboardVisible: keyboard, overlay: true });
  const reduced = useReducedMotion();
  const [availableHeight, setAvailableHeight] = useState(height);
  const { heading, capture, onShow, requestRestore } = useOverlayFocus(visible, undefined, abandoned);
  const drag = useRef(new Animated.Value(0)).current;
  const dragAnimation = useRef<Animated.CompositeAnimation | null>(null);
  const closeRequested = useRef(false);
  const latest = useRef({ onClose, dismissible, visible });
  latest.current = { onClose, dismissible, visible };
  // Capture only when granted a real presentation, not while queued. Restoration
  // is completed by the retained native host, not by an abandoned requester.
  useLayoutEffect(() => {
    capture();
    return () => { dragAnimation.current?.stop(); drag.stopAnimation(); };
  }, [drag, capture]);
  useLayoutEffect(() => {
    if (!visible) { closeRequested.current = false; }
  }, [visible]);

  function requestClose() {
    if (!latest.current.visible || !latest.current.dismissible || closeRequested.current) { return; }
    closeRequested.current = true;
    requestRestore();
    latest.current.onClose();
  }

  const responder = useMemo(() => PanResponder.create({
    onMoveShouldSetPanResponder: (_, gesture) => latest.current.visible && latest.current.dismissible && !closeRequested.current && !keyboard && gesture.dy > 8 && Math.abs(gesture.dy) > Math.abs(gesture.dx),
    onPanResponderGrant: () => { dragAnimation.current?.stop(); drag.stopAnimation(); },
    onPanResponderMove: (_, gesture) => {
      if (latest.current.visible && latest.current.dismissible && !keyboard) { drag.setValue(Math.max(0, gesture.dy)); }
    },
    onPanResponderRelease: (_, gesture) => {
      if (!latest.current.visible || !latest.current.dismissible || closeRequested.current) { drag.setValue(0); return; }
      if (!keyboard && (gesture.dy > 80 || gesture.vy > 0.8)) {
        closeRequested.current = true;
        requestRestore();
        // Reset the optional gesture offset if the controlled parent rejects a
        // close; the presentation exit still supplies its own slide distance.
        drag.setValue(0);
        latest.current.onClose();
      } else if (reduced || keyboard) { drag.setValue(0); }
      else {
        dragAnimation.current = Animated.spring(drag, { toValue: 0, useNativeDriver: true, overshootClamping: true });
        dragAnimation.current.start();
      }
    },
    onPanResponderTerminate: () => { dragAnimation.current?.stop(); drag.setValue(0); },
  }), [drag, requestRestore, keyboard, reduced]);

  const topGap = insets.top + 24;
  const layout = { maxHeight: Math.max(0, Math.min(height, availableHeight) - topGap), paddingBottom: keyboard ? 8 : Math.max(12, insets.bottom) };
  return (
    <Modal
      visible={nativeVisible}
      transparent
      animationType="none"
      statusBarTranslucent
      navigationBarTranslucent
      onRequestClose={requestClose}
      onDismiss={() => { onNativeDismiss(); }}
      onShow={onShow}>
      <View style={styles.overlay} accessibilityViewIsModal importantForAccessibility="yes" testID={testID}>
        <Animated.View style={[StyleSheet.absoluteFill, styles.backdrop, { opacity: progress }]}>
          <Pressable accessible={false} importantForAccessibility="no" disabled={!dismissible || !visible || closeRequested.current} onPress={requestClose} style={StyleSheet.absoluteFill} />
        </Animated.View>
        <KeyboardAvoidingView
          style={styles.position}
          behavior={Platform.OS === 'ios' ? 'padding' : undefined}
          enabled={Platform.OS === 'ios'}
          pointerEvents="box-none">
          <View
            ref={reveal.regionRef}
            style={styles.position}
            pointerEvents="box-none"
            collapsable={false}
            onLayout={event => { setAvailableHeight(event.nativeEvent.layout.height); reveal.onRegionLayout(event); }}>
            <View style={[styles.available, reveal.overlapStyle]} pointerEvents="box-none">
            <Animated.View style={[
              styles.sheet,
              layout,
              { transform: [{ translateY: Animated.add(progress.interpolate({ inputRange: [0, 1], outputRange: [height, 0] }), drag) }] },
            ]}>
              {dismissible ? (
                <View {...responder.panHandlers} style={styles.handleTarget} accessible={false} importantForAccessibility="no">
                  <View style={styles.handle} />
                </View>
              ) : <View style={styles.requiredSpacing} />}
              <View style={styles.header}>
                <View style={styles.copy}>
                  <Text ref={heading} accessible accessibilityRole="header" style={styles.title}>{title}</Text>
                  {description ? <Text style={styles.description}>{description}</Text> : null}
                </View>
                {dismissible ? <IconAction label={closeLabel} icon={<X size={20} color={theme.ink700} />} disabled={!visible} onPress={requestClose} /> : null}
              </View>
              <KeyboardRevealContext.Provider value={reveal.context}>
                <ScrollView style={styles.body} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" keyboardDismissMode="on-drag" automaticallyAdjustKeyboardInsets={false} {...reveal.scrollProps}>
                  {children}
                </ScrollView>
              </KeyboardRevealContext.Provider>
              {footer != null ? <View style={styles.footer}>{footer}</View> : null}
            </Animated.View>
            </View>
          </View>
        </KeyboardAvoidingView>
      </View>
    </Modal>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    overlay: { flex: 1 },
    backdrop: { backgroundColor: 'rgba(0,0,0,0.46)' },
    position: { flex: 1 },
    available: { flex: 1, justifyContent: 'flex-end' },
    sheet: { backgroundColor: theme.surface, borderTopLeftRadius: 22, borderTopRightRadius: 22, overflow: 'hidden', flexShrink: 1 },
    handleTarget: { height: 24, alignItems: 'center', justifyContent: 'center' },
    handle: { height: 4, width: 38, borderRadius: 2, backgroundColor: theme.ink400 },
    requiredSpacing: { height: 20 },
    header: { flexDirection: 'row', alignItems: 'flex-start', paddingHorizontal: 20, paddingBottom: 12, gap: 12, flexShrink: 0 },
    copy: { flex: 1, minWidth: 0 },
    title: { ...type.cardTitle, color: theme.ink900 },
    description: { ...type.body, color: theme.ink500, marginTop: 4 },
    body: { flexGrow: 0, flexShrink: 1, minHeight: 0 },
    content: { paddingHorizontal: 20, paddingBottom: 8 },
    footer: { paddingHorizontal: 20, paddingTop: 12, borderTopColor: theme.line, borderTopWidth: 1, flexShrink: 0 },
  });
}
const makeStyles = memoizeStyles(createStyles);
