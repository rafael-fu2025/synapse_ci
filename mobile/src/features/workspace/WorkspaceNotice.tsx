import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { X } from 'lucide-react-native';
import { useTheme } from '../../theme/ThemeProvider';
import { Theme, memoizeStyles, type } from '../../theme/tokens';
import { IconAction } from '../../components/IconAction';
import { useWorkspace } from './WorkspaceContext';

export function WorkspaceNotice() {
  const { notice, clearNotice } = useWorkspace();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  if (!notice) { return null; }
  return (
    <View style={styles.notice}>
      <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={styles.text}>{notice}</Text>
      <IconAction label="Dismiss message" icon={<X size={18} color={theme.ink700} />} onPress={clearNotice} />
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    notice: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 16, paddingVertical: 8, backgroundColor: theme.surfaceWarm, borderBottomWidth: 1, borderBottomColor: theme.line },
    text: { ...type.body, color: theme.ink700, flex: 1 },
  });
}
const makeStyles = memoizeStyles(createStyles);
