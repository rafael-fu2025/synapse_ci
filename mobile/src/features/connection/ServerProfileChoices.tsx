import React from 'react';
import { Animated, Pressable, StyleSheet, Text, View } from 'react-native';
import { usePressFeedback } from '../../lib/usePressFeedback';
import { useTheme } from '../../theme/ThemeProvider';
import { memoizeStyles, Theme, type } from '../../theme/tokens';
import { CONNECTION_PROFILES, ConnectionProfile, ConnectionProfileId } from './profiles';

type Props = {
  selectedId: ConnectionProfileId | null;
  currentId?: ConnectionProfileId;
  onSelect: (id: ConnectionProfileId) => void;
  disabled?: boolean;
};

/** Choosing a radio only changes the provisional choice, never the runtime. */
export function ServerProfileChoices({ selectedId, currentId, onSelect, disabled = false }: Props) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View accessibilityRole="radiogroup" accessibilityLabel="Server profiles" style={styles.list}>
      {CONNECTION_PROFILES.map(profile => (
        <ProfileChoice
          key={profile.id}
          profile={profile}
          checked={selectedId === profile.id}
          current={currentId === profile.id}
          disabled={disabled}
          onSelect={onSelect}
        />
      ))}
    </View>
  );
}

function ProfileChoice({ profile, checked, current, disabled, onSelect }: {
  profile: ConnectionProfile;
  checked: boolean;
  current: boolean;
  disabled: boolean;
  onSelect: Props['onSelect'];
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const feedback = usePressFeedback(disabled);
  return (
    <Pressable
      accessibilityRole="radio"
      accessibilityLabel={`${profile.label}, ${profile.apiBaseUrl}${current ? ', current server' : ''}`}
      accessibilityHint="Selects this server for review before switching"
      accessibilityState={{ checked, disabled }}
      disabled={disabled}
      onPress={disabled ? undefined : () => onSelect(profile.id)}
      onPressIn={feedback.onPressIn}
      onPressOut={feedback.onPressOut}
      testID={`server-profile-${profile.id}`}
      style={({ pressed }) => [styles.choice, checked && styles.checked, pressed && !disabled && styles.pressed, disabled && styles.disabled]}>
      <Animated.View style={[styles.content, { transform: [{ scale: feedback.scale }] }]} pointerEvents="none">
        <View style={[styles.radio, checked && styles.checkedRadio]} accessible={false}>
          {checked ? <View style={styles.dot} /> : null}
        </View>
        <View style={styles.copy}>
          <Text style={styles.label}>{profile.label}</Text>
          <Text style={styles.address}>{profile.apiBaseUrl}</Text>
          {current ? <Text style={styles.current}>Current server</Text> : null}
        </View>
      </Animated.View>
    </Pressable>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    list: { gap: 10 },
    choice: { minHeight: 64, padding: 14, borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    checked: { borderColor: theme.brandIcon, backgroundColor: theme.brand50 },
    pressed: { opacity: 0.75 },
    disabled: { opacity: 0.5 },
    content: { flexDirection: 'row', alignItems: 'flex-start', gap: 12 },
    radio: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, borderColor: theme.ink400, alignItems: 'center', justifyContent: 'center', marginTop: 1 },
    checkedRadio: { borderColor: theme.brandIcon },
    dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: theme.brandIcon },
    copy: { flex: 1, minWidth: 0 },
    label: { ...type.action, color: theme.ink900 },
    address: { ...type.body, color: theme.ink500, marginTop: 4, flexShrink: 1 },
    current: { ...type.caption, color: theme.brandText, marginTop: 4 },
  });
}
const makeStyles = memoizeStyles(createStyles);
