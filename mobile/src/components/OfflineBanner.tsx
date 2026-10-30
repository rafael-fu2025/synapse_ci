import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { WifiOff } from 'lucide-react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Theme, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

/** Shown when the device loses connectivity; queries refetch automatically on reconnect. */
export function OfflineBanner() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <SafeAreaView edges={['top']} style={styles.wrap}>
      <View style={styles.row}>
        <WifiOff size={13} color="#79591f" />
        <Text style={styles.text}>You are offline — some content may be out of date.</Text>
      </View>
    </SafeAreaView>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    wrap: { backgroundColor: '#f9efd9' },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'center',
      gap: 7,
      paddingVertical: 7,
      paddingHorizontal: 16,
      borderBottomWidth: 1,
      borderBottomColor: theme.line,
    },
    text: { color: '#79591f', fontSize: 9.5, fontWeight: '600' },
  });
}

const makeStyles = memoizeStyles(createStyles);
