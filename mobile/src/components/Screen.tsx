import React from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import type { StyleProp, ViewStyle } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Theme, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

/** ScreenShell equivalent: canvas background, safe-area padding, scrollable content. */
export function Screen({ children, scroll = true, header, bodyStyle }: {
  children: React.ReactNode;
  scroll?: boolean;
  /** Fixed, full-width content inside the top safe area, outside the body. */
  header?: React.ReactNode;
  /** Overrides the body only when scroll is false (for an owned list viewport). */
  bodyStyle?: StyleProp<ViewStyle>;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const body = (
    <View style={[styles.content, scroll && header != null && styles.headerContent, !scroll && bodyStyle]}>
      {children}
    </View>
  );
  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      {header}
      {scroll ? (
        <ScrollView style={styles.viewport} contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
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
    viewport: { flex: 1 },
    scroll: { flexGrow: 1 },
    content: { flex: 1, paddingHorizontal: 18, paddingBottom: 20 },
    headerContent: { paddingTop: 18 },
  });
}

const makeStyles = memoizeStyles(createStyles);
