import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { ArrowLeft } from 'lucide-react-native';
import { Theme, font, type } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

export function PageHeader({
  title,
  subtitle,
  onBack,
  action,
}: {
  title: string;
  subtitle?: string;
  onBack?: () => void;
  action?: React.ReactNode;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.header}>
      <View style={styles.row}>
        {onBack ? (
          <Pressable
            onPress={onBack}
            accessibilityRole="button"
            accessibilityLabel="Go back"
            hitSlop={8}
            style={styles.back}>
            <ArrowLeft size={19} color={theme.ink700} />
          </Pressable>
        ) : null}
        <View style={styles.copy}>
          <Text style={styles.title}>{title}</Text>
          {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
        </View>
        {action}
      </View>
    </View>
  );
}

function makeStyles(theme: Theme) {
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
    row: { minHeight: 42, flexDirection: 'row', alignItems: 'center', gap: 10 },
    back: {
      width: 38,
      height: 38,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 12,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    copy: { flex: 1, minWidth: 0 },
    title: { ...type.pageTitle, letterSpacing: -0.6, color: theme.ink900 },
    subtitle: { marginTop: 3, fontSize: 11.5, fontFamily: font.body, color: theme.ink500 },
  });
}
