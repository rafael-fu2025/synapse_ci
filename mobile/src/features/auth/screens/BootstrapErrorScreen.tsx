import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Action } from '../../../components/Action';
import { Screen } from '../../../components/Screen';
import { useAuth } from '../../../store/auth';
import { Theme, memoizeStyles, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { ServerConnectionControl } from '../../connection/ServerConnectionControl';

/** Safe holding screen when credentials exist but authoritative /me is unavailable. */
export function BootstrapErrorScreen() {
  const { retryBootstrap, signOut } = useAuth();
  const { theme } = useTheme();
  const styles = makeStyles(theme);

  return (
    <Screen>
      <View style={styles.center}>
        <Text accessibilityRole="header" style={styles.title}>Could not finish signing in</Text>
        <Text style={styles.body}>
          Your secure session is still available, but your account and access could not be verified. Retry when the connection is available.
        </Text>
        <ServerConnectionControl requiresSignIn />
        <Action full onPress={() => { retryBootstrap().catch(() => undefined); }}>Retry</Action>
        <Action full variant="secondary" onPress={() => { signOut().catch(() => undefined); }}>Sign out</Action>
      </View>
    </Screen>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    center: { flex: 1, justifyContent: 'center', alignItems: 'stretch', gap: 12, paddingHorizontal: 18 },
    title: { ...type.pageTitle, color: theme.ink900, textAlign: 'center' },
    body: { ...type.body, color: theme.ink500, textAlign: 'center', lineHeight: 19, marginBottom: 8 },
  });
}

const makeStyles = memoizeStyles(createStyles);
