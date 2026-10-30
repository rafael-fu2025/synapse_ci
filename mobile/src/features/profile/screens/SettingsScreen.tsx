import React from 'react';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { version } from '../../../../package.json';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { ChevronRight, Moon, ShieldCheck, Smartphone, LogOut } from 'lucide-react-native';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { Theme, font, memoizeStyles } from '../../../theme/tokens';
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
        <SettingsRow icon={<Moon size={16} color={theme.brandIcon} />} title="Appearance" caption="Light, dark, or system theme" onPress={() => navigation.navigate('Appearance')} />
      </View>

      <View style={styles.group}>
        <Text style={styles.groupLabel}>PRIVACY</Text>
        <SettingsRow icon={<ShieldCheck size={16} color={theme.brandIcon} />} title="Privacy & security" caption="Data handling and session control" onPress={() => navigation.navigate('PrivacySecurity')} />
      </View>

      <Pressable accessibilityRole="button" accessibilityLabel="Sign out" style={styles.signOut} onPress={() => {
        signOut().catch(() => undefined);
      }}>
        <LogOut size={15} color="#ffffff" />
        <Text style={styles.signOutText}>Sign out</Text>
      </Pressable>

      <View style={styles.footer}>
        <Text style={styles.footerText}>{`SYNAPSE Mobile · v${version}`}</Text>
        <Text style={styles.footerText}>Foundation University · Dumaguete City</Text>
        <View style={styles.deviceRow}>
          <Smartphone size={15} color={theme.brandIcon} />
          <Text style={styles.deviceText}>{`${Platform.OS === 'ios' ? 'iOS' : Platform.OS === 'android' ? 'Android' : Platform.OS} · refresh token secured on this device`}</Text>
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
    <Pressable accessibilityRole="button" accessibilityLabel={title} onPress={onPress} style={({ pressed }) => [styles.row, pressed && styles.pressed]}>
      <View style={styles.rowIcon}>{icon}</View>
      <View style={styles.rowCopy}>
        <Text style={styles.rowTitle}>{title}</Text>
        <Text style={styles.rowCaption}>{caption}</Text>
      </View>
      <ChevronRight size={16} color={theme.ink400} />
    </Pressable>
  );
}

function createStyles(theme: Theme) {
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
    row: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, minHeight: 61 },
    pressed: { opacity: 0.75 },
    rowIcon: { width: 26, height: 26, alignItems: 'center', justifyContent: 'center' },
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
      backgroundColor: theme.brand,
    },
    signOutText: { color: '#ffffff', fontSize: 11, fontWeight: '700', fontFamily: font.body },
    footer: { marginTop: 20, alignItems: 'center', gap: 3 },
    footerText: { color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
    deviceRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 8 },
    deviceText: { flexShrink: 1, color: theme.ink500, fontSize: 8.5, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
