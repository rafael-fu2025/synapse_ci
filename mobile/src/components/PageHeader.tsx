import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { ArrowLeft } from 'lucide-react-native';
import { Theme, font, type, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

export function PageHeader({
  title,
  subtitle,
  onBack,
  backDisabled = false,
  action,
  edgeToEdge = false,
}: {
  title: string;
  subtitle?: string;
  onBack?: () => void;
  /** Keep the back affordance visible while a pending operation locks navigation. */
  backDisabled?: boolean;
  action?: React.ReactNode;
  /** Full-width fixed-slot header; removes legacy body-gutter and bottom margins. */
  edgeToEdge?: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={[styles.header, edgeToEdge && styles.edgeToEdge]}>
      <View style={styles.row}>
        {onBack ? (
          <Pressable
            onPress={backDisabled ? undefined : onBack}
            disabled={backDisabled}
            accessibilityRole="button"
            accessibilityLabel="Go back"
            accessibilityState={{ disabled: backDisabled }}
            hitSlop={8}
            style={({ pressed }) => [styles.back, pressed && !backDisabled && styles.pressed, backDisabled && styles.disabled]}>
            <ArrowLeft size={19} color={theme.ink700} />
          </Pressable>
        ) : null}
        <View style={styles.copy}>
          <Text accessibilityRole="header" style={styles.title}>{title}</Text>
          {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
        </View>
        {action}
      </View>
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    header: {
      marginHorizontal: -18,
      marginBottom: 18,
      paddingHorizontal: 18,
      paddingTop: 12,
      paddingBottom: 14,
      backgroundColor: theme.header,
      borderBottomWidth: 1,
      borderBottomColor: theme.line,
    },
    edgeToEdge: { marginHorizontal: 0, marginBottom: 0 },
    row: { minHeight: 42, flexDirection: 'row', alignItems: 'center', gap: 10 },
    back: {
      width: 44,
      height: 44,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 12,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    copy: { flex: 1, minWidth: 0 },
    pressed: { opacity: 0.7 },
    disabled: { opacity: 0.5 },
    title: { ...type.pageTitle, letterSpacing: -0.6, color: theme.ink900 },
    subtitle: { marginTop: 3, fontSize: 11.5, fontFamily: font.body, color: theme.ink500 },
  });
}

const makeStyles = memoizeStyles(createStyles);
