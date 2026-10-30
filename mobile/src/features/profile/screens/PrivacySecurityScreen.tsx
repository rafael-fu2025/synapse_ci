import React from 'react';
import { Platform, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Database, KeyRound, Lock, ShieldCheck, Smartphone } from 'lucide-react-native';
import LinearGradient from 'react-native-linear-gradient';
import { Action } from '../../../components/Action';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { palette, Theme, font, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { useAuth } from '../../../store/auth';
import { ProfileStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<ProfileStackParamList, 'PrivacySecurity'>;

export function PrivacySecurityScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const { user, signOut } = useAuth();

  return (
    <Screen>
      <PageHeader title="Privacy & security" onBack={navigation.goBack} />

      <LinearGradient colors={[palette.brand900, palette.brand600]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.hero}>
        <ShieldCheck size={34} color="#ffffff" />
        <View style={styles.heroCopy}>
          <Text style={styles.heroTitle}>Your information is protected</Text>
          <Text style={styles.heroBody}>
            Your account and session help protect access to your university health services.
          </Text>
        </View>
      </LinearGradient>

      <View style={styles.list}>
        <Fact icon={<KeyRound size={16} color={theme.brandIcon} />} title="Secure sign-in" body="Your MIS password is used to authenticate and is not saved by this app." />
        <Fact icon={<Lock size={16} color={theme.brandIcon} />} title="Protected session" body="Your refresh token is kept in your device's Keychain or Keystore. Your access token is held in memory." />
        <Fact icon={<Database size={16} color={theme.brandIcon} />} title="Private records" body="The appointment timeline does not display internal clinical or counseling notes." />
      </View>

      <Text style={styles.sectionTitle}>On this device</Text>
      <View style={styles.deviceSession}>
        <Smartphone size={20} color={theme.brandIcon} />
        <View style={styles.factCopy}>
          <Text style={styles.factTitle}>Current session</Text>
          <Text style={styles.factBody}>{`${Platform.OS === 'ios' ? 'iOS' : Platform.OS === 'android' ? 'Android' : Platform.OS} · ${user?.identifier ?? 'your account'}`}</Text>
          <Text style={styles.factBody}>Signing out clears this device's credentials. Server revocation is attempted; if offline, the server session may remain active until it expires.</Text>
        </View>
      </View>

      <View style={styles.actions}>
        <Action variant="danger" full onPress={() => {
          signOut().catch(() => undefined);
        }}>
          Sign out of this device
        </Action>
      </View>
    </Screen>
  );
}

function Fact({ icon, title, body }: { icon: React.ReactNode; title: string; body: string }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.fact}>
      <View style={styles.factIcon}>{icon}</View>
      <View style={styles.factCopy}>
        <Text style={styles.factTitle}>{title}</Text>
        <Text style={styles.factBody}>{body}</Text>
      </View>
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    hero: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 15, borderRadius: 15 },
    heroCopy: { flex: 1 },
    heroTitle: { color: '#ffffff', fontSize: 11.5, fontWeight: '700', fontFamily: font.body },
    heroBody: { marginTop: 3, color: '#efcccc', fontSize: 9, lineHeight: 13, fontFamily: font.body },
    list: { marginTop: 13, marginBottom: 18 },
    fact: { flexDirection: 'row', gap: 10, paddingVertical: 11, borderBottomWidth: 1, borderBottomColor: theme.line },
    factIcon: { width: 26, height: 26, alignItems: 'center', justifyContent: 'center' },
    factCopy: { flex: 1 },
    factTitle: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    factBody: { marginTop: 3, color: theme.ink500, fontSize: 9, lineHeight: 13, fontFamily: font.body },
    sectionTitle: { color: theme.ink900, fontSize: 12, fontWeight: '700', fontFamily: font.display },
    deviceSession: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 9, padding: 12, borderRadius: 14, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    actions: { marginTop: 'auto', paddingTop: 24 },
  });
}

const makeStyles = memoizeStyles(createStyles);
