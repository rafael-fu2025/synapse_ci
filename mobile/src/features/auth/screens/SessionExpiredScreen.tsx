import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Clock3 } from 'lucide-react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Action } from '../../../components/Action';
import { useAuth } from '../../../store/auth';
import { Theme, palette, type, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';

export function SessionExpiredScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const { acknowledgeExpired } = useAuth();

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <View style={styles.body}>
        <View style={styles.iconWrap}>
          <Clock3 size={30} color={palette.red700} />
        </View>
        <Text style={styles.title}>Your session has expired</Text>
        <Text style={styles.message}>
          Your session could not be renewed. Sign in again to continue securely.
        </Text>
        <View style={styles.actionWrap}>
          <Action full onPress={acknowledgeExpired}>
            Sign in again
          </Action>
        </View>
      </View>
    </SafeAreaView>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    safe: { flex: 1, backgroundColor: theme.canvas },
    body: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 26 },
    iconWrap: {
      width: 40,
      height: 40,
      alignItems: 'center',
      justifyContent: 'center',

      marginBottom: 16,
    },
    title: { ...type.hero, color: theme.ink900 },
    message: { maxWidth: 290, marginTop: 8, textAlign: 'center', color: theme.ink500, fontSize: 11.5, lineHeight: 18 },
    actionWrap: { alignSelf: 'stretch', marginTop: 22 },
  });
}

const makeStyles = memoizeStyles(createStyles);
