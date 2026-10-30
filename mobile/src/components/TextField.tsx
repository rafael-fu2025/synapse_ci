import React from 'react';
import { StyleSheet, Text, TextInput, TextInputProps, View } from 'react-native';
import { Theme, font, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

type FieldProps = {
  label: string;
  error?: string;
  hint?: string;
  trailing?: React.ReactNode;
  inputRef?: React.Ref<React.ComponentRef<typeof TextInput>>;
} & TextInputProps;

export function TextField({ label, error, hint, trailing, inputRef, style, ...inputProps }: FieldProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <View style={styles.controlWrap}>
        <TextInput
          ref={inputRef}
          placeholderTextColor={theme.ink400}
          {...inputProps}
          style={[styles.control, error ? styles.controlError : null, trailing ? styles.withTrailing : null, style]}
        />
        {trailing ? <View style={styles.trailing}>{trailing}</View> : null}
      </View>
      {error ? (
        <Text style={styles.error}>{error}</Text>
      ) : hint ? (
        <Text style={styles.hint}>{hint}</Text>
      ) : null}
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    field: { marginTop: 15 },
    label: { marginBottom: 6, marginLeft: 2, fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink700 },
    controlWrap: { position: 'relative' },
    trailing: { position: 'absolute', right: 1, top: 1, bottom: 1, width: 44, alignItems: 'center', justifyContent: 'center' },
    withTrailing: { paddingRight: 54 },
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

const makeStyles = memoizeStyles(createStyles);
