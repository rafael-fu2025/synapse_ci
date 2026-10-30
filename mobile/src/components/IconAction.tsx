import React from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { Theme, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

/** Square 38px icon button from the reference (.icon-action), with an optional unread dot. */
export function IconAction({
  label,
  icon,
  onPress,
  badge = false,
}: {
  label: string;
  icon: React.ReactNode;
  onPress?: () => void;
  badge?: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={label}
      hitSlop={6}
      style={({ pressed }) => [styles.button, pressed && styles.pressed]}>
      {icon}
      {badge ? <View style={styles.dot} /> : null}
    </Pressable>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    button: {
      width: 38,
      height: 38,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 12,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    pressed: { opacity: 0.7 },
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
