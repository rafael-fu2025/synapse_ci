import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useNetInfo } from '@react-native-community/netinfo';
import { useIsFocused, useNavigation, useRoute } from '@react-navigation/native';
import type { RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { CalendarX2, Edit3, MapPin, RefreshCw, Users } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { EmptyState } from '../../../components/EmptyState';
import { IconAction } from '../../../components/IconAction';
import { useOverlayActivity } from '../../../components/OverlayProvider';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { StatusPill } from '../../../components/StatusPill';
import { useForegroundOnline } from '../../../lib/useActiveScreen';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, memoizeStyles, type } from '../../../theme/tokens';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { WorkspaceHeaderActions } from '../../workspace/WorkspaceHeaderActions';
import { useWorkspaceDraft } from '../../workspace/useWorkspaceDraft';
import { teamCalendarErrorKind, teamCalendarErrorMessage } from '../errors';
import {
  useCalendarManualRefresh,
  useCancelTeamCalendarEvent,
  useTeamCalendarAccess,
  useTeamCalendarEvent,
} from '../hooks';
import { formatManilaDayLabel, formatManilaTimeFromInstant, instantToManilaDate } from '../manila';
import type { TeamCalendarStackParamList } from '../navigation';

type DetailRoute = RouteProp<TeamCalendarStackParamList, 'TeamCalendarEventDetail'>;
type Navigation = NativeStackNavigationProp<TeamCalendarStackParamList>;

export function TeamCalendarEventDetailScreen() {
  const route = useRoute<DetailRoute>();
  const { activeUnit } = useWorkspace();
  return <EventDetail key={`${activeUnit?.unit ?? 'none'}:${route.params.id}`} />;
}

