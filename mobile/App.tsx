import React from 'react';
import { StatusBar } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { ConnectionProvider } from './src/features/connection/ConnectionProvider';
import { useOptionalConnection } from './src/features/connection/ConnectionContext';
import { RootNavigator } from './src/navigation/RootNavigator';
import { QueryLifecycle } from './src/lib/QueryLifecycle';
import { AuthProvider, useAuth } from './src/store/auth';
import { WorkspaceProvider } from './src/features/workspace/WorkspaceContext';
import { ThemeProvider, useTheme } from './src/theme/ThemeProvider';
import { OverlayProvider } from './src/components/OverlayProvider';
import { SystemNavigationBarProvider } from './src/components/SystemNavigationBar';
import { NotificationPermissionLifecycle } from './src/components/NotificationPermissionLifecycle';
import { WorkspaceUnitPicker } from './src/features/workspace/WorkspaceUnitPicker';
import { WorkspaceDiscardDialog } from './src/features/workspace/WorkspaceDiscardDialog';

function ThemedStatusBar() {
  const { theme } = useTheme();
  return <StatusBar barStyle={theme.mode === 'dark' ? 'light-content' : 'dark-content'} />;
}

function SessionScreens() {
  const { profile } = useAuth();
  const owner = useOptionalConnection()?.owner ?? 0;
  const sessionKey = `${owner}:${profile ? `${profile.user.tenant_id}:${profile.user.id}` : 'signed-out'}`;
  return (
    <WorkspaceProvider key={sessionKey}>
      <OverlayProvider>
        <ThemedStatusBar />
        <RootNavigator />
        <WorkspaceUnitPicker />
        <WorkspaceDiscardDialog />
      </OverlayProvider>
    </WorkspaceProvider>
  );
}

export default function App(): React.JSX.Element {
  return (
    <SafeAreaProvider>
      <ThemeProvider>
        <SystemNavigationBarProvider>
          <NotificationPermissionLifecycle />
          <ConnectionProvider>
            <QueryLifecycle />
            <AuthProvider>
              <SessionScreens />
            </AuthProvider>
          </ConnectionProvider>
        </SystemNavigationBarProvider>
      </ThemeProvider>
    </SafeAreaProvider>
  );
}
