import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Theme, font } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

export function DetailRow({
  icon,
  label,
  value,
  last = false,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  last?: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={[styles.row, last ? null : styles.rowDivider]}>
      <View style={styles.icon}>{icon}</View>
      <View style={styles.copy}>
        <Text style={styles.label}>{label}</Text>
        <Text style={styles.value}>{value}</Text>
      </View>
    </View>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    row: { flexDirection: 'row', gap: 11, paddingVertical: 12 },
    rowDivider: { borderBottomWidth: 1, borderBottomColor: theme.line },
    icon: { width: 32, height: 32, alignItems: 'center', justifyContent: 'center', borderRadius: 9, backgroundColor: theme.brand50 },
    copy: { flex: 1, justifyContent: 'center' },
    label: { fontSize: 9, fontFamily: font.body, color: theme.ink500 },
    value: { marginTop: 2, fontSize: 11, fontWeight: '600', fontFamily: font.body, color: theme.ink900 },
  });
}
