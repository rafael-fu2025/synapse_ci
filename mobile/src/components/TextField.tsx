import React from 'react';
import { StyleSheet, Text, TextInput, TextInputProps, View } from 'react-native';
import { Theme, font } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

type FieldProps = {
  label: string;
  error?: string;
  hint?: string;
} & TextInputProps;

export function TextField({ label, error, hint, style, ...inputProps }: FieldProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        placeholderTextColor={theme.ink400}
        {...inputProps}
        style={[styles.control, error ? styles.controlError : null, style]}
      />
      {error ? (
        <Text style={styles.error}>{error}</Text>
      ) : hint ? (
        <Text style={styles.hint}>{hint}</Text>
      ) : null}
    </View>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    field: { marginTop: 15 },
    label: { marginBottom: 6, marginLeft: 2, fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink700 },
    control: {
      minHeight: 46,
      paddingHorizontal: 13,
      borderWidth: 1,
      borderRadius: 12,
      fontSize: 12,
      fontFamily: font.body,
      color: theme.ink900,
      borderColor: '#ccd9d6',
      backgroundColor: theme.surfaceWarm,
    },
    controlError: { borderColor: '#a33b3b' },
    error: { marginTop: 4, marginLeft: 2, color: '#a33b3b', fontSize: 9.5, fontFamily: font.body },
    hint: { marginTop: 4, marginLeft: 2, color: theme.ink500, fontSize: 9.5, fontFamily: font.body },
  });
}