function EventDetail() {
  const route = useRoute<DetailRoute>();
  const navigation = useNavigation<Navigation>();
  const id = route.params.id;
  const { activeUnit, openUnitPicker } = useWorkspace();
  const network = useNetInfo();
  const focused = useIsFocused();
  const foregroundOnline = useForegroundOnline();
  const overlayActive = useOverlayActivity();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const unit = activeUnit?.features.team_calendar === true ? activeUnit.unit : null;
  const { accessDenied, refreshingAccess, recoverAccess, retryAccess } = useTeamCalendarAccess(unit);
  const cancelMutation = useCancelTeamCalendarEvent(unit, id);
  const [cancelling, setCancelling] = useState(false);
  const [cancelVisible, setCancelVisible] = useState(false);
  const [cancelError, setCancelError] = useState<unknown>(null);
  const [cancelled, setCancelled] = useState(false);
  const [needsReview, setNeedsReview] = useState(false);
  const pending = cancelling || cancelMutation.isPending;
  const query = useTeamCalendarEvent(unit, id, !accessDenied && !pending);
  const { refreshing, refresh } = useCalendarManualRefresh(query);
  const requestInFlight = useRef(false);
  const succeeded = useRef(false);
  const cancellationVersion = useRef<number | null>(null);
  const confirmationOpen = useRef(false);
  const mounted = useRef(true);
  const event = query.data?.data;
  const queryKind = query.isError ? teamCalendarErrorKind(query.error) : null;
  const cancelKind = cancelError ? teamCalendarErrorKind(cancelError) : null;
  const offline = network.isConnected === false || query.fetchStatus === 'paused';
  const canWrite = foregroundOnline && focused && !offline;
  const current = useRef({ canWrite, event, needsReview, overlayActive });
  current.current = { canWrite, event, needsReview, overlayActive };
  const { requestLeave } = useWorkspaceDraft({
    key: `team-calendar-cancel:${unit ?? 'none'}:${id}`,
    dirty: false,
    submitting: pending,
    onDiscard: () => undefined,
  });

  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);
  useEffect(() => {
    if (queryKind === 'forbidden') { recoverAccess().catch(() => undefined); }
  }, [queryKind, recoverAccess]);

  function goBack() {
    if (requestInFlight.current || current.current.overlayActive || confirmationOpen.current) { return; }
    requestLeave(() => {
      if (navigation.canGoBack()) { navigation.goBack(); }
      else { navigation.navigate('TeamCalendarHome'); }
    });
  }

  function editEvent() {
    const state = current.current;
    if (requestInFlight.current || succeeded.current || confirmationOpen.current || state.overlayActive || state.needsReview || !state.event?.can_edit || state.event.status !== 'scheduled') { return; }
    navigation.navigate('TeamCalendarEventForm', { id });
  }

  function confirmCancel() {
    const state = current.current;
    // This latch guards duplicate open taps, not the outgoing native presentation.
    // An explicit retry may request the next presentation while the old one exits.
    if (requestInFlight.current || succeeded.current || confirmationOpen.current || !state.canWrite || state.needsReview || !state.event?.can_edit || state.event.status !== 'scheduled') { return; }
    // A retry keeps the reviewed version; polling cannot silently authorize a newer one.
    if (!cancelError) { cancellationVersion.current = state.event.version; }
    confirmationOpen.current = true;
    setCancelVisible(true);
  }

  async function cancelEvent() {
    const state = current.current;
    const version = cancellationVersion.current;
    if (requestInFlight.current || succeeded.current || !confirmationOpen.current || !state.canWrite || state.needsReview || !state.event?.can_edit || state.event.status !== 'scheduled' || version === null) { return; }
    requestInFlight.current = true;
    setCancelling(true);
    setCancelError(null);
    try {
      await cancelMutation.mutateAsync(version);
      succeeded.current = true;
      if (mounted.current) { setCancelled(true); }
    } catch (error) {
      if (mounted.current) {
        const kind = teamCalendarErrorKind(error);
        setCancelError(error);
        setNeedsReview(['stale', 'conflict', 'forbidden', 'missing'].includes(kind));
      }
    } finally {
      requestInFlight.current = false;
      if (mounted.current) {
        confirmationOpen.current = false;
        setCancelling(false);
        setCancelVisible(false);
      }
    }
  }

  async function reviewLatest() {
    if (requestInFlight.current || !query.eligible || refreshing) { return; }
    const latest = await query.refetch();
    if (mounted.current && latest.isSuccess) {
      cancellationVersion.current = null;
      setCancelError(null);
      setNeedsReview(false);
      cancelMutation.reset();
    }
  }

  const header = (
    <PageHeader
      edgeToEdge
      title="Event details"
      subtitle={activeUnit?.label ?? 'Team Workspace'}
      onBack={goBack}
      backDisabled={pending || cancelVisible || overlayActive}
      action={(
        <WorkspaceHeaderActions disabled={pending || cancelVisible || overlayActive}>
          {event?.can_edit && event.status === 'scheduled' && !cancelled && !accessDenied ? (
            <IconAction label="Edit event" icon={<Edit3 size={18} color={theme.brandIcon} />} disabled={pending || cancelVisible || overlayActive || needsReview} onPress={editEvent} />
          ) : null}
        </WorkspaceHeaderActions>
      )}
    />
  );

  if (!unit) {
    return (
      <Screen header={header}>
        <EmptyState icon={<Users size={30} color={theme.brandIcon} />} title="Choose a Team Workspace unit" body="Select a calendar-enabled unit to view this event." action={<Action full onPress={openUnitPicker}>Choose unit</Action>} />
      </Screen>
    );
  }
  if (accessDenied || queryKind === 'forbidden') {
    return (
      <Screen header={header}>
        <EmptyState icon={<Users size={30} color={theme.brandIcon} />} title="Calendar access changed" body="Your calendar read access is no longer available. Refresh workspace access to continue." action={<Action full loading={refreshingAccess} disabled={offline} onPress={() => { retryAccess().catch(() => undefined); }}>Refresh access</Action>} />
      </Screen>
    );
  }
  if (query.isLoading && !event) {
    return <Screen scroll={false} bodyStyle={styles.center} header={header}><ActivityIndicator accessibilityLabel="Loading event" color={theme.brandIcon} /></Screen>;
  }
  if (!event) {
    return (
      <Screen header={header}>
        <EmptyState
          icon={<RefreshCw size={30} color={theme.brandIcon} />}
          title={queryKind === 'missing' ? 'Event unavailable' : offline ? 'You are offline' : 'Could not load event'}
          body={teamCalendarErrorMessage(query.error, offline ? 'Reconnect to load this event.' : 'Try loading the event again.')}
          action={queryKind === 'unauthorized' ? undefined : <Action full loading={refreshing} disabled={!query.eligible} onPress={() => { refresh().catch(() => undefined); }}>Try again</Action>}
        />
      </Screen>
    );
  }

  const startDate = instantToManilaDate(event.starts_at);
  const endDate = instantToManilaDate(event.ends_at);
  const sameDate = startDate !== null && startDate === endDate;
  const status = cancelled ? 'cancelled' : event.status;
  const cancellationMessage = cancelKind === 'forbidden'
    ? 'You are not allowed to cancel this event. Your calendar remains available; review the latest event permissions.'
    : teamCalendarErrorMessage(cancelError, 'The event could not be cancelled. Try again.');

  return (
    <>
      <Screen
        header={header}
        refreshControl={<RefreshControl refreshing={refreshing} enabled={query.eligible} tintColor={theme.brandIcon} colors={[theme.brandIcon]} onRefresh={() => { refresh().catch(() => undefined); }} />}>
        {offline ? <Notice text="You are offline. These details may be out of date." /> : null}
        {query.isError ? (
          <View style={styles.refreshError}>
            <Notice text="Could not refresh. Showing the last available event details." />
            <Action variant="secondary" loading={refreshing} disabled={!query.eligible} onPress={() => { refresh().catch(() => undefined); }}>Retry refresh</Action>
          </View>
        ) : null}
        <View style={styles.card}>
          <View style={styles.titleRow}>
            <Text accessibilityRole="header" style={styles.eventTitle}>{event.title}</Text>
            <StatusPill tone={status === 'cancelled' ? 'danger' : 'info'}>{status}</StatusPill>
          </View>
          <View style={styles.schedule}>
            <Fact label="Starts" value={`${startDate ? formatManilaDayLabel(startDate) : 'Invalid date'}, ${formatManilaTimeFromInstant(event.starts_at)}`} />
            <Fact label="Ends" value={`${sameDate ? '' : endDate ? `${formatManilaDayLabel(endDate)}, ` : 'Invalid date, '}${formatManilaTimeFromInstant(event.ends_at)}`} />
            <Text style={styles.muted}>Asia/Manila time</Text>
          </View>
          {event.location ? <View style={styles.inlineFact}><MapPin size={17} color={theme.ink500} /><Text style={styles.inlineFactText}>{event.location}</Text></View> : null}
          {event.description ? <Text style={styles.description}>{event.description}</Text> : null}
        </View>

        <Text accessibilityRole="header" style={styles.sectionTitle}>Participants ({event.participants.length})</Text>
        <View style={styles.card}>
          {event.participants.length === 0 ? <Text style={styles.muted}>No participants selected.</Text> : event.participants.map(member => (
            <View key={member.id} style={styles.memberRow}>
              <View style={styles.initial}><Text style={styles.initialText}>{member.name.trim().slice(0, 1).toUpperCase()}</Text></View>
              <View style={styles.memberCopy}>
                <Text style={styles.memberName}>{member.name}</Text>
                <Text style={styles.memberRole}>{member.role.replace(/_/g, ' ')}</Text>
              </View>
            </View>
          ))}
        </View>

        {event.can_edit && status === 'scheduled' ? (
          <View style={styles.actions}>
            <Action full variant="secondary" icon={<Edit3 size={17} color={theme.brandIcon} />} disabled={pending || cancelVisible || overlayActive || needsReview} onPress={editEvent}>Edit event</Action>
            <Action full variant="danger" icon={<CalendarX2 size={17} color="#ffffff" />} loading={pending} disabled={!canWrite || needsReview || cancelVisible} onPress={confirmCancel}>Cancel event</Action>
          </View>
        ) : null}
        {cancelError ? (
          <View accessibilityRole="alert" accessibilityLiveRegion="polite" style={styles.errorBlock}>
            <Text style={styles.errorText}>{cancellationMessage}</Text>
            {needsReview ? <Action variant="secondary" loading={query.isFetching} disabled={!query.eligible} onPress={() => { reviewLatest().catch(() => undefined); }}>Review latest</Action> : null}
            {!needsReview && cancelKind !== 'unauthorized' ? <Action variant="secondary" disabled={!canWrite || pending || cancelVisible} onPress={confirmCancel}>Retry cancellation</Action> : null}
          </View>
        ) : null}
      </Screen>
      <ConfirmDialog
        visible={cancelVisible}
        title="Cancel event?"
        message={`Cancel “${event.title}”? The event remains in calendar history as cancelled and cannot be edited again.`}
        confirmLabel="Cancel event"
        cancelLabel="Keep event"
        destructive
        loading={pending}
        onConfirm={() => { cancelEvent().catch(() => undefined); }}
        onCancel={() => {
          if (!requestInFlight.current) {
            confirmationOpen.current = false;
            setCancelVisible(false);
          }
        }}
      />
    </>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <View style={styles.fact}><Text style={styles.factLabel}>{label}</Text><Text style={styles.factValue}>{value}</Text></View>;
}

