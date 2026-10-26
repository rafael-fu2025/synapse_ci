import React from 'react';
import { StyleSheet, Text, TextStyle, View, ViewStyle } from 'react-native';
import { StatusTone, statusTone } from '../theme/tokens';

// Static tone colors come from the token table; no StyleSheet indirection needed.
const toneStyles = Object.fromEntries(
  (Object.keys(statusTone) as StatusTone[]).map((tone) => [
    tone,
    {
      pill: { backgroundColor: statusTone[tone].bg } as ViewStyle,
      label: { color: statusTone[tone].fg } as TextStyle,
    },
  ]),
) as Record<StatusTone, { pill: ViewStyle; label: TextStyle }>;

export function StatusPill({ children, tone = 'neutral' }: { children: React.ReactNode; tone?: StatusTone }) {
  return (
    <View style={[base.pill, toneStyles[tone].pill]}>
      <Text style={[base.label, toneStyles[tone].label]}>{children}</Text>
    </View>
  );
}

const base = StyleSheet.create({
  pill: {
    alignSelf: 'flex-start',
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 999,
  },
  label: { fontSize: 9.5, fontWeight: '700' },
});
