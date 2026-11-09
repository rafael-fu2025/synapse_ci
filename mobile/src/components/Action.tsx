import React from 'react';
import { ActivityIndicator, Animated, Pressable, StyleSheet, Text, View, ViewStyle } from 'react-native';
import { usePressFeedback } from '../lib/usePressFeedback';
import { useTheme } from '../theme/ThemeProvider';
import { elevation, spacing, type } from '../theme/tokens';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';

export function Action({
  children,
  onPress,
  variant = 'primary',
  full = false,
  loading = false,
  disabled = false,
  icon,
  accessibilityLabel,
  accessibilityHint,
  testID,
  style,
}: {
  children: React.ReactNode;
  onPress?: () => void;
  variant?: Variant;
  full?: boolean;
  loading?: boolean;
  disabled?: boolean;
  icon?: React.ReactNode;
  accessibilityLabel?: string;
  accessibilityHint?: string;
  testID?: string;
  style?: ViewStyle;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const isDisabled = disabled || loading || onPress === undefined;
  const feedback = usePressFeedback(isDisabled);

  return (
    <Pressable
      onPress={isDisabled ? undefined : onPress}
      onPressIn={feedback.onPressIn}
      onPressOut={feedback.onPressOut}
      disabled={isDisabled}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
      accessibilityHint={accessibilityHint}
      accessibilityState={{ disabled: isDisabled, busy: loading }}
      testID={testID}
      style={({ pressed }) => [
        styles.base,
        styles[variant],
        full && styles.full,
        pressed && !isDisabled && styles.pressed,
        isDisabled && styles.disabled,
        style,
      ]}>
      <Animated.View style={[styles.content, { transform: [{ scale: feedback.scale }] }, loading && styles.hidden]} pointerEvents="none">
        {icon}
        <Text style={[styles.label, styles[`${variant}Label` as const] as object]}>{children}</Text>
      </Animated.View>
      {loading ? (
        <View style={styles.spinner} pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
          <ActivityIndicator size="small" color={variant === 'primary' || variant === 'danger' ? '#ffffff' : theme.brandIcon} />
        </View>
      ) : null}
    </Pressable>
  );
}

function makeStyles(theme: ReturnType<typeof useTheme>['theme']) {
  return StyleSheet.create({
    base: {
      minHeight: 44,
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'center',
      gap: spacing.sm,
      paddingHorizontal: 17,
      paddingVertical: 10,
      borderRadius: 12,
      opacity: 1,
    },
    content: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: spacing.sm },
    hidden: { opacity: 0 },
    spinner: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, alignItems: 'center', justifyContent: 'center' },
    full: { width: '100%' as const },
    pressed: { opacity: 0.75 },
    disabled: { opacity: 0.5 },
    primary: { backgroundColor: theme.brand, ...elevation.sm },
    secondary: { backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.mode === 'dark' ? theme.line : '#f0d5d5' },
    ghost: { backgroundColor: 'transparent' },
    danger: { backgroundColor: '#a33b3b' },
    label: { ...type.action },
    primaryLabel: { color: '#ffffff' },
    secondaryLabel: { color: theme.brandText },
    ghostLabel: { color: theme.ink700 },
    dangerLabel: { color: '#ffffff' },
  });
}
