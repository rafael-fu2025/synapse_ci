import React from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Theme, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

/** ScreenShell equivalent: canvas background, safe-area padding, scrollable content. */
export function Screen({ children, scroll = true }: { children: React.ReactNode; scroll?: boolean }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const body = <View style={styles.content}>{children}</View>;
  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      {scroll ? (
        <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
          {body}
        </ScrollView>
      ) : (
        body
      )}
    </SafeAreaView>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    safe: { flex: 1, backgroundColor: theme.canvas },
    scroll: { flexGrow: 1 },
    content: { flex: 1, paddingHorizontal: 18, paddingBottom: 20 },
  });
}

const makeStyles = memoizeStyles(createStyles);
