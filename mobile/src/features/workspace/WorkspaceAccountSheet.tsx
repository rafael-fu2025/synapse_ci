import React, { useRef } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Bell, Settings, Users } from 'lucide-react-native';
import { Action } from '../../components/Action';
import { BottomSheet } from '../../components/BottomSheet';
import { useAuth } from '../../store/auth';
import { useTheme } from '../../theme/ThemeProvider';
import { Theme, memoizeStyles, type } from '../../theme/tokens';
import { navigationRef } from '../../navigation/navigationRef';
import { useWorkspace } from './WorkspaceContext';

export function WorkspaceAccountSheet({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const { user, profile } = useAuth();
  const { activeUnit, units, openUnitPicker, showNotice, hasPendingSubmission } = useWorkspace();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const afterClose = useRef<(() => void) | null>(null);
  const currentIdentity = profile ? `${profile.user.tenant_id}:${profile.user.id}` : null;
  const owner = useRef(currentIdentity);
  owner.current = currentIdentity;
  function open(destination: 'Notifications' | 'AccountSettings') {
    if (!visible || hasPendingSubmission || afterClose.current !== null) { return; }
    const identity = owner.current;
    afterClose.current = () => {
      if (identity !== owner.current) { return; }
      if (navigationRef.isReady()) { navigationRef.navigate(destination); }
      else { showNotice('This screen is not ready yet. Please try again.'); }
    };
    onClose();
  }
  function switchWorkspace() {
    if (!visible || hasPendingSubmission || afterClose.current !== null) { return; }
    const identity = owner.current;
    afterClose.current = () => { if (identity === owner.current) { openUnitPicker(); } };
    onClose();
  }
  return (
    <BottomSheet
      visible={visible}
      title="Your account"
      description={activeUnit ? `${activeUnit.label} · Team Workspace` : 'SYNAPSE Mobile'}
      closeLabel="Close account menu"
      onClose={onClose}
      onDismiss={() => { const action = afterClose.current; afterClose.current = null; action?.(); }}>
      <View style={styles.identity}>
        <Text style={styles.name}>{user?.name ?? 'Your account'}</Text>
        <Text style={styles.identifier}>{user?.identifier}</Text>
      </View>
      <View style={styles.actions}>
        {units.length > 1 ? <Action full variant="secondary" disabled={hasPendingSubmission} icon={<Users size={18} color={theme.brandIcon} />} onPress={switchWorkspace}>Switch workspace</Action> : null}
        <Action full variant="secondary" disabled={hasPendingSubmission} icon={<Bell size={18} color={theme.brandIcon} />} onPress={() => open('Notifications')}>Notifications</Action>
        <Action full variant="secondary" disabled={hasPendingSubmission} icon={<Settings size={18} color={theme.brandIcon} />} onPress={() => open('AccountSettings')}>Settings and sign out</Action>
      </View>
    </BottomSheet>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    identity: { paddingVertical: 12, gap: 4 },
    name: { ...type.bodyStrong, color: theme.ink900 },
    identifier: { ...type.caption, color: theme.ink500 },
    actions: { gap: 10, paddingVertical: 8 },
  });
}
const makeStyles = memoizeStyles(createStyles);
