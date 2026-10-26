import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Theme, font, type } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

export function SectionHeading({
  title,
  caption,
  trailing,
}: {
  title: string;
  caption?: string;
  trailing?: React.ReactNode;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.heading}>
      <View style={styles.flex}>
        <Text style={styles.title}>{title}</Text>
        {caption ? <Text style={styles.caption}>{caption}</Text> : null}
      </View>
      {trailing}
    </View>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    heading: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginHorizontal: 1, marginBottom: 10, marginTop: 19 },
    flex: { flex: 1, minWidth: 0 },
    title: { ...type.cardTitle, color: theme.ink900 },
    caption: { marginTop: 2, fontSize: 10.5, fontFamily: font.body, color: theme.ink500 },
  });
}
