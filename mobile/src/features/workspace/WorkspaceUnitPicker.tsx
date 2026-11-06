import React from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { Check, X } from 'lucide-react-native';
import { useWorkspace } from './WorkspaceContext';
import { Theme, memoizeStyles, type } from '../../theme/tokens';
import { useTheme } from '../../theme/ThemeProvider';

export function WorkspaceUnitPicker() {
  const workspace = useWorkspace();
  const { theme } = useTheme();
  const styles = makeStyles(theme);

  return (
    <Modal
      visible={workspace.unitPickerVisible}
      transparent
      animationType="fade"
      accessibilityViewIsModal
      onRequestClose={workspace.closeUnitPicker}>
      <View style={styles.backdrop}>
        <View style={styles.sheet}>
          <View style={styles.headingRow}>
            <View style={styles.copy}>
              <Text accessibilityRole="header" style={styles.title}>Choose Team Workspace</Text>
              <Text style={styles.body}>Select the unit you want to open.</Text>
            </View>
            {workspace.personalEnabled || workspace.activeUnit !== null ? (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel="Close unit picker"
                onPress={workspace.closeUnitPicker}
                style={styles.close}>
                <X size={20} color={theme.ink700} />
              </Pressable>
            ) : null}
          </View>
          {workspace.units.map(item => {
            const selected = workspace.activeUnit?.unit === item.unit;
            return (
              <Pressable
                key={item.unit}
                accessibilityRole="button"
                accessibilityLabel={`Open ${item.label} workspace`}
                accessibilityState={{ selected }}
                onPress={() => workspace.requestDiscard(() => workspace.selectUnit(item.unit))}
                style={({ pressed }) => [styles.unit, selected && styles.unitSelected, pressed && styles.pressed]}>
                <View style={styles.copy}>
                  <Text style={styles.unitLabel}>{item.label}</Text>
                  <Text style={styles.unitMeta}>{item.can_manage ? 'Manager access' : 'Member access'}</Text>
                </View>
                {selected ? <Check size={20} color={theme.brandIcon} /> : null}
              </Pressable>
            );
          })}
        </View>
      </View>
    </Modal>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(0,0,0,0.42)' },
    sheet: { backgroundColor: theme.surface, borderTopLeftRadius: 22, borderTopRightRadius: 22, padding: 20, gap: 10 },
    headingRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, marginBottom: 4 },
    copy: { flex: 1, minWidth: 0 },
    title: { ...type.cardTitle, color: theme.ink900 },
    body: { ...type.body, color: theme.ink500, marginTop: 3 },
    close: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
    unit: { minHeight: 58, flexDirection: 'row', alignItems: 'center', padding: 12, borderRadius: 14, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surfaceWarm },
    unitSelected: { borderColor: theme.brand, backgroundColor: theme.brand50 },
    unitLabel: { ...type.bodyStrong, color: theme.ink900 },
    unitMeta: { ...type.caption, color: theme.ink500, marginTop: 2 },
    pressed: { opacity: 0.7 },
  });
}
const makeStyles = memoizeStyles(createStyles);
