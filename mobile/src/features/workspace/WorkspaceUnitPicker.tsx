import React, { useRef, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Check } from 'lucide-react-native';
import { BottomSheet } from '../../components/BottomSheet';
import type { TeamUnit } from '../../api/types';
import { useWorkspace } from './WorkspaceContext';
import { Theme, memoizeStyles, type } from '../../theme/tokens';
import { useTheme } from '../../theme/ThemeProvider';

export function WorkspaceUnitPicker() {
  const workspace = useWorkspace();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const [closing, setClosing] = useState(false);
  const selectedUnit = useRef<TeamUnit | null>(null);
  const dismissible = workspace.personalEnabled || workspace.activeUnit !== null;

  return (
    <BottomSheet
      visible={workspace.unitPickerVisible && !closing}
      title="Choose Team Workspace"
      description="Select the unit you want to open."
      closeLabel="Close unit picker"
      dismissible={dismissible && !workspace.hasPendingSubmission}
      onClose={workspace.closeUnitPicker}
      onDismiss={() => {
        const unit = selectedUnit.current;
        selectedUnit.current = null;
        if (unit !== null) { workspace.selectUnit(unit); }
        setClosing(false);
      }}>
      {workspace.units.map(item => {
        const selected = workspace.activeUnit?.unit === item.unit;
        return (
          <Pressable
            key={item.unit}
            accessibilityRole="button"
            accessibilityLabel={`Open ${item.label} workspace`}
            accessibilityState={{ selected, disabled: closing || workspace.hasPendingSubmission }}
            disabled={closing || workspace.hasPendingSubmission}
            onPress={() => {
              if (selectedUnit.current !== null) { return; }
              selectedUnit.current = item.unit;
              setClosing(true);
            }}
            style={({ pressed }) => [styles.unit, selected && styles.unitSelected, pressed && styles.pressed]}>
            <View style={styles.copy}>
              <Text style={styles.unitLabel}>{item.label}</Text>
              <Text style={styles.unitMeta}>{item.can_manage ? 'Manager access' : 'Member access'}</Text>
            </View>
            {selected ? <Check size={20} color={theme.brandIcon} /> : null}
          </Pressable>
        );
      })}
    </BottomSheet>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    copy: { flex: 1, minWidth: 0 },
    unit: { minHeight: 58, flexDirection: 'row', alignItems: 'center', padding: 12, marginBottom: 10, borderRadius: 14, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surfaceWarm },
    unitSelected: { borderColor: theme.brand, backgroundColor: theme.brand50 },
    unitLabel: { ...type.bodyStrong, color: theme.ink900 },
    unitMeta: { ...type.caption, color: theme.ink500, marginTop: 2 },
    pressed: { opacity: 0.7 },
  });
}
const makeStyles = memoizeStyles(createStyles);
