import React from 'react';
import { StatusBar } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { QueryClientProvider } from '@tanstack/react-query';
import { RootNavigator } from './src/navigation/RootNavigator';
import { queryClient } from './src/lib/queryClient';
import { QueryLifecycle } from './src/lib/QueryLifecycle';
import { AuthProvider, useAuth } from './src/store/auth';
import { WorkspaceProvider } from './src/features/workspace/WorkspaceContext';
import { ThemeProvider, useTheme } from './src/theme/ThemeProvider';
import { OverlayProvider } from './src/components/OverlayProvider';
import { WorkspaceUnitPicker } from './src/features/workspace/WorkspaceUnitPicker';
import { WorkspaceDiscardDialog } from './src/features/workspace/WorkspaceDiscardDialog';

function ThemedStatusBar() {
  const { theme } = useTheme();
  return <StatusBar barStyle={theme.mode === 'dark' ? 'light-content' : 'dark-content'} />;
}

function SessionScreens() {
  const { profile } = useAuth();
  const sessionKey = profile ? `${profile.user.tenant_id}:${profile.user.id}` : 'signed-out';
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
        <QueryClientProvider client={queryClient}>
          <QueryLifecycle />
          <AuthProvider>
            <SessionScreens />
          </AuthProvider>
        </QueryClientProvider>
      </ThemeProvider>
    </SafeAreaProvider>
  );
}
