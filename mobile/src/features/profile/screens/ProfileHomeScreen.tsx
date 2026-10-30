import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';

import {
  Building2,
  CalendarDays,
  Camera,
  ChevronRight,
  CircleCheck,
  FileHeart,
  HeartPulse,
  RefreshCw,
  Settings,
} from 'lucide-react-native';
import { useQuery } from '@tanstack/react-query';
import { Screen } from '../../../components/Screen';
import { Avatar } from '../../../components/Avatar';
import { PageHeader } from '../../../components/PageHeader';
import { IconAction } from '../../../components/IconAction';
import { ProfileCard } from '../../../components/ProfileCard';
import { PlaceholderCard } from '../../../components/PlaceholderCard';
import { StatusPill } from '../../../components/StatusPill';
import { queryKeys } from '../../../api/queryKeys';
import { me } from '../../../api/endpoints/me';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { useAuth } from '../../../store/auth';
import { unitLabel } from '../../appointments/status';
import { useHealthSummary } from '../hooks';
import { useProfilePhoto } from '../useProfilePhoto';
import { profileCardFromSummary } from '../profileCardModel';
import type { ProfileNavigation } from '../../../navigation/types';

export function ProfileHomeScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<ProfileNavigation>();
  const { user } = useAuth();
  const photoUri = useProfilePhoto();
  const profileQuery = useQuery({ queryKey: queryKeys.me, queryFn: me });
  const summaryQuery = useHealthSummary();
  const staffUnits = profileQuery.data?.entitlements.staff_queue_units ?? [];
  const isEmployee = user?.role !== 'student';

  // The card is always present: it falls back to the signed-in identity until MIS-backed details load.
  const card = summaryQuery.data
    ? profileCardFromSummary(summaryQuery.data.data)
    : {
        caption: isEmployee ? 'Employee Profile' : 'Student Profile',
        name: user?.name ?? 'University Member',
        idNumber: user?.identifier ?? '—',
        rows: [{ label: 'Email', value: user?.email ?? 'N/A' }],
      };

  return (
    <Screen>
      <PageHeader
        title="Profile"
        action={
          <IconAction
            label="Settings"
            onPress={() => navigation.navigate('Settings')}
            icon={<Settings size={20} color={theme.ink700} />}
          />
        }
      />

      <View style={styles.hero}>
        <Pressable
          onPress={() => navigation.navigate('ProfilePhoto')}
          accessibilityRole="button"
          accessibilityLabel="Change profile photo">
          <Avatar initials={user?.avatar_initials ?? '··'} size="large" photoUri={photoUri} />
          <View style={styles.cameraBadge}>
            <Camera size={13} color="#ffffff" />
          </View>
        </Pressable>
        <Text style={styles.heroName}>{user?.name ?? '—'}</Text>
        <Text style={styles.heroMeta}>
          {user?.identifier ?? ''} · {isEmployee ? 'Employee' : 'Student'}
        </Text>
        <StatusPill tone="success">
          <CircleCheck size={12} color="#176259" /> Active account
        </StatusPill>
      </View>

      <ProfileCard {...card} />
      {summaryQuery.isError && !summaryQuery.data ? (
        <View style={styles.profileNotice}>
          <PlaceholderCard
            icon={<RefreshCw size={20} color={theme.brandIcon} />}
            title="Profile details unavailable"
            body="Showing your sign-in identity. Tap to load the full university profile."
            onPress={() => summaryQuery.refetch().catch(() => undefined)}
            actionLabel="Retry profile details"
          />
        </View>
      ) : null}

      <Text style={styles.sectionLabel}>HEALTH & WELLNESS</Text>
      <MenuRow
        icon={<HeartPulse size={19} color={theme.brandIcon} />}
        title="Health profile & medical flags"
        caption="Blood type, allergies, and emergency details"
        onPress={() => navigation.navigate('HealthProfile')}
      />
      <MenuRow
        icon={<FileHeart size={19} color={theme.brandIcon} />}
        title="Personal records"
        caption="View your permitted health summaries"
        onPress={() => navigation.navigate('Records')}
      />
      <MenuRow
        icon={<CalendarDays size={19} color={theme.brandIcon} />}
        title="Appointment history"
        caption="Upcoming and past visits"
        onPress={() => navigation.navigate('Visits', { screen: 'Appointments' })}
      />

      {staffUnits.length > 0 ? (
        <>
          <Text style={styles.sectionLabel}>STAFF</Text>
          <MenuRow
            icon={<Building2 size={19} color={theme.brandIcon} />}
            title="Staff queue monitor"
            caption={`Read-only ${staffUnits.map(unitLabel).join(' / ')} board`}
            onPress={() => navigation.navigate('StaffQueue')}
          />
        </>
      ) : null}

      <View style={styles.syncNote}>
        <RefreshCw size={13} color={theme.ink400} />
        <Text style={styles.syncText}>Profile details sync from your MIS account.</Text>
      </View>
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
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      style={({ pressed }) => [styles.menuRow, pressed && styles.pressed]}>
      <View style={styles.menuIcon}>{icon}</View>
      <View style={styles.menuCopy}>
        <Text style={styles.menuTitle}>{title}</Text>
        <Text style={styles.menuCaption}>{caption}</Text>
      </View>
      <ChevronRight size={17} color={theme.ink400} />
    </Pressable>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    profileNotice: { marginTop: 10 },
    hero: { alignItems: 'center', paddingTop: 3, paddingBottom: 20 },
    cameraBadge: {
      position: 'absolute',
      right: 0,
      bottom: 2,
      width: 26,
      height: 26,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 13,
      borderWidth: 2,
      borderColor: theme.surface,
      backgroundColor: theme.brand,
    },
    heroName: { marginTop: 10, ...type.cardTitle, fontSize: 18, fontWeight: '800', color: theme.ink900 },
    heroMeta: { marginTop: 3, marginBottom: 8, color: theme.ink500, fontSize: 10, fontFamily: font.body },
    sectionLabel: { marginHorizontal: 2, marginTop: 18, marginBottom: 7, color: theme.ink400, fontSize: 9.5, fontWeight: '800', letterSpacing: 1.1, fontFamily: font.body },
    menuRow: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      marginBottom: 7,
      padding: 11,
      paddingHorizontal: 12,
      minHeight: 59,
      borderRadius: 13,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    pressed: { opacity: 0.75 },
    menuIcon: {
      width: 26,
      height: 26,
      alignItems: 'center',
      justifyContent: 'center',
    },
    menuCopy: { flex: 1 },
    menuTitle: { fontSize: 11.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    menuCaption: { marginTop: 2, color: theme.ink400, fontSize: 9.5, fontFamily: font.body },
    syncNote: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, marginTop: 14 },
    syncText: { color: theme.ink400, fontSize: 9.5, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
