import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { useNavigation } from '@react-navigation/native';
import {
  Bell,
  Building2,
  CalendarPlus,
  ChevronRight,
  ClipboardList,
  Clock3,
  ListChecks,
  Megaphone,
  Stethoscope,
} from 'lucide-react-native';
import { useQuery } from '@tanstack/react-query';
import { Screen } from '../../../components/Screen';
import { SectionHeading } from '../../../components/SectionHeading';
import { StatusPill } from '../../../components/StatusPill';
import { Avatar } from '../../../components/Avatar';
import { IconAction } from '../../../components/IconAction';
import { PlaceholderCard } from '../../../components/PlaceholderCard';
import { queryKeys } from '../../../api/queryKeys';
import { me } from '../../../api/endpoints/me';
import { useAppointments } from '../../appointments/hooks';
import { useNotificationInbox } from '../../notifications/hooks';
import { useAnnouncements } from '../../announcements/hooks';
import { useSurveys } from '../../surveys/hooks';
import { useQueueSnapshot } from '../../queue/hooks';
import { QueueTicketCard, QueueTicketPlaceholder } from '../../queue/components/QueueTicketCard';
import { statusPresentation, unitLabel, UNIT_PRESENTATION } from '../../appointments/status';
import { poll } from '../../../api/poll';
import { useFocusPoll } from '../../../lib/useFocusPoll';
import { Theme, font, memoizeStyles, palette, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import {
  formatDate,
  formatDateTile,
  formatEyebrowDate,
  formatRelative,
  formatTime,
  manilaHour,
} from '../../../lib/format';
import { useAuth } from '../../../store/auth';
import { useProfilePhoto } from '../../profile/useProfilePhoto';

import type { HomeNavigation } from '../../../navigation/types';

function greetingFor(hour: number): string {
  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}

export function DashboardScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<HomeNavigation>();
  const { user } = useAuth();
  const photoUri = useProfilePhoto();

  const profileRefetch = useFocusPoll(poll.dashboardMs);
  const profileQuery = useQuery({ queryKey: queryKeys.me, queryFn: me, refetchInterval: profileRefetch });
  const appointmentsQuery = useAppointments({ service: 'all', status: 'active', sort: 'upcoming' });
  const inboxQuery = useNotificationInbox();
  const queueQuery = useQueueSnapshot();
  const announcementsQuery = useAnnouncements({ unit: 'all', status: 'all', page: 1 });
  const surveysQuery = useSurveys({ status: 'available' });

  const unreadCount = inboxQuery.data?.data.unread_count ?? 0;
  const nextVisit = appointmentsQuery.data?.data?.[0];
  const staffUnits = profileQuery.data?.entitlements.staff_queue_units ?? [];
  const tickets = queueQuery.data?.data.tickets ?? [];
  const latestAnnouncement = announcementsQuery.data?.data?.[0];
  const openSurvey = surveysQuery.data?.data?.find((survey) => !survey.submitted && !survey.closed);
  const dateTile = nextVisit ? formatDateTile(nextVisit.starts_at) : null;

  const firstName = user?.name ? user.name.trim().split(/\s+/)[0] : '';

  return (
    <Screen>
      <View style={styles.header}>
        <View style={styles.headerCopy}>
          <Text style={styles.eyebrow}>{formatEyebrowDate()}</Text>
          <Text style={styles.welcome}>
            {greetingFor(manilaHour())}
            {firstName ? `, ${firstName}` : ''}
          </Text>
          <Text style={styles.muted}>How are you feeling today?</Text>
        </View>
        <View style={styles.headerActions}>
          <IconAction
            label={`Notifications, ${unreadCount} unread`}
            badge={unreadCount > 0}
            onPress={() => navigation.navigate('Notifications')}
            icon={<Bell size={20} color={theme.ink700} />}
          />
          <Pressable
            onPress={() => navigation.navigate('Profile', { screen: 'ProfileHome' })}
            accessibilityRole="button"
            accessibilityLabel="Open profile"
            hitSlop={6}>
            <Avatar initials={user?.avatar_initials ?? '··'} photoUri={photoUri} />
          </Pressable>
        </View>
      </View>

      {nextVisit ? (
        <Pressable
          onPress={() => navigation.navigate('Visits', { screen: 'AppointmentDetail', params: { id: nextVisit.id }, initial: false })}
          accessibilityRole="button"
          accessibilityLabel="View appointment"
          style={styles.nextVisit}>
          <LinearGradient colors={[palette.brand900, palette.brand700]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }}>
            <View style={styles.kickerRow}>
              <Text style={styles.nextVisitKicker}>NEXT APPOINTMENT</Text>
              <StatusPill tone={statusPresentation(nextVisit.status).tone}>
                {statusPresentation(nextVisit.status).label}
              </StatusPill>
            </View>
            <View style={styles.nextVisitRow}>
              {dateTile ? (
                <View style={styles.dateTile}>
                  <Text style={styles.dateTileMonth}>{dateTile.month}</Text>
                  <Text style={styles.dateTileDay}>{dateTile.day}</Text>
                </View>
              ) : null}
              <View style={styles.nextVisitCopy}>
                <Text style={styles.nextVisitTitle}>
                  {nextVisit.unit === 'clinic' ? 'Clinic consultation' : 'Guidance session'}
                </Text>
                <View style={styles.metaLine}>
                  <Clock3 size={14} color="#d5e9e6" />
                  <Text style={styles.nextVisitMeta}>
                    {formatDate(nextVisit.starts_at)} · {formatTime(nextVisit.starts_at)} ·{' '}
                    {UNIT_PRESENTATION[nextVisit.unit]?.label ?? unitLabel(nextVisit.unit)}
                  </Text>
                </View>
                <View style={styles.metaLine}>
                  <Stethoscope size={14} color="#d5e9e6" />
                  <Text style={styles.nextVisitMeta}>
                    {nextVisit.provider_name ?? 'Provider assigned on confirmation'}
                  </Text>
                </View>
              </View>
            </View>
            <View style={styles.cardLink}>
              <Text style={styles.cardLinkText}>View appointment</Text>
              <ChevronRight size={15} color="#f2fbf9" />
            </View>
          </LinearGradient>
        </Pressable>
      ) : (
        <PlaceholderCard
          icon={<CalendarPlus size={22} color={theme.brandIcon} />}
          title={appointmentsQuery.isLoading ? 'Loading appointments…' : appointmentsQuery.isError ? 'Appointments unavailable' : 'No upcoming appointment'}
          body={appointmentsQuery.isError
            ? 'We couldn’t load appointments. Open Visits to retry.'
            : 'Request a clinic or guidance visit and it will show up here.'}
          loading={appointmentsQuery.isLoading}
          onPress={() => navigation.navigate('Visits')}
          actionLabel="Open appointments"
        />
      )}

      <SectionHeading title="My queue ticket" caption="Live waiting room status" />
      {tickets.length > 0 ? (
        tickets.map((ticket) => <QueueTicketCard key={`${ticket.unit}-${ticket.ticket_code}`} ticket={ticket} />)
      ) : (
        <QueueTicketPlaceholder loading={queueQuery.isLoading} error={queueQuery.isError} onPress={() => navigation.navigate('Queue')} />
      )}

      <SectionHeading title="Quick access" caption="What would you like to do?" />
      <View style={styles.quickGrid}>
        <QuickLink
          icon={<Stethoscope size={19} color={theme.brandIcon} />}

          label="Book a visit"
          onPress={() => navigation.navigate('Visits', { screen: 'Booking', initial: false })}
        />
        <QuickLink
          icon={<ListChecks size={19} color={palette.gold600} />}

          label="Queue status"
          onPress={() => navigation.navigate('Queue')}
        />
        <QuickLink
          icon={<Megaphone size={19} color={palette.blue600} />}

          label="Announcements"
          onPress={() => navigation.navigate('Announcements')}
        />
        <QuickLink
          icon={<ClipboardList size={19} color={palette.violet600} />}

          label="Surveys"
          onPress={() => navigation.navigate('Surveys')}
        />
      </View>

      <SectionHeading
        title="Latest updates"
        trailing={
          <Pressable
            onPress={() => navigation.navigate('Announcements')}
            accessibilityRole="link"
            hitSlop={8}>
            <Text style={styles.textLink}>See all</Text>
          </Pressable>
        }
      />
      {latestAnnouncement ? (
        <Pressable
          onPress={() => navigation.navigate('AnnouncementDetail', { id: latestAnnouncement.id })}
          accessibilityRole="button"
          style={({ pressed }) => [styles.newsCard, pressed && styles.pressed]}>
          <View style={styles.newsIcon}>
            <Megaphone size={21} color={palette.gold600} />
          </View>
          <View style={styles.newsCopy}>
            <StatusPill tone="info">
              {latestAnnouncement.unit === 'guidance' ? 'Guidance update' : 'Health advisory'}
            </StatusPill>
            <Text style={styles.newsTitle} numberOfLines={2}>
              {latestAnnouncement.title}
            </Text>
            <Text style={styles.newsBody} numberOfLines={2}>
              {latestAnnouncement.excerpt}
            </Text>
            <Text style={styles.newsTime}>{formatRelative(latestAnnouncement.published_at)}</Text>
          </View>
        </Pressable>
      ) : (
        <PlaceholderCard
          icon={<Megaphone size={22} color={palette.gold600} />}

          title={announcementsQuery.isLoading ? 'Loading announcements…' : announcementsQuery.isError ? 'Announcements unavailable' : 'No announcements yet'}
          body={announcementsQuery.isError
            ? 'We couldn’t load updates. Open Announcements to retry.'
            : 'Campus health and guidance updates will appear here when they are published.'}
          loading={announcementsQuery.isLoading}
          onPress={() => navigation.navigate('Announcements')}
          actionLabel="Open announcements"
        />
      )}

      <View style={styles.surveySlot}>
        {openSurvey ? (
          <Pressable
            onPress={() => navigation.navigate('SurveyForm', { id: openSurvey.id })}
            accessibilityRole="button"
            style={({ pressed }) => [styles.newsCard, pressed && styles.pressed]}>
            <View style={styles.newsIcon}>
              <ClipboardList size={21} color={palette.violet600} />
            </View>
            <View style={styles.newsCopy}>
              <StatusPill tone="warning">
                {openSurvey.ends_at ? `Due ${formatDate(openSurvey.ends_at)}` : 'Survey open'}
              </StatusPill>
              <Text style={styles.newsTitle} numberOfLines={2}>
                {openSurvey.title}
              </Text>
              {openSurvey.description ? (
                <Text style={styles.newsBody} numberOfLines={2}>
                  {openSurvey.description}
                </Text>
              ) : null}
              <Text style={styles.newsAction}>Start survey</Text>
            </View>
          </Pressable>
        ) : (
          <PlaceholderCard
            icon={<ClipboardList size={22} color={palette.violet600} />}

            title={surveysQuery.isLoading ? 'Loading surveys…' : surveysQuery.isError ? 'Surveys unavailable' : 'No surveys waiting'}
            body={surveysQuery.isError
              ? 'We couldn’t load surveys. Open Surveys to retry.'
              : 'You’re all caught up. New guidance surveys will appear here.'}
            loading={surveysQuery.isLoading}
            onPress={() => navigation.navigate('Surveys')}
            actionLabel="Open surveys"
          />
        )}
      </View>

      {staffUnits.length > 0 ? (
        <View style={styles.staffBanner}>
          <View style={styles.staffIcon}>
            <Building2 size={19} color={palette.blue600} />
          </View>
          <View style={styles.staffCopy}>
            <Text style={styles.staffTitle}>Staff mode available</Text>
            <Text style={styles.staffBody}>
              You monitor the {staffUnits.map(unitLabel).join(' and ')} queues. Open Staff queue from the Profile tab.
            </Text>
          </View>
        </View>
      ) : null}
    </Screen>
  );
}

