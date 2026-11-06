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

export function StatusPill({
  children,
  tone = 'neutral',
  onBrand = false,
}: {
  children: React.ReactNode;
  tone?: StatusTone;
  /** For pill placement on maroon/black branded surfaces: translucent white + white label. */
  onBrand?: boolean;
}) {
  if (onBrand) {
    return (
      <View style={[base.pill, onBrandStyle.pill]}>
        <Text style={[base.label, onBrandStyle.label]}>{children}</Text>
      </View>
    );
  }
  return (
    <View style={[base.pill, toneStyles[tone].pill]}>
      <Text style={[base.label, toneStyles[tone].label]}>{children}</Text>
    </View>
  );
}

// Static on-brand colors come from the token table; no StyleSheet indirection needed.
const onBrandStyle = {
  pill: { backgroundColor: 'rgba(255,255,255,0.18)' } as ViewStyle,
  label: { color: '#ffffff' } as TextStyle,
};

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
