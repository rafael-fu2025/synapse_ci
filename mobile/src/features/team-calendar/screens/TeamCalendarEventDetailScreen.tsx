import React, { useCallback, useEffect, useRef } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNetInfo } from '@react-native-community/netinfo';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useQueryClient } from '@tanstack/react-query';
import { CalendarX2, Edit3, MapPin, RefreshCw, Users } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { StatusPill } from '../../../components/StatusPill';
import { useAuth } from '../../../store/auth';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, memoizeStyles, type } from '../../../theme/tokens';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { teamCalendarErrorKind, teamCalendarErrorMessage } from '../errors';
import {
  teamCalendarQueryKeys,
  useCancelTeamCalendarEvent,
  useTeamCalendarEvent,
} from '../hooks';
import {
  formatManilaDayLabel,
  formatManilaTimeFromInstant,
  instantToManilaDate,
} from '../manila';
import type { TeamCalendarStackParamList } from '../navigation';

type DetailRoute = RouteProp<TeamCalendarStackParamList, 'TeamCalendarEventDetail'>;
type Navigation = NativeStackNavigationProp<TeamCalendarStackParamList>;

export function TeamCalendarEventDetailScreen() {
  const route = useRoute<DetailRoute>();
  const navigation = useNavigation<Navigation>();
  const id = route.params.id;
  const { activeUnit, enterPersonal, openUnitPicker } = useWorkspace();
  const { retryBootstrap, user } = useAuth();
  const queryClient = useQueryClient();
  const network = useNetInfo();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const unit = activeUnit?.features.team_calendar === true ? activeUnit.unit : null;
  const query = useTeamCalendarEvent(unit, id);
  const cancelMutation = useCancelTeamCalendarEvent(unit, id);
  const accessRecoveryStarted = useRef(false);
  const event = query.data?.data;
  const queryKind = query.isError ? teamCalendarErrorKind(query.error) : null;
  const cancelKind = cancelMutation.isError ? teamCalendarErrorKind(cancelMutation.error) : null;
  const offline = network.isConnected === false
    || network.isInternetReachable === false
    || query.fetchStatus === 'paused';

  const recoverAccess = useCallback(async () => {
    if (accessRecoveryStarted.current || !activeUnit || !user) { return; }
    accessRecoveryStarted.current = true;
    queryClient.removeQueries({
      queryKey: teamCalendarQueryKeys.unit(user.tenant_id, user.id, activeUnit.unit),
      exact: false,
    });
    if (navigation.canGoBack()) { navigation.goBack(); }
    enterPersonal();
    await retryBootstrap().catch(() => undefined);
  }, [activeUnit, enterPersonal, navigation, queryClient, retryBootstrap, user]);

  useEffect(() => {
    if (queryKind === 'forbidden' || cancelKind === 'forbidden') {
      recoverAccess().catch(() => undefined);
    }
  }, [cancelKind, queryKind, recoverAccess]);

  const cancelEvent = async () => {
    if (!event || cancelMutation.isPending) { return; }
    await cancelMutation.mutateAsync(event.version).catch(() => undefined);
  };

  const confirmCancel = () => {
    Alert.alert(
      'Cancel event?',
      'The event remains in calendar history as cancelled and cannot be edited again.',
      [
        { text: 'Keep event', style: 'cancel' },
        { text: 'Cancel event', style: 'destructive', onPress: () => { cancelEvent().catch(() => undefined); } },
      ],
    );
  };

  const header = (
    <PageHeader
      edgeToEdge
      title="Event details"
      subtitle={activeUnit?.label ?? 'Team Workspace'}
      onBack={navigation.goBack}
      action={(
        <View style={styles.headerActions}>
          <Pressable accessibilityRole="button" accessibilityLabel="Switch Team Workspace unit" onPress={openUnitPicker} style={styles.headerButton}>
            <Users size={19} color={theme.brandIcon} />
          </Pressable>
          {event?.can_edit && event.status === 'scheduled' ? (
            <Pressable accessibilityRole="button" accessibilityLabel="Edit event" onPress={() => navigation.navigate('TeamCalendarEventForm', { id })} style={styles.headerButton}>
              <Edit3 size={18} color={theme.brandIcon} />
            </Pressable>
          ) : null}
        </View>
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

  if (query.isLoading) {
    return <Screen scroll={false} bodyStyle={styles.center} header={header}><ActivityIndicator accessibilityLabel="Loading event" color={theme.brandIcon} /></Screen>;
  }

  if (query.isError && !event) {
    return (
      <Screen header={header}>
        <EmptyState
          icon={<RefreshCw size={30} color={theme.brandIcon} />}
          title={queryKind === 'missing' ? 'Event unavailable' : queryKind === 'offline' ? 'You are offline' : queryKind === 'forbidden' ? 'Access changed' : 'Could not load event'}
          body={teamCalendarErrorMessage(query.error, 'Try loading the event again.')}
          action={queryKind === 'forbidden' || queryKind === 'unauthorized' ? undefined : <Action full onPress={() => { query.refetch().catch(() => undefined); }}>Try again</Action>}
        />
      </Screen>
    );
  }

  if (!event) { return null; }

  const startDate = instantToManilaDate(event.starts_at);
  const endDate = instantToManilaDate(event.ends_at);
  const sameDate = startDate !== null && startDate === endDate;

  return (
    <Screen header={header}>
      {offline ? <Notice text="You are offline. These details may be out of date." /> : null}
      {query.isError ? <Notice text={teamCalendarErrorMessage(query.error, 'Could not refresh. Showing the last available event details.')} /> : null}
      <View style={styles.card}>
        <View style={styles.titleRow}>
          <Text style={styles.eventTitle}>{event.title}</Text>
          <StatusPill tone={event.status === 'cancelled' ? 'danger' : 'info'}>{event.status}</StatusPill>
        </View>
        <View style={styles.schedule}>
          <Fact label="Starts" value={`${startDate ? formatManilaDayLabel(startDate) : 'Invalid date'}, ${formatManilaTimeFromInstant(event.starts_at)}`} />
          <Fact label="Ends" value={`${sameDate ? '' : endDate ? `${formatManilaDayLabel(endDate)}, ` : 'Invalid date, '}${formatManilaTimeFromInstant(event.ends_at)}`} />
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

      {event.can_edit && event.status === 'scheduled' ? (
        <View style={styles.actions}>
          <Action full variant="secondary" onPress={() => navigation.navigate('TeamCalendarEventForm', { id })}>Edit event</Action>
          <Action full variant="danger" loading={cancelMutation.isPending} onPress={confirmCancel}>
            <CalendarX2 size={17} color="#ffffff" />
            Cancel event
          </Action>
        </View>
      ) : null}
      {cancelMutation.isError ? (
        <View accessibilityRole="alert" style={styles.errorBlock}>
          <Text style={styles.errorText}>{teamCalendarErrorMessage(cancelMutation.error, 'The event could not be cancelled.')}</Text>
          {cancelKind === 'stale' || cancelKind === 'conflict' ? <Action variant="secondary" onPress={() => { cancelMutation.reset(); query.refetch().catch(() => undefined); }}>Review latest</Action> : null}
          {cancelKind === 'offline' ? <Action variant="secondary" onPress={() => { cancelEvent().catch(() => undefined); }}>Retry</Action> : null}
        </View>
      ) : null}
    </Screen>
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
    headerActions: { flexDirection: 'row', gap: 8 },
    headerButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    notice: { minHeight: 44, justifyContent: 'center', marginBottom: 10, padding: 10, borderRadius: 11, borderWidth: 1, borderColor: '#ead39f', backgroundColor: '#f9efd9' },
    noticeText: { ...type.bodyStrong, color: '#79591f' },
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
    errorText: { ...type.bodyStrong, color: '#a33b3b' },
  });
}

const makeStyles = memoizeStyles(createStyles);
