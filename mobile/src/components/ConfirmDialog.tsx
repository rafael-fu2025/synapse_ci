import React, { useEffect, useLayoutEffect, useRef } from 'react';
import { Animated, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useKeyboardVisible } from '../lib/useKeyboardVisible';
import { motion } from '../lib/motion';
import { useTheme } from '../theme/ThemeProvider';
import { Theme, memoizeStyles, type } from '../theme/tokens';
import { Action } from './Action';
import { useOverlayFocus, useOverlayHost } from './OverlayProvider';
import { useSystemNavigationBar } from './SystemNavigationBar';

export type ConfirmDialogProps = {
  visible: boolean;
  title: string;
  message: string;
  confirmLabel: string;
  cancelLabel?: string;
  onConfirm: () => void;
  onCancel: () => void;
  destructive?: boolean;
  loading?: boolean;
  onDismiss?: () => void;
};

export function ConfirmDialog(props: ConfirmDialogProps) {
  return useOverlayHost(props.visible, props.onDismiss, presentation => (
    <ConfirmDialogSurface
      {...props}
      key={presentation.cycle}
      visible={props.visible && presentation.nativeVisible}
      nativeVisible={presentation.nativeVisible}
      progress={presentation.progress}
      onNativeDismiss={presentation.onNativeDismiss}
      abandoned={presentation.abandoned}
    />
  ), { timing: motion.dialog });
}

type SurfaceProps = ConfirmDialogProps & {
  nativeVisible: boolean;
  progress: Animated.Value;
  onNativeDismiss: () => void;
  abandoned: boolean;
};

function ConfirmDialogSurface({ visible, nativeVisible, progress, onNativeDismiss, abandoned, title, message, confirmLabel, cancelLabel = 'Cancel', onConfirm, onCancel, destructive = false, loading = false }: SurfaceProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  useSystemNavigationBar('dialog', nativeVisible);
  const insets = useSafeAreaInsets();
  const keyboard = useKeyboardVisible();
  const { heading, capture, onShow, requestRestore } = useOverlayFocus(visible, undefined, abandoned);
  const acted = useRef(false);
  const latest = useRef({ visible, loading, onConfirm, onCancel });
  latest.current = { visible, loading, onConfirm, onCancel };
  useLayoutEffect(() => { capture(); }, [capture]);
  useEffect(() => { if (!loading) { acted.current = false; } }, [loading]);
  useEffect(() => { if (!visible) { acted.current = false; } }, [visible]);
  function cancel() {
    if (latest.current.loading || acted.current || !latest.current.visible) { return; }
    acted.current = true;
    requestRestore();
    latest.current.onCancel();
  }
  function confirm() {
    if (latest.current.loading || acted.current || !latest.current.visible) { return; }
    acted.current = true;
    latest.current.onConfirm();
  }
  const frameInsets = { paddingTop: insets.top + 24, paddingBottom: keyboard ? 24 : insets.bottom + 24 };
  return (
    <Modal
      visible={nativeVisible}
      transparent
      animationType="none"
      statusBarTranslucent
      navigationBarTranslucent
      onRequestClose={cancel}
      onDismiss={onNativeDismiss}
      onShow={onShow}>
      <View style={styles.overlay} accessibilityViewIsModal importantForAccessibility="yes">
        <Animated.View style={[StyleSheet.absoluteFill, styles.backdrop, { opacity: progress }]}>
          <Pressable accessible={false} importantForAccessibility="no" disabled={loading || !visible} onPress={cancel} style={StyleSheet.absoluteFill} />
        </Animated.View>
        <KeyboardAvoidingView
          style={styles.position}
          behavior={Platform.OS === 'ios' ? 'padding' : undefined}
          enabled={Platform.OS === 'ios'}
          pointerEvents="box-none">
          <View style={[styles.available, frameInsets]} pointerEvents="box-none">
            <Animated.View style={[styles.dialog, { opacity: progress, transform: [{ scale: progress.interpolate({ inputRange: [0, 1], outputRange: [motion.dialog.fromScale, 1] }) }] }]}>
              <ScrollView style={styles.body} keyboardShouldPersistTaps="handled" keyboardDismissMode="on-drag" automaticallyAdjustKeyboardInsets={false} contentContainerStyle={styles.content}>
                <Text ref={heading} accessible accessibilityRole="header" style={styles.title}>{title}</Text>
                <Text style={styles.message}>{message}</Text>
                <Action full variant={destructive ? 'danger' : 'primary'} loading={loading} disabled={!visible} onPress={confirm}>{confirmLabel}</Action>
                <Action full variant="secondary" disabled={loading || !visible} onPress={cancel}>{cancelLabel}</Action>
              </ScrollView>
            </Animated.View>
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
    available: { flex: 1, justifyContent: 'center', paddingHorizontal: 24 },
    dialog: { backgroundColor: theme.surface, borderRadius: 18, maxHeight: '100%', overflow: 'hidden', flexShrink: 1 },
    body: { flexGrow: 0, flexShrink: 1, minHeight: 0 },
    content: { padding: 20, gap: 12 },
    title: { ...type.cardTitle, color: theme.ink900 },
    message: { ...type.body, color: theme.ink500, marginBottom: 4 },
  });
}
const makeStyles = memoizeStyles(createStyles);
