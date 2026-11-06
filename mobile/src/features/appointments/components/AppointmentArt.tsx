import React from 'react';
import { StyleSheet, View } from 'react-native';
import { SvgXml } from 'react-native-svg';
import {
  CLINIC_ART,
  CLINIC_ART_VIEWBOX,
  GUIDANCE_ART,
  GUIDANCE_ART_VIEWBOX,
} from '../../../assets/brand/appointmentArt';
import { ThemeMode, palette } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';

/**
 * The tint each unit uses for its illustration.
 *
 * Light surfaces take the unit colour, matching its icon. Dark surfaces would
 * swallow maroon and violet at this opacity, so the art turns white there —
 * the same treatment as the dashboard's next-appointment card.
 */
export function appointmentArtTint(unit: 'clinic' | 'guidance', mode: ThemeMode = 'light'): string {
  if (mode === 'dark') {
    return '#ffffff';
  }
  return unit === 'guidance' ? palette.violet600 : palette.brand700;
}

/**
 * Decorative unit illustration anchored to a card's lower-right corner.
 *
 * Purely texture: it never receives touches and carries no accessibility label,
 * so the card's own label and text remain the only announced content. The parent
 * card must clip (`overflow: 'hidden'`) for the rounded corners to hold.
 */
export function AppointmentArt({
  unit,
  height,
  opacity,
  color,
}: {
  unit: 'clinic' | 'guidance';
  height: number;
  opacity: number;
  /** Defaults to the theme-aware unit tint; the dashboard hero passes white explicitly. */
  color?: string;
}) {
  const guidance = unit === 'guidance';
  const { mode } = useTheme();
  const box = guidance ? GUIDANCE_ART_VIEWBOX : CLINIC_ART_VIEWBOX;
  return (
    <View pointerEvents="none" style={[styles.art, { opacity }]}>
      <SvgXml
        xml={guidance ? GUIDANCE_ART : CLINIC_ART}
        width={height * (box.width / box.height)}
        height={height}
        color={color ?? appointmentArtTint(unit, mode)}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  art: { position: 'absolute', right: 0, bottom: 0 },
});
