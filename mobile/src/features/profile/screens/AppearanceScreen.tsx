import React from 'react';
import { Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Check, Monitor, Moon, Sun } from 'lucide-react-native';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { Theme, font, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { ThemePreference } from '../../../lib/storage';
import { ProfileStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<ProfileStackParamList, 'Appearance'>;

const OPTIONS: { key: ThemePreference; title: string; caption: string; icon: typeof Monitor }[] = [
  { key: 'system', title: 'Match system', caption: 'Follow your device setting automatically', icon: Monitor },
  { key: 'light', title: 'Light', caption: 'The default SYNAPSE palette', icon: Sun },
  { key: 'dark', title: 'Dark', caption: 'Easier on the eyes at night', icon: Moon },
];

export function AppearanceScreen() {
  const { theme, mode, preference, setPreference } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();

  return (
    <Screen>
      <PageHeader title="Appearance" subtitle="Saved on this device" onBack={navigation.goBack} />

      <View style={styles.preview}>
        <Image source={mode === 'dark' ? require('../../../assets/brand/synapse-white.png') : require('../../../assets/brand/synapse-maroon.png')} style={styles.previewLogo} resizeMode="contain" accessibilityLabel="SYNAPSE" />
        <View style={styles.previewCopy}>
          <Text style={styles.previewLabel}>CURRENT THEME</Text>
          <Text style={styles.previewValue}>{`${mode === 'dark' ? 'Dark' : 'Light'} mode${preference === 'system' ? ' · device setting' : ''}`}</Text>
        </View>
      </View>

      <Text style={styles.sectionLabel}>THEME</Text>
      <View accessibilityRole="radiogroup" style={styles.options}>
        {OPTIONS.map((option) => {
          const selected = preference === option.key;
          return (
            <Pressable key={option.key} accessibilityRole="radio" accessibilityLabel={option.title} accessibilityState={{ checked: selected }} onPress={() => setPreference(option.key)} style={styles.option}>
              <View style={styles.optionIcon}><option.icon size={16} color={theme.brandIcon} /></View>
              <View style={styles.optionCopy}>
                <Text style={styles.optionTitle}>{option.title}</Text>
                <Text style={styles.optionCaption}>{option.caption}</Text>
              </View>
              <View style={[styles.radio, selected && styles.radioSelected]}>
                {selected ? <Check size={11} color="#ffffff" /> : null}
              </View>
            </Pressable>
          );
        })}
      </View>
      <Text style={styles.note}>Maroon remains the primary action color in both themes, matching the SYNAPSE web portal.</Text>
    </Screen>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    preview: {
      minHeight: 155,
      alignItems: 'center',
      justifyContent: 'center',
      gap: 15,
      borderRadius: 17,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    previewLogo: { width: 175, height: 50 },
    previewCopy: { alignItems: 'center' },
    previewLabel: { color: theme.ink500, fontSize: 8, letterSpacing: 1, fontWeight: '700', fontFamily: font.body },
    previewValue: { marginTop: 2, fontSize: 11, color: theme.ink900, fontWeight: '600', fontFamily: font.body },
    sectionLabel: { marginTop: 17, marginBottom: 8, color: theme.ink400, fontSize: 8.5, fontWeight: '800', letterSpacing: 1, fontFamily: font.body },
    note: { marginTop: 14, padding: 12, borderRadius: 12, backgroundColor: theme.surfaceWarm, color: theme.ink500, fontSize: 9, lineHeight: 14, fontFamily: font.body },
    options: {
      borderRadius: 14,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
      overflow: 'hidden',
    },
    option: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, minHeight: 61, borderBottomWidth: 1, borderBottomColor: theme.line },
    optionIcon: { width: 26, height: 26, alignItems: 'center', justifyContent: 'center' },
    optionCopy: { flex: 1 },
    optionTitle: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    optionCaption: { marginTop: 2, color: theme.ink500, fontSize: 8.5, fontFamily: font.body },
    radio: { width: 18, height: 18, alignItems: 'center', justifyContent: 'center', borderRadius: 999, borderWidth: 1, borderColor: '#777777' },
    radioSelected: { backgroundColor: theme.brand, borderColor: theme.brand },
  });
}

const makeStyles = memoizeStyles(createStyles);
