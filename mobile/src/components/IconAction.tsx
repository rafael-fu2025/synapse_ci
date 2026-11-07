import React from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Theme, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

/** A 44pt icon target, with an optional unread dot and a stable loading slot. */
export function IconAction({
  label,
  icon,
  onPress,
  badge = false,
  disabled = false,
  loading = false,
}: {
  label: string;
  icon: React.ReactNode;
  onPress?: () => void;
  badge?: boolean;
  disabled?: boolean;
  loading?: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const isDisabled = disabled || loading || onPress === undefined;
  return (
    <Pressable
      onPress={isDisabled ? undefined : onPress}
      disabled={isDisabled}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: isDisabled, busy: loading }}
      hitSlop={6}
      style={({ pressed }) => [styles.button, pressed && !isDisabled && styles.pressed, isDisabled && styles.disabled]}>
      <View style={loading && styles.hidden} pointerEvents="none">{icon}</View>
      {loading ? <ActivityIndicator style={styles.spinner} color={theme.brandIcon} size="small" accessible={false} /> : null}
      {badge ? <View style={styles.dot} pointerEvents="none" /> : null}
    </Pressable>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    button: {
      width: 44,
      height: 44,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 12,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    pressed: { opacity: 0.7 },
    disabled: { opacity: 0.5 },
    hidden: { opacity: 0 },
    spinner: { position: 'absolute' },
    dot: {
      position: 'absolute',
      top: 7,
      right: 7,
      width: 7,
      height: 7,
      borderRadius: 999,
      backgroundColor: '#d64c45',
      borderWidth: 2,
      borderColor: theme.surface,
    },
  });
}

const makeStyles = memoizeStyles(createStyles);
