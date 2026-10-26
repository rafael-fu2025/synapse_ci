import React from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Building2, Camera, ChevronRight, ClipboardList, HeartPulse, Settings2 } from 'lucide-react-native';
import { Screen } from '../../../components/Screen';
import { Avatar } from '../../../components/Avatar';
import { queryKeys } from '../../../api/queryKeys';
import { me } from '../../../api/endpoints/me';
import { useQuery } from '@tanstack/react-query';
import { Theme, font } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { useAuth } from '../../../store/auth';
import { unitLabel } from '../../appointments/status';
import { ProfileStackParamList } from '../../../navigation/types';

type Nav = NativeStackScreenProps<ProfileStackParamList>['navigation'];

export function ProfileHomeScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Nav>();
  const { user, signOut } = useAuth();
  const profileQuery = useQuery({ queryKey: queryKeys.me, queryFn: me });
  const staffUnits = profileQuery.data?.entitlements.staff_queue_units ?? [];

  return (
    <Screen>
      <View style={styles.hero}>
        <Avatar initials={user?.avatar_initials ?? '··'} size="large" />
        <Text style={styles.heroName}>{user?.name ?? '—'}</Text>
        <Text style={styles.heroMeta}>
          {user?.identifier ?? ''} · {user?.role === 'student' ? 'Student' : 'Employee'}
        </Text>
      </View>

      <Text style={styles.sectionLabel}>MY ACCOUNT</Text>
      <MenuRow icon={<HeartPulse size={17} color={theme.brand} />} title="Health profile" caption="Blood type, allergies, emergency contact" onPress={() => navigation.navigate('HealthProfile')} />
      <MenuRow icon={<Camera size={17} color={theme.brand} />} title="Profile photo" caption="Choose how teammates see you" onPress={() => navigation.navigate('ProfilePhoto')} />
      <MenuRow icon={<ClipboardList size={17} color={theme.brand} />} title="My records" caption="Visit history and outcomes" onPress={() => navigation.navigate('Records')} />
      <MenuRow icon={<Settings2 size={17} color={theme.brand} />} title="Settings" caption="Appearance, privacy, sign-out" onPress={() => navigation.navigate('Settings')} />

      {staffUnits.length > 0 ? (
        <>
          <Text style={styles.sectionLabel}>STAFF</Text>
          <MenuRow
            icon={<Building2 size={17} color={theme.brand} />}
            title="Staff queue monitor"
            caption={`Read-only ${staffUnits.map(unitLabel).join(' / ')} board`}
            onPress={() => navigation.navigate('StaffQueue')}
          />
        </>
      ) : null}

      {profileQuery.isLoading ? <ActivityIndicator color={theme.brand} /> : null}

      <Pressable style={styles.signOut} onPress={() => {
        signOut().catch(() => undefined);
      }}>
        <Text style={styles.signOutText}>Sign out</Text>
      </Pressable>
      <Text style={styles.syncNote}>Profile details sync from the university MIS directory.</Text>
    </Screen>
  );
}

function MenuRow({
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
    <Pressable onPress={onPress} style={({ pressed }) => [styles.menuRow, pressed && styles.pressed]}>
      <View style={styles.menuIcon}>{icon}</View>
      <View style={styles.menuCopy}>
        <Text style={styles.menuTitle}>{title}</Text>
        <Text style={styles.menuCaption}>{caption}</Text>
      </View>
      <ChevronRight size={16} color={theme.ink400} />
    </Pressable>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    hero: { alignItems: 'center', paddingTop: 3, paddingBottom: 20 },
    heroName: { marginTop: 10, fontSize: 18, fontWeight: '800', fontFamily: font.display, color: theme.ink900 },
    heroMeta: { marginTop: 3, color: theme.ink500, fontSize: 10, fontFamily: font.body },
    sectionLabel: { marginHorizontal: 2, marginTop: 15, marginBottom: 7, color: theme.ink400, fontSize: 9.5, fontWeight: '800', letterSpacing: 1.1, fontFamily: font.body },
    menuRow: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      marginBottom: 7,
      padding: 11,
      paddingHorizontal: 12,
      borderRadius: 13,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    pressed: { opacity: 0.75 },
    menuIcon: {
      width: 37,
      height: 37,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 10,
      backgroundColor: theme.brand50,
    },
    menuCopy: { flex: 1 },
    menuTitle: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    menuCaption: { marginTop: 2, color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
    signOut: {
      alignItems: 'center',
      padding: 12,
      marginTop: 18,
      borderRadius: 12,
      backgroundColor: 'rgba(163,59,59,0.12)',
    },
    signOutText: { color: '#a33b3b', fontSize: 11, fontWeight: '700', fontFamily: font.body },
    syncNote: { marginTop: 14, textAlign: 'center', color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
  });
}
