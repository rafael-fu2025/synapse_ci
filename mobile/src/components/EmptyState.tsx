import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { type } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

export function EmptyState({
  icon,
  title,
  body,
  action,
}: {
  icon: React.ReactNode;
  title: string;
  body?: string;
  action?: React.ReactNode;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme.mode === 'dark');
  return (
    <View style={styles.state}>
      <View style={styles.iconWrap}>{icon}</View>
      <Text style={styles.title}>{title}</Text>
      {body ? <Text style={styles.body}>{body}</Text> : null}
      {action ? <View style={styles.actionWrap}>{action}</View> : null}
    </View>
  );
}

function makeStyles(dark: boolean) {
  return StyleSheet.create({
    state: { alignItems: 'center', paddingTop: 45, paddingBottom: 28, paddingHorizontal: 18 },
    iconWrap: {
      width: 40,
      height: 40,
      alignItems: 'center',
      justifyContent: 'center',
      marginBottom: 18,

    },
    title: { ...type.hero, fontSize: 19, color: dark ? '#ffffff' : '#111827' },
    body: { maxWidth: 290, marginTop: 7, marginBottom: 18, textAlign: 'center', fontSize: 11.5, lineHeight: 18, color: dark ? '#a3a3a3' : '#667085' },
    actionWrap: { alignSelf: 'stretch' },
  });
}
