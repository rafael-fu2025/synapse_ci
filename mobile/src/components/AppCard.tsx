import React from 'react';
import { StyleSheet, View, ViewStyle } from 'react-native';
import { useTheme } from '../theme/ThemeProvider';
import { elevation, spacing } from '../theme/tokens';

export function AppCard({
  children,
  style,
  padded = true,
}: {
  children: React.ReactNode;
  style?: ViewStyle | ViewStyle[];
  padded?: boolean;
}) {
  const { theme } = useTheme();
  return (
    <View
      style={[
        styles.card,
        padded && styles.padded,
        { backgroundColor: theme.surface, borderColor: theme.line, shadowColor: theme.shadowColor },
        style,
      ]}>
      {children}
    </View>
  );
}

const styles = StyleSheet.create({
  card: { width: '100%', borderRadius: 15, borderWidth: 1, ...elevation.sm },
  padded: { padding: spacing.lg },
});
