import React from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { Theme, font, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

/**
 * Dashed "nothing here yet" card. It keeps a slot (queue ticket, announcement, survey,
 * appointment) visible when there is no data so the layout never jumps or disappears.
 */
export function PlaceholderCard({
  icon,

  title,
  body,
  loading = false,
  onPress,
  actionLabel,
}: {
  icon: React.ReactNode;

  title: string;
  body: string;
  loading?: boolean;
  onPress?: () => void;
  actionLabel?: string;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const content = (
    <>
      <View style={styles.icon}>
        {loading ? <ActivityIndicator size="small" color={theme.brandIcon} /> : icon}
      </View>
      <View style={styles.copy}>
        <Text style={styles.title}>{title}</Text>
        <Text style={styles.body}>{body}</Text>
      </View>
    </>
  );

  if (onPress) {
    return (
      <Pressable
        onPress={onPress}
        accessibilityRole="button"
        accessibilityLabel={actionLabel ?? title}
        style={({ pressed }) => [styles.card, pressed && styles.pressed]}>
        {content}
      </Pressable>
    );
  }
  return (
    <View style={styles.card} accessible accessibilityLabel={`${title}. ${body}`}>
      {content}
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    card: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 12,
      padding: 14,
      borderRadius: 15,
      borderWidth: 1,
      borderStyle: 'dashed',
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    pressed: { opacity: 0.75 },
    icon: { width: 24, minHeight: 24, alignItems: 'center', justifyContent: 'center' },
    copy: { flex: 1 },
    title: { fontSize: 12, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    body: { marginTop: 2, color: theme.ink500, fontSize: 10, lineHeight: 14, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
