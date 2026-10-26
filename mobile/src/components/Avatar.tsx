import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { font } from '../theme/tokens';

/** Gradient-backed initials avatar matching the reference .avatar styles. */
export function Avatar({ initials, size = 'small' }: { initials: string; size?: 'small' | 'large' }) {
  return (
    <View style={[styles.base, size === 'large' ? styles.large : styles.small]}>
      <Text style={[styles.label, size === 'large' ? styles.largeLabel : styles.smallLabel]}>{initials}</Text>
    </View>
  );
}

const gold = { from: '#b17a27', to: '#e3b654' } as const;

const styles = StyleSheet.create({
  base: {
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 999,
    backgroundColor: gold.to,
  },
  small: { width: 38, height: 38 },
  large: { width: 86, height: 86, borderWidth: 4, borderColor: '#ffffff' },
  label: { color: '#ffffff', fontWeight: '800', fontFamily: font.body },
  smallLabel: { fontSize: 11 },
  largeLabel: { fontSize: 22 },
});
