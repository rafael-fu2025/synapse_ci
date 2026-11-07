import React, { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { Bell, CircleUserRound, Users } from 'lucide-react-native';
import { IconAction } from '../../components/IconAction';
import { useTheme } from '../../theme/ThemeProvider';
import { navigationRef } from '../../navigation/navigationRef';
import { useNotificationInbox } from '../notifications/hooks';
import { useWorkspace } from './WorkspaceContext';
import { WorkspaceAccountSheet } from './WorkspaceAccountSheet';

/** Root screens share account/bell access; detail screens retain room for their action. */
export function WorkspaceHeaderActions({ children, root = false, disabled = false }: {
  children?: React.ReactNode;
  root?: boolean;
  disabled?: boolean;
}) {
  const { theme } = useTheme();
  const workspace = useWorkspace();
  const [accountVisible, setAccountVisible] = useState(false);
  const locked = disabled || workspace.hasPendingSubmission;
  return (
    <View style={styles.actions}>
      {!root && workspace.units.length > 1 ? (
        <IconAction label="Switch Team Workspace unit" icon={<Users size={19} color={theme.brandIcon} />} disabled={locked} onPress={workspace.openUnitPicker} />
      ) : null}
      {root ? (
        <>
          <WorkspaceNotificationButton disabled={locked} />
          <IconAction label="Open account menu" icon={<CircleUserRound size={19} color={theme.brandIcon} />} disabled={locked} onPress={() => setAccountVisible(true)} />
          <WorkspaceAccountSheet visible={accountVisible} onClose={() => setAccountVisible(false)} />
        </>
      ) : null}
      {children}
    </View>
  );
}

function WorkspaceNotificationButton({ disabled }: { disabled: boolean }) {
  const { theme } = useTheme();
  const { showNotice } = useWorkspace();
  const inbox = useNotificationInbox();
  return (
    <IconAction
      label="Open notifications"
      icon={<Bell size={19} color={theme.brandIcon} />}
      badge={(inbox.data?.data.unread_count ?? 0) > 0}
      disabled={disabled}
      onPress={() => {
        if (navigationRef.isReady()) { navigationRef.navigate('Notifications'); }
        else { showNotice('Notifications are not ready yet. Please try again.'); }
      }}
    />
  );
}

const styles = StyleSheet.create({ actions: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', justifyContent: 'flex-end', gap: 6 } });
