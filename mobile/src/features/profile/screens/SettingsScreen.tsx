import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { ChevronRight, Moon, ShieldCheck, Smartphone, LogOut } from 'lucide-react-native';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { Theme, font } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { useAuth } from '../../../store/auth';
import { ProfileStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<ProfileStackParamList, 'Settings'>;

export function SettingsScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const { signOut } = useAuth();

  return (
    <Screen>
      <PageHeader title="Settings" onBack={navigation.goBack} />

      <View style={styles.group}>
        <Text style={styles.groupLabel}>PREFERENCES</Text>
        <SettingsRow icon={<Moon size={16} color={theme.brand} />} title="Appearance" caption="Light, dark, or system theme" onPress={() => navigation.navigate('Appearance')} />
      </View>

      <View style={styles.group}>
        <Text style={styles.groupLabel}>ACCOUNT</Text>
        <SettingsRow icon={<ShieldCheck size={16} color={theme.brand} />} title="Privacy & security" caption="Data handling and session control" onPress={() => navigation.navigate('PrivacySecurity')} />
      </View>

      <Pressable style={styles.signOut} onPress={() => {
        signOut().catch(() => undefined);
      }}>
        <LogOut size={15} color="#a33b3b" />
        <Text style={styles.signOutText}>Sign out</Text>
      </Pressable>

      <View style={styles.footer}>
        <Text style={styles.footerText}>SYNAPSE Mobile · v1.0.0</Text>
        <Text style={styles.footerText}>Foundation University · Dumaguete City</Text>
        <View style={styles.deviceRow}>
          <Smartphone size={15} color={theme.brand} />
          <Text style={styles.deviceText}>Android · session tokens stored in device secure storage</Text>
        </View>
      </View>
    </Screen>
  );
}

function SettingsRow({
  icon,
  title,
  caption,
  onPress,
}: {
  icon: React.ReactNode;
  title: string;
  caption: string;
  onPress: () => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.row, pressed && styles.pressed]}>
      <View style={styles.rowIcon}>{icon}</View>
      <View style={styles.rowCopy}>
        <Text style={styles.rowTitle}>{title}</Text>
        <Text style={styles.rowCaption}>{caption}</Text>
      </View>
      <ChevronRight size={16} color={theme.ink400} />
    </Pressable>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    group: {
      marginBottom: 17,
      borderRadius: 14,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
      overflow: 'hidden',
    },
    groupLabel: { padding: 10, paddingBottom: 7, paddingHorizontal: 12, backgroundColor: theme.surfaceWarm, color: theme.ink400, fontSize: 8.5, fontWeight: '800', letterSpacing: 1, fontFamily: font.body },
    row: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, borderWidth: 1, borderColor: theme.line, borderBottomWidth: 1 },
    pressed: { opacity: 0.75 },
    rowIcon: { width: 37, height: 37, alignItems: 'center', justifyContent: 'center', borderRadius: 10, backgroundColor: theme.brand50 },
    rowCopy: { flex: 1 },
    rowTitle: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    rowCaption: { marginTop: 2, color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
    signOut: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'center',
      gap: 8,
      padding: 12,
      marginTop: 4,
      borderRadius: 12,
      backgroundColor: 'rgba(163,59,59,0.12)',
    },
    signOutText: { color: '#a33b3b', fontSize: 11, fontWeight: '700', fontFamily: font.body },
    footer: { marginTop: 20, alignItems: 'center', gap: 3 },
    footerText: { color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
    deviceRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 8 },
    deviceText: { color: theme.ink500, fontSize: 8.5, fontFamily: font.body },
  });
}
