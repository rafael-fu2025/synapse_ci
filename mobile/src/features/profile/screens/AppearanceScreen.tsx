import React from 'react';
import { Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Check, Monitor, Moon, Sun } from 'lucide-react-native';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { Theme, font } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { ThemePreference } from '../../../lib/storage';
import { ProfileStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<ProfileStackParamList, 'Appearance'>;

const OPTIONS: { key: ThemePreference; title: string; caption: string; icon: React.ReactNode }[] = [
  { key: 'system', title: 'Match system', caption: 'Follow your device setting automatically', icon: <Monitor size={16} color="#e3dcef" /> },
  { key: 'light', title: 'Light', caption: 'The default SYNAPSE palette', icon: <Sun size={16} color="#e3dcef" /> },
  { key: 'dark', title: 'Dark', caption: 'Easier on the eyes at night', icon: <Moon size={16} color="#e3dcef" /> },
];

export function AppearanceScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const { preference, setPreference } = useTheme();

  return (
    <Screen>
      <PageHeader title="Appearance" onBack={navigation.goBack} />

      <View style={styles.preview}>
        <Image source={require('../../../assets/brand/synapse-white.png')} style={styles.previewLogo} resizeMode="contain" />
        <View style={styles.previewCopy}>
          <Text style={styles.previewLabel}>CURRENT THEME</Text>
          <Text style={styles.previewValue}>{preference === 'system' ? 'Match system' : preference === 'dark' ? 'Dark' : 'Light'}</Text>
        </View>
      </View>

      <View style={styles.options}>
        {OPTIONS.map((option) => {
          const selected = preference === option.key;
          return (
            <Pressable key={option.key} onPress={() => setPreference(option.key)} style={styles.option}>
              <View style={styles.optionIcon}>{option.icon}</View>
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
    </Screen>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    preview: {
      minHeight: 155,
      alignItems: 'center',
      justifyContent: 'center',
      gap: 15,
      borderRadius: 17,
      backgroundColor: '#1c1c1c',
      borderWidth: 1,
      borderColor: '#484848',
    },
    previewLogo: { width: 175, height: 50 },
    previewCopy: { alignItems: 'center' },
    previewLabel: { color: '#a3a3a3', fontSize: 8, letterSpacing: 1, fontWeight: '700', fontFamily: font.body },
    previewValue: { marginTop: 2, fontSize: 11, color: '#ffffff', fontWeight: '600', fontFamily: font.body },
    options: {
      marginTop: 17,
      borderRadius: 14,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
      overflow: 'hidden',
    },
    option: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, borderBottomWidth: 1, borderBottomColor: theme.line },
    optionIcon: { width: 37, height: 37, alignItems: 'center', justifyContent: 'center', borderRadius: 10, backgroundColor: '#282828' },
    optionCopy: { flex: 1 },
    optionTitle: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    optionCaption: { marginTop: 2, color: theme.ink500, fontSize: 8.5, fontFamily: font.body },
    radio: { width: 18, height: 18, alignItems: 'center', justifyContent: 'center', borderRadius: 999, borderWidth: 1, borderColor: '#777777' },
    radioSelected: { backgroundColor: theme.brand, borderColor: theme.brand },
  });
}
