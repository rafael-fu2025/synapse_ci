import React from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, ViewStyle } from 'react-native';
import { useTheme } from '../theme/ThemeProvider';
import { elevation, spacing, type } from '../theme/tokens';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';

export function Action({
  children,
  onPress,
  variant = 'primary',
  full = false,
  loading = false,
  style,
}: {
  children: React.ReactNode;
  onPress?: () => void;
  variant?: Variant;
  full?: boolean;
  loading?: boolean;
  style?: ViewStyle;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);

  return (
    <Pressable
      onPress={onPress}
      disabled={loading || onPress === undefined}
      style={({ pressed }) => [
        styles.base,
        styles[variant],
        full && styles.full,
        (pressed || loading) && styles.pressed,
        style,
      ]}>
      {loading ? (
        <ActivityIndicator size="small" color={variant === 'primary' || variant === 'danger' ? '#ffffff' : theme.brand} />
      ) : null}
      <Text style={[styles.label, styles[`${variant}Label` as const] as object]}>{children}</Text>
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
    full: { width: '100%' as const },
    pressed: { opacity: 0.75, transform: [{ translateY: -0.5 }] },
    primary: { backgroundColor: theme.brand, ...elevation.sm },
    secondary: { backgroundColor: theme.surface, borderWidth: 1, borderColor: '#f0d5d5' },
    ghost: { backgroundColor: 'transparent' },
    danger: { backgroundColor: '#a33b3b' },
    label: { ...type.action },
    primaryLabel: { color: '#ffffff' },
    secondaryLabel: { color: theme.brand },
    ghostLabel: { color: theme.ink700 },
    dangerLabel: { color: '#ffffff' },
  });
}
