import React, { useId, useState } from 'react';
import { StyleSheet, Text, TextInput, TextInputProps, View } from 'react-native';
import { Theme, font, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';
import { useKeyboardInputReveal } from './Screen';

type FieldProps = {
  label: string;
  error?: string;
  hint?: string;
  trailing?: React.ReactNode;
  inputRef?: React.Ref<React.ComponentRef<typeof TextInput>>;
  /** Extra clearance below this focused field, e.g. for an inline submit action. */
  keyboardRevealOffset?: number;
} & TextInputProps;

export function TextField({
  label, error, hint, trailing, inputRef, style, onFocus, onBlur, onLayout, keyboardRevealOffset = 16,
  accessibilityLabel, accessibilityHint, accessibilityLabelledBy, ...inputProps
}: FieldProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const [focused, setFocused] = useState(false);
  const labelId = `field-label-${useId()}`;
  const reveal = useKeyboardInputReveal(keyboardRevealOffset);
  const help = [accessibilityHint, error || hint].filter(Boolean).join('. ') || undefined;
  return (
    <View style={styles.field}>
      <Text nativeID={labelId} style={styles.label}>{label}</Text>
      <View style={styles.controlWrap}>
        <TextInput
          ref={inputRef}
          placeholderTextColor={theme.ink400}
          selectionColor={theme.brandIcon}
          {...inputProps}
          accessibilityLabel={accessibilityLabel ?? label}
          accessibilityLabelledBy={accessibilityLabelledBy ?? (accessibilityLabel ? undefined : labelId)}
          accessibilityHint={help}
          onFocus={event => { setFocused(true); reveal.onFocus(); onFocus?.(event); }}
          onBlur={event => { setFocused(false); reveal.onBlur(); onBlur?.(event); }}
          onLayout={event => { reveal.onLayout(); onLayout?.(event); }}
          style={[styles.control, focused && styles.controlFocused, error ? styles.controlError : null, trailing ? styles.withTrailing : null, style]}
        />
        {trailing ? <View style={styles.trailing}>{trailing}</View> : null}
      </View>
      {error ? (
        <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={styles.error}>{error}</Text>
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
      borderColor: theme.mode === 'dark' ? theme.line : '#ccd9d6',
      backgroundColor: theme.surfaceWarm,
    },
    controlFocused: { borderColor: theme.brandIcon },
    controlError: { borderColor: '#a33b3b' },
    error: { marginTop: 4, marginLeft: 2, color: '#a33b3b', fontSize: 9.5, fontFamily: font.body },
    hint: { marginTop: 4, marginLeft: 2, color: theme.ink500, fontSize: 9.5, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
