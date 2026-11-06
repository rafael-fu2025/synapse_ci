import React from 'react';
import { Modal, StyleSheet, Text, View } from 'react-native';
import { Action } from '../../components/Action';
import { useWorkspace } from './WorkspaceContext';
import { Theme, memoizeStyles, type } from '../../theme/tokens';
import { useTheme } from '../../theme/ThemeProvider';

export function WorkspaceDiscardDialog() {
  const workspace = useWorkspace();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Modal visible={workspace.pendingDiscard} transparent animationType="fade" accessibilityViewIsModal onRequestClose={workspace.cancelDiscard}>
      <View style={styles.backdrop}>
        <View style={styles.dialog}>
          <Text accessibilityRole="header" style={styles.title}>Discard unsaved changes?</Text>
          <Text style={styles.body}>Changing portal or workspace will clear the unsaved draft held in memory.</Text>
          <Action full variant="danger" onPress={workspace.confirmDiscard}>Discard and continue</Action>
          <Action full variant="secondary" onPress={workspace.cancelDiscard}>Keep editing</Action>
        </View>
      </View>
    </Modal>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    backdrop: { flex: 1, justifyContent: 'center', padding: 24, backgroundColor: 'rgba(0,0,0,0.46)' },
    dialog: { padding: 20, gap: 12, borderRadius: 18, backgroundColor: theme.surface },
    title: { ...type.cardTitle, color: theme.ink900 },
    body: { ...type.body, color: theme.ink500, lineHeight: 19, marginBottom: 4 },
  });
}
const makeStyles = memoizeStyles(createStyles);
