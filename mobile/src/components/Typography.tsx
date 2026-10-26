import React from 'react';
import { StyleSheet, Text, TextProps } from 'react-native';
import { Theme, font, type } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

/** Themed text primitives so screens never need inline style objects. */
export function BodyText({ style, ...props }: TextProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <Text {...props} style={[styles.body, style]} />;
}

export function MutedText({ style, ...props }: TextProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <Text {...props} style={[styles.muted, style]} />;
}

export function Eyebrow({ style, ...props }: TextProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <Text {...props} style={[styles.eyebrow, style]} />;
}

export function CardTitle({ style, ...props }: TextProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <Text {...props} style={[styles.cardTitle, style]} />;
}

export function HeroTitle({ style, ...props }: TextProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <Text {...props} style={[styles.hero, style]} />;
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    body: { ...type.body, color: theme.ink700 },
    muted: { ...type.body, color: theme.ink500 },
    eyebrow: { fontSize: 9.5, fontWeight: '800', letterSpacing: 1.1, fontFamily: font.body, color: theme.brand },
    cardTitle: { ...type.cardTitle, color: theme.ink900 },
    hero: { ...type.hero, color: theme.ink900 },
  });
}
