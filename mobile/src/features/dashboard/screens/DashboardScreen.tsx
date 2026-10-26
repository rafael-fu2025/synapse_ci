import React from 'react';
import { ActivityIndicator, Image, Pressable, StyleSheet, Text, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import {
  Bell,
  Building2,
  ChevronRight,
  ClipboardList,
  ListChecks,
  Stethoscope,
} from 'lucide-react-native';
import { useQuery } from '@tanstack/react-query';
import { Screen } from '../../../components/Screen';
import { SectionHeading } from '../../../components/SectionHeading';
import { StatusPill } from '../../../components/StatusPill';
import { Avatar } from '../../../components/Avatar';
import { queryKeys } from '../../../api/queryKeys';
import { me } from '../../../api/endpoints/me';
import { useAppointments } from '../../appointments/hooks';
import { useNotificationInbox } from '../../notifications/hooks';
import { statusPresentation, unitLabel } from '../../appointments/status';
import { poll } from '../../../api/poll';
import { Theme, font, palette, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate, formatTime } from '../../../lib/format';
import { useAuth } from '../../../store/auth';
import { HomeStackParamList } from '../../../navigation/types';

/** Quick actions cross into sibling stacks; route names are unique across the tree. */
type Nav = NativeStackScreenProps<HomeStackParamList>['navigation'] & {
  navigate: (
    name: 'Booking' | 'QueueBoard' | 'Visits' | 'Queue' | 'Profile' | 'Announcements' | 'Surveys' | 'Notifications',
    params?: object,
  ) => void;
};

export function DashboardScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Nav>();
  const { user } = useAuth();

  const profileQuery = useQuery({
    queryKey: queryKeys.me,
    queryFn: me,
    refetchInterval: poll.dashboardMs,
  });
  const appointmentsQuery = useAppointments({ service: 'all', status: 'active', sort: 'upcoming' });
  const inboxQuery = useNotificationInbox();
  const unreadCount = inboxQuery.data?.data.unread_count ?? 0;
  const nextVisit = appointmentsQuery.data?.data?.[0];
  const staffUnits = profileQuery.data?.entitlements.staff_queue_units ?? [];

  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';

  return (
    <Screen>
      <View style={styles.header}>
        <View style={styles.headerCopy}>
          <Text style={styles.eyebrow}>SYNAPSE · PERSONAL PORTAL</Text>
          <Text style={styles.welcome}>
            {greeting}
            {user?.name ? `, ${user.name.split(' ')[0]}` : ''}
          </Text>
          <Text style={styles.muted}>Your health and guidance, in one place.</Text>
        </View>
        <View style={styles.headerActions}>
          <Pressable
            onPress={() => navigation.navigate('Notifications')}
            accessibilityRole="button"
            accessibilityLabel={`Notifications, ${unreadCount} unread`}
            hitSlop={6}
            style={styles.iconAction}>
            <Bell size={20} color={theme.ink700} />
            {unreadCount > 0 ? <View style={styles.notificationDot} /> : null}
          </Pressable>
          <Avatar initials={user?.avatar_initials ?? '··'} />
        </View>
      </View>

      {appointmentsQuery.isLoading ? (
        <ActivityIndicator color={theme.brand} />
      ) : nextVisit ? (
        <Pressable onPress={() => navigation.navigate('Visits')} style={styles.nextVisit}>
          <LinearGradient colors={[palette.brand900, palette.brand700]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }}>
            <View style={styles.nextVisitInner}>
              <Text style={styles.nextVisitKicker}>NEXT VISIT · {unitLabel(nextVisit.unit).toUpperCase()}</Text>
              <View style={styles.nextVisitRow}>
                <View style={styles.dateTile}>
                  <Text style={styles.dateTileMonth}>{formatDate(nextVisit.starts_at).split(' ')[1]}</Text>
                  <Text style={styles.dateTileDay}>{formatDate(nextVisit.starts_at).split(' ')[2]}</Text>
                </View>
                <View style={styles.nextVisitCopy}>
                  <Text style={styles.nextVisitTitle}>
                    {formatDate(nextVisit.starts_at)} · {formatTime(nextVisit.starts_at)}
                  </Text>
                  <Text style={styles.nextVisitMeta}>
                    {nextVisit.provider_name ?? 'Provider assigned on confirmation'}
                  </Text>
                  <View style={styles.nextVisitPill}>
                    <StatusPill tone={statusPresentation(nextVisit.status).tone}>
                      {statusPresentation(nextVisit.status).label}
                    </StatusPill>
                  </View>
                </View>
              </View>
            </View>
          </LinearGradient>
        </Pressable>
      ) : null}

      <SectionHeading title="Quick actions" />
      <View style={styles.quickGrid}>
        <QuickLink
          icon={<Stethoscope size={17} color={palette.brand700} />}
          iconTone="brand"
          label="Request a visit"
          onPress={() => navigation.navigate('Booking')}
        />
        <QuickLink
          icon={<ListChecks size={17} color={palette.gold600} />}
          iconTone="gold"
          label="My queue ticket"
          onPress={() => navigation.navigate('QueueBoard')}
        />
        <QuickLink
          icon={<Bell size={17} color={palette.blue600} />}
          iconTone="blue"
          label="Announcements"
          onPress={() => navigation.navigate('Announcements')}
        />
        <QuickLink
          icon={<ClipboardList size={17} color={palette.violet600} />}
          iconTone="violet"
          label="Surveys"
          onPress={() => navigation.navigate('Surveys')}
        />
      </View>

      {staffUnits.length > 0 ? (
        <View style={styles.staffBanner}>
          <View style={styles.staffIcon}>
            <Building2 size={19} color={palette.blue600} />
          </View>
          <View style={styles.staffCopy}>
            <Text style={styles.staffTitle}>Staff mode available</Text>
            <Text style={styles.staffBody}>
              You monitor the {staffUnits.map(unitLabel).join(' and ')} queues. Open Staff queue from the Queue tab.
            </Text>
          </View>
        </View>
      ) : null}

      <View style={styles.footer}>
        <Image source={require('../../../assets/brand/synapse-maroon.png')} style={styles.footerLogo} resizeMode="contain" />
        <Text style={styles.footerText}>Foundation University · SYNAPSE</Text>
      </View>
    </Screen>
  );
}