function Notice({ text }: { text: string }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <View accessibilityRole="alert" style={styles.notice}><Text style={styles.noticeText}>{text}</Text></View>;
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    center: { alignItems: 'center', justifyContent: 'center' },
    notice: { minHeight: 44, justifyContent: 'center', marginBottom: 10, padding: 10, borderRadius: 11, borderWidth: 1, borderColor: theme.mode === 'dark' ? '#685536' : '#ead39f', backgroundColor: theme.mode === 'dark' ? '#30291c' : '#f9efd9' },
    noticeText: { ...type.bodyStrong, color: theme.mode === 'dark' ? '#f5d697' : '#79591f' },
    card: { padding: 14, borderRadius: 15, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    titleRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
    eventTitle: { ...type.cardTitle, flex: 1, color: theme.ink900 },
    schedule: { gap: 10, marginTop: 14 },
    fact: { gap: 2 }, factLabel: { ...type.micro, color: theme.ink500, textTransform: 'uppercase' }, factValue: { ...type.bodyStrong, color: theme.ink700 },
    inlineFact: { minHeight: 44, flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 8 },
    inlineFactText: { ...type.body, flex: 1, color: theme.ink700 },
    description: { ...type.body, marginTop: 12, lineHeight: 18, color: theme.ink700 },
    sectionTitle: { ...type.cardTitle, marginTop: 20, marginBottom: 8, color: theme.ink900 },
    muted: { ...type.body, color: theme.ink500 },
    memberRow: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 10, borderBottomWidth: 1, borderBottomColor: theme.line },
    initial: { width: 32, height: 32, alignItems: 'center', justifyContent: 'center', borderRadius: 16, backgroundColor: theme.brand50 },
    initialText: { ...type.bodyStrong, color: theme.brandText },
    memberCopy: { flex: 1 }, memberName: { ...type.bodyStrong, color: theme.ink900 }, memberRole: { ...type.caption, color: theme.ink500, textTransform: 'capitalize' },
    actions: { gap: 8, marginTop: 20 },
    errorBlock: { gap: 8, marginTop: 12 },
    errorText: { ...type.bodyStrong, color: theme.mode === 'dark' ? '#ffb4ae' : '#a33b3b' },
    refreshError: { gap: 8, marginBottom: 12 },
  });
}
const makeStyles = memoizeStyles(createStyles);
