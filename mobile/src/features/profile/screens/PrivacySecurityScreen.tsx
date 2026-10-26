import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Database, Lock, Smartphone } from 'lucide-react-native';
import LinearGradient from 'react-native-linear-gradient';
import { Action } from '../../../components/Action';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { palette, Theme, font } from '../../../theme/tokens';
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
        <Lock size={22} color="#ffffff" />
        <View style={styles.heroCopy}>
          <Text style={styles.heroTitle}>Your health data stays yours</Text>
          <Text style={styles.heroBody}>
            Records are encrypted in transit, access is audited per request, and only authorized staff can open them.
          </Text>
        </View>
      </LinearGradient>

      <View style={styles.list}>
        <Fact icon={<Database size={16} color={theme.brand} />} title="Identified, never public" body="Survey answers and visit records are linked to your account and visible only to the department that owns them." />
        <Fact icon={<Lock size={16} color={theme.brand} />} title="Encrypted transport" body="All traffic travels over TLS. Your session token lives in the device secure storage — never in plain files." />
        <Fact icon={<Smartphone size={16} color={theme.brand} />} title="This device" body={`Signed in as ${user?.identifier ?? 'your account'}. Signing out revokes this device's session immediately.`} />
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

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    hero: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 15, borderRadius: 15 },
    heroCopy: { flex: 1 },
    heroTitle: { color: '#ffffff', fontSize: 11.5, fontWeight: '700', fontFamily: font.body },
    heroBody: { marginTop: 3, color: '#efcccc', fontSize: 9, lineHeight: 13, fontFamily: font.body },
    list: { marginTop: 13, marginBottom: 18 },
    fact: { flexDirection: 'row', gap: 10, paddingVertical: 11, borderBottomWidth: 1, borderBottomColor: theme.line },
    factIcon: { width: 35, height: 35, alignItems: 'center', justifyContent: 'center', borderRadius: 10, backgroundColor: theme.brand50 },
    factCopy: { flex: 1 },
    factTitle: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    factBody: { marginTop: 3, color: theme.ink500, fontSize: 9, lineHeight: 13, fontFamily: font.body },
    actions: { marginTop: 'auto', paddingTop: 24 },
  });
}