function QuickLink({
  icon,
  iconTone,
  label,
  onPress,
}: {
  icon: React.ReactNode;
  iconTone: 'brand' | 'gold' | 'blue' | 'violet';
  label: string;
  onPress: () => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const toneBackground = {
    brand: theme.brand100,
    gold: palette.gold100,
    blue: palette.blue100,
    violet: palette.violet100,
  }[iconTone];

  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.quickLink, pressed && styles.pressed]}>
      <View style={[styles.quickIcon, { backgroundColor: toneBackground }]}>{icon}</View>
      <Text style={styles.quickLabel}>{label}</Text>
      <ChevronRight size={15} color={theme.ink400} />
    </Pressable>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    header: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginTop: 6, marginBottom: 20 },
    headerCopy: { flex: 1 },
    eyebrow: { color: theme.brand, fontSize: 9.5, fontWeight: '800', letterSpacing: 1.1, fontFamily: font.body },
    welcome: { marginTop: 4, ...type.hero, color: theme.ink900 },
    muted: { marginTop: 3, color: theme.ink500, fontSize: 12, fontFamily: font.body },
    headerActions: { flexDirection: 'row', gap: 8 },
    iconAction: {
      width: 38,
      height: 38,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 12,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    notificationDot: {
      position: 'absolute',
      top: 7,
      right: 7,
      width: 7,
      height: 7,
      borderRadius: 999,
      backgroundColor: '#d64c45',
      borderWidth: 2,
      borderColor: theme.surface,
    },
    nextVisit: { borderRadius: 15, overflow: 'hidden', elevation: 6, shadowColor: '#800000', shadowOpacity: 0.2, shadowRadius: 25, shadowOffset: { width: 0, height: 12 } },
    nextVisitInner: { padding: 14 },
    nextVisitKicker: { color: '#f2cece', fontSize: 9, fontWeight: '800', letterSpacing: 1, fontFamily: font.body },
    nextVisitRow: { flexDirection: 'row', gap: 13, alignItems: 'center', marginTop: 11 },
    dateTile: { width: 51, height: 59, borderRadius: 12, backgroundColor: 'rgba(255,255,255,0.12)', alignItems: 'center', justifyContent: 'center' },
    dateTileMonth: { color: '#d2e8e4', fontSize: 8, fontWeight: '700', fontFamily: font.body, textTransform: 'uppercase' },
    dateTileDay: { fontSize: 23, fontWeight: '800', color: '#ffffff', fontFamily: font.display },
    nextVisitCopy: { flex: 1 },
    nextVisitTitle: { fontSize: 14, fontWeight: '700', color: '#ffffff', fontFamily: font.display },
    nextVisitMeta: { marginTop: 4, color: '#d5e9e6', fontSize: 10.5, fontFamily: font.body },
    nextVisitPill: { marginTop: 8 },
    quickGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 9 },
    quickLink: {
      minWidth: '47.5%',
      flexGrow: 1,
      flexDirection: 'row',
      alignItems: 'center',
      gap: 8,
      padding: 10,
      borderRadius: 13,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    pressed: { opacity: 0.75 },
    quickIcon: { width: 35, height: 35, alignItems: 'center', justifyContent: 'center', borderRadius: 10 },
    quickLabel: { flex: 1, fontSize: 11.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    staffBanner: {
      flexDirection: 'row',
      alignItems: 'flex-start',
      gap: 11,
      marginTop: 22,
      padding: 14,
      borderRadius: 14,
      backgroundColor: palette.blue100,
    },
    staffIcon: {
      width: 40,
      height: 40,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 11,
      backgroundColor: '#ffffff',
    },
    staffCopy: { flex: 1 },
    staffTitle: { fontSize: 11, fontWeight: '700', fontFamily: font.body, color: '#344054' },
    staffBody: { marginTop: 3, color: theme.ink500, fontSize: 9, lineHeight: 13, fontFamily: font.body },
    footer: { alignItems: 'center', gap: 8, marginTop: 28 },
    footerLogo: { width: 120, height: 40 },
    footerText: { color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
  });
}
