import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Action } from '../../../components/Action';
import { Theme, font, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { appointmentErrorMessage } from '../errors';

export function AppointmentLoadError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.card} accessibilityRole="alert">
      <Text style={styles.title}>Could not load appointments</Text>
      <Text style={styles.message}>
        {appointmentErrorMessage(error, 'Appointments are unavailable right now. Please try again.')}
      </Text>
      <Action variant="secondary" onPress={onRetry}>Try again</Action>
    </View>
  );
}

const makeStyles = memoizeStyles((theme: Theme) => StyleSheet.create({
  card: { padding: 14, marginBottom: 14, gap: 10, borderRadius: 12, backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.line },
  title: { color: theme.ink900, fontFamily: font.body, fontWeight: '700', fontSize: 13 },
  message: { color: theme.ink700, fontFamily: font.body, fontSize: 11.5, lineHeight: 17 },
}));