function QuickLink({
  icon,
  label,
  onPress,
}: {
  icon: React.ReactNode;

  label: string;
  onPress: () => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      style={({ pressed }) => [styles.quickLink, pressed && styles.pressed]}>
      <View style={styles.quickIcon}>{icon}</View>
      <Text style={styles.quickLabel}>{label}</Text>
      <ChevronRight size={15} color={theme.ink400} />
    </Pressable>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    header: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginTop: 6, marginBottom: 20 },
    headerCopy: { flex: 1 },
    eyebrow: { color: theme.brandText, fontSize: 9.5, fontWeight: '800', letterSpacing: 1.1, fontFamily: font.body },
    welcome: { marginTop: 4, ...type.hero, color: theme.ink900 },
    muted: { marginTop: 3, color: theme.ink500, fontSize: 12, fontFamily: font.body },
    headerActions: { flexDirection: 'row', alignItems: 'center', gap: 8 },
    nextVisit: { borderRadius: 15, overflow: 'hidden', elevation: 6, shadowColor: '#800000', shadowOpacity: 0.2, shadowRadius: 25, shadowOffset: { width: 0, height: 12 } },
    kickerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 14, paddingTop: 12 },
    nextVisitKicker: { color: '#f2cece', fontSize: 9, fontWeight: '800', letterSpacing: 1, fontFamily: font.body },
    nextVisitRow: { flexDirection: 'row', gap: 13, alignItems: 'center', paddingHorizontal: 14, paddingTop: 11, paddingBottom: 13 },
    dateTile: { width: 51, height: 59, borderRadius: 12, backgroundColor: 'rgba(255,255,255,0.12)', alignItems: 'center', justifyContent: 'center' },
    dateTileMonth: { color: '#d2e8e4', fontSize: 8, fontWeight: '700', fontFamily: font.body },
    dateTileDay: { fontSize: 23, fontWeight: '800', color: '#ffffff', fontFamily: font.display },
    nextVisitCopy: { flex: 1, gap: 3 },
    nextVisitTitle: { ...type.cardTitle, color: '#ffffff' },
    metaLine: { flexDirection: 'row', alignItems: 'center', gap: 5 },
    nextVisitMeta: { flex: 1, color: '#d5e9e6', fontSize: 10.5, fontFamily: font.body },
    cardLink: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'center',
      gap: 5,
      padding: 9,
      borderTopWidth: 1,
      borderTopColor: 'rgba(255,255,255,0.12)',
    },
    cardLinkText: { color: '#f2fbf9', fontSize: 10.5, fontWeight: '700', fontFamily: font.body },
    quickGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 9 },
    quickLink: {
      minWidth: '47.5%',
      minHeight: 55,
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
    quickIcon: { width: 24, height: 24, alignItems: 'center', justifyContent: 'center' },
    quickLabel: { flex: 1, fontSize: 11.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    textLink: { color: theme.brandText, fontSize: 10.5, fontWeight: '700', fontFamily: font.body },
    newsCard: {
      flexDirection: 'row',
      gap: 11,
      padding: 14,
      borderRadius: 15,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    surveySlot: { marginTop: 10 },
    newsIcon: { width: 24, height: 28, alignItems: 'center', justifyContent: 'center' },
    newsCopy: { flex: 1, minWidth: 0 },
    newsTitle: { ...type.cardTitle, marginTop: 7, fontSize: 13, color: theme.ink900 },
    newsBody: { marginTop: 3, color: theme.ink500, fontSize: 11, lineHeight: 16, fontFamily: font.body },
    newsTime: { marginTop: 6, color: theme.ink400, fontSize: 9, fontFamily: font.body },
    newsAction: { marginTop: 8, color: theme.brandText, fontSize: 10.5, fontWeight: '700', fontFamily: font.body },
    staffBanner: {
      flexDirection: 'row',
      alignItems: 'flex-start',
      gap: 11,
      marginTop: 22,
      padding: 14,
      borderRadius: 14,
      backgroundColor: theme.mode === 'dark' ? theme.surfaceWarm : palette.blue100,
    },
    staffIcon: { width: 24, height: 24, alignItems: 'center', justifyContent: 'center' },
    staffCopy: { flex: 1 },
    staffTitle: { fontSize: 11, fontWeight: '700', fontFamily: font.body, color: theme.ink700 },
    staffBody: { marginTop: 3, color: theme.ink500, fontSize: 9.5, lineHeight: 14, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
