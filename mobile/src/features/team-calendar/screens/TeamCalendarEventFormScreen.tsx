import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNetInfo } from '@react-native-community/netinfo';
import { useIsFocused, useNavigation, useRoute } from '@react-navigation/native';
import type { RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { Check, Search, Users, X } from 'lucide-react-native';
import type { TeamCalendarEventCreateInput, TeamCalendarEventDetail, TeamCalendarEventEditableInput } from '../../../api/endpoints/teamCalendar';
import type { TeamMember } from '../../../api/endpoints/teamTasks';
import { Action } from '../../../components/Action';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { EmptyState } from '../../../components/EmptyState';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { Skeleton } from '../../../components/Skeleton';
import { TextField } from '../../../components/TextField';
import { loadingState } from '../../../lib/loadingState';
import { secureClientNonce } from '../../../lib/secureNonce';
import { useForegroundOnline } from '../../../lib/useActiveScreen';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, memoizeStyles, type } from '../../../theme/tokens';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { WorkspaceHeaderActions } from '../../workspace/WorkspaceHeaderActions';
import { useWorkspaceDraft } from '../../workspace/useWorkspaceDraft';
import { ManilaDateTimeField } from '../components/ManilaDateTimeField';
import { isAmbiguousCreateError, teamCalendarErrorKind, teamCalendarErrorMessage } from '../errors';
import {
  useCalendarManualRefresh,
  useCreateTeamCalendarEvent,
  useTeamCalendarAccess,
  useTeamCalendarEvent,
  useTeamCalendarMemberSearch,
  useUpdateTeamCalendarEvent,
} from '../hooks';
import {
  defaultEventWalls,
  formatManilaWall,
  instantToManilaWall,
  manilaWallToPickerDate,
  parseManilaDate,
  parseManilaWall,
  pickerDateToManilaParts,
} from '../manila';
import { normalizeParticipantIds } from '../model';
import type { TeamCalendarStackParamList } from '../navigation';

type FormRoute = RouteProp<TeamCalendarStackParamList, 'TeamCalendarEventForm'>;
type Navigation = NativeStackNavigationProp<TeamCalendarStackParamList>;
type Draft = TeamCalendarEventEditableInput;

function newDraft(initialDate?: string): Draft {
  const walls = defaultEventWalls();
  const validDate = initialDate && parseManilaDate(initialDate) ? initialDate : null;
  if (validDate) {
    const starts_at = `${validDate}${walls.starts_at.slice(10)}`;
    const startInstant = manilaWallToPickerDate(starts_at);
    const ends_at = formatManilaWall(pickerDateToManilaParts(new Date(startInstant.getTime() + 60 * 60 * 1000)));
    return { title: '', description: '', location: '', starts_at, ends_at, participant_ids: [] };
  }
  return { title: '', description: '', location: '', starts_at: walls.starts_at, ends_at: walls.ends_at, participant_ids: [] };
}

function eventDraft(event: TeamCalendarEventDetail): Draft {
  return {
    title: event.title,
    description: event.description,
    location: event.location,
    starts_at: instantToManilaWall(event.starts_at) ?? defaultEventWalls().starts_at,
    ends_at: instantToManilaWall(event.ends_at) ?? defaultEventWalls().ends_at,
    participant_ids: normalizeParticipantIds(event.participants.map(member => member.id)),
  };
}

export function TeamCalendarEventFormScreen() {
  const route = useRoute<FormRoute>();
  const { activeUnit } = useWorkspace();
  // A different record/unit must not reuse this record's draft, version or nonce.
  return <EventForm key={`${route.key}:${activeUnit?.unit ?? 'none'}:${route.params?.id ?? 'new'}`} />;
}

function EventForm() {
  const route = useRoute<FormRoute>();
  const navigation = useNavigation<Navigation>();
  const id = route.params?.id;
  const editing = id !== undefined;
  const { activeUnit, openUnitPicker } = useWorkspace();
  const unit = activeUnit?.features.team_calendar === true ? activeUnit.unit : null;
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const network = useNetInfo();
  const focused = useIsFocused();
  const foregroundOnline = useForegroundOnline();
  const [memberQuery, setMemberQuery] = useState('');
  const [debouncedQuery, setDebouncedQuery] = useState('');
  const [memberPage, setMemberPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [needsReview, setNeedsReview] = useState(false);
  const [ambiguousRetry, setAmbiguousRetry] = useState(false);
  const [editingRetry, setEditingRetry] = useState(false);
  const editAfterDismiss = useRef(false);
  const pendingCreate = useRef<TeamCalendarEventCreateInput | null>(null);
  const requestInFlight = useRef(false);
  const succeeded = useRef(false);
  const [saving, setSaving] = useState(false);
  const mounted = useRef(true);
  const createEvent = useCreateTeamCalendarEvent(unit);
  const updateEvent = useUpdateTeamCalendarEvent(unit, id ?? 0);
  const submitting = saving || createEvent.isPending || updateEvent.isPending;
  const { accessDenied, refreshingAccess, recoverAccess, retryAccess } = useTeamCalendarAccess(unit);
  const detail = useTeamCalendarEvent(unit, id ?? 0, !submitting && !accessDenied);
  const { refreshing, refresh } = useCalendarManualRefresh(detail);
  const loadedEvent = detail.data?.data;
  const detailKind = detail.isError ? teamCalendarErrorKind(detail.error) : null;
  const readDenied = editing && ['unauthorized', 'forbidden', 'missing'].includes(detailKind ?? '');
  const contentState = loadingState(detail);
  const [initialDraft] = useState(() => editing && loadedEvent && !readDenied ? eventDraft(loadedEvent) : newDraft(route.params?.initialDate));
  const [draft, setDraft] = useState<Draft>(initialDraft);
  const draftRef = useRef(draft);
  draftRef.current = draft;
  const [loadedVersion, setLoadedVersion] = useState<number | null>(() => editing && loadedEvent && !readDenied ? loadedEvent.version : null);
  const [initialSnapshot, setInitialSnapshot] = useState(() => JSON.stringify(initialDraft));
  const [selectedMembers, setSelectedMembers] = useState<TeamMember[]>(() => editing && loadedEvent && !readDenied ? loadedEvent.participants : []);
  const baseline = useRef({ draft: initialDraft, members: selectedMembers });
  const deniedEdit = editing && !!loadedEvent && (!loadedEvent.can_edit || loadedEvent.status !== 'scheduled');
  const locked = submitting || ambiguousRetry || deniedEdit || accessDenied || readDenied;
  const members = useTeamCalendarMemberSearch(unit, debouncedQuery, memberPage,
    !locked && memberQuery.trim() === debouncedQuery && (!editing || loadedVersion !== null));
  const offline = network.isConnected === false || detail.fetchStatus === 'paused';
  const canWrite = focused && foregroundOnline && !offline && !readDenied;
  const current = useRef({ locked, canWrite, loadedEvent, loadedVersion, needsReview });
  current.current = { locked, canWrite, loadedEvent, loadedVersion, needsReview };

  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);
  useEffect(() => {
    const event = detail.data?.data;
    if (!editing || !event || loadedVersion !== null || readDenied || accessDenied) { return; }
    const loaded = eventDraft(event);
    baseline.current = { draft: loaded, members: event.participants };
    draftRef.current = loaded;
    setDraft(loaded);
    setInitialSnapshot(JSON.stringify(loaded));
    setSelectedMembers(event.participants);
    setLoadedVersion(event.version);
  }, [accessDenied, detail.data, editing, loadedVersion, readDenied]);
  useEffect(() => {
    if (detailKind === 'forbidden' || (members.isError && teamCalendarErrorKind(members.error) === 'forbidden')) {
      recoverAccess().catch(() => undefined);
    }
  }, [detailKind, members.error, members.isError, recoverAccess]);
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedQuery(memberQuery.trim()), memberQuery.trim() ? 250 : 0);
    return () => clearTimeout(timer);
  }, [memberQuery]);

  const snapshot = JSON.stringify(draft);
  const dirty = ambiguousRetry || ((editing ? loadedVersion !== null : true) && snapshot !== initialSnapshot);
  const { requestLeave, markSaved } = useWorkspaceDraft({
    key: `team-calendar-event:${unit ?? 'none'}:${id ?? 'new'}`,
    dirty,
    submitting,
    warning: ambiguousRetry ? 'The event may already have been created. Check the calendar before creating another event. Leaving discards the safe retry request.' : undefined,
    onDiscard: () => {
      draftRef.current = baseline.current.draft;
      setDraft(baseline.current.draft);
      setSelectedMembers(baseline.current.members);
      setInitialSnapshot(JSON.stringify(baseline.current.draft));
      setMemberQuery('');
      setDebouncedQuery('');
      setMemberPage(1);
      pendingCreate.current = null;
      setAmbiguousRetry(false);
      setEditingRetry(false);
      setError(null);
      setNeedsReview(false);
      createEvent.reset();
      updateEvent.reset();
    },
  });

  function leave() {
    if (requestInFlight.current) { return; }
    requestLeave(() => {
      if (navigation.canGoBack()) { navigation.goBack(); }
      else if (editing) { navigation.replace('TeamCalendarEventDetail', { id: id! }); }
      else { navigation.navigate('TeamCalendarHome'); }
    });
  }

  function reviewLatest() {
    if (requestInFlight.current || !editing) { return; }
    requestLeave(() => {
      if (navigation.canGoBack()) { navigation.goBack(); }
      else { navigation.replace('TeamCalendarEventDetail', { id: id! }); }
    });
  }

  function patch(value: Partial<Draft>) {
    if (requestInFlight.current || succeeded.current || current.current.locked) { return; }
    const next = { ...draftRef.current, ...value };
    draftRef.current = next;
    setDraft(next);
    pendingCreate.current = null;
    createEvent.reset();
    if (!current.current.needsReview) { setError(null); }
  }

  function toggleMember(member: TeamMember) {
    if (requestInFlight.current || succeeded.current || current.current.locked) { return; }
    const ids = draftRef.current.participant_ids;
    const selected = ids.includes(member.id);
    if (!selected && ids.length >= 100) { setError('An event can have at most 100 participants.'); return; }
    const participant_ids = selected ? ids.filter(value => value !== member.id) : normalizeParticipantIds([...ids, member.id]);
    setSelectedMembers(previous => selected
      ? previous.filter(value => value.id !== member.id)
      : previous.some(value => value.id === member.id) ? previous : [...previous, member]);
    patch({ participant_ids });
  }

  async function submit() {
    const state = current.current;
    if (requestInFlight.current || succeeded.current || state.needsReview || submitting || accessDenied) { return; }
    if (!unit) { setError('Choose a calendar-enabled Team Workspace unit first.'); return; }
    if (!state.canWrite) { setError('Reconnect while this form is open to save the event. Your draft is kept.'); return; }
    if (editing && (state.loadedVersion === null || !state.loadedEvent?.can_edit || state.loadedEvent.status !== 'scheduled')) {
      setError('You are not authorized to edit this event, or it has been cancelled.'); return;
    }
    const value = draftRef.current;
    const title = value.title.trim();
    if (!title) { setError('Enter an event title.'); return; }
    if (!parseManilaWall(value.starts_at) || !parseManilaWall(value.ends_at)) { setError('Choose valid start and end times in Asia/Manila.'); return; }
    const start = manilaWallToPickerDate(value.starts_at).getTime();
    const end = manilaWallToPickerDate(value.ends_at).getTime();
    if (end <= start) { setError('End time must be after start time.'); return; }
    if (end - start > 31 * 24 * 60 * 60 * 1000) { setError('An event cannot last longer than 31 days.'); return; }
    const payload: Draft = {
      title,
      description: value.description.trim(),
      location: value.location.trim(),
      starts_at: value.starts_at,
      ends_at: value.ends_at,
      participant_ids: normalizeParticipantIds(value.participant_ids),
    };
    requestInFlight.current = true;
    setSaving(true);
    setError(null);
    try {
      let savedId: number;
      if (editing) {
        const response = await updateEvent.mutateAsync({ ...payload, version: state.loadedVersion! });
        savedId = response.data.id;
      } else {
        // Every explicit retry sends this exact immutable payload and nonce. Never
        // replay automatically: a failed response may already have committed.
        const attempt = pendingCreate.current ?? { ...payload, client_nonce: secureClientNonce() };
        pendingCreate.current = attempt;
        const response = await createEvent.mutateAsync(attempt);
        savedId = response.data.id;
      }
      succeeded.current = true;
      pendingCreate.current = null;
      if (!mounted.current) { return; }
      setAmbiguousRetry(false);
      markSaved();
      if (editing && navigation.canGoBack()) { navigation.goBack(); }
      else { navigation.replace('TeamCalendarEventDetail', { id: savedId }); }
    } catch (submitError) {
      if (!mounted.current) { return; }
      const kind = teamCalendarErrorKind(submitError);
      if (!editing && isAmbiguousCreateError(submitError)) {
        setAmbiguousRetry(true);
        setError('The create result is unknown. Retry sends the exact saved request with the same nonce, so it cannot create a duplicate.');
      } else {
        if (!editing) { pendingCreate.current = null; }
        setAmbiguousRetry(false);
        setNeedsReview(editing && ['stale', 'conflict', 'forbidden', 'missing'].includes(kind));
        setError(kind === 'forbidden'
          ? 'You are not allowed to save this event. Your draft and calendar remain available; review the latest permissions.'
          : teamCalendarErrorMessage(submitError, 'The event could not be saved. Your draft is kept.'));
      }
    } finally {
      requestInFlight.current = false;
      if (mounted.current) { setSaving(false); }
    }
  }

  const header = (
    <PageHeader
      edgeToEdge
      title={editing ? 'Edit event' : 'New event'}
      subtitle={activeUnit?.label ?? 'Team Workspace'}
      onBack={leave}
      backDisabled={submitting || editingRetry}
      action={<WorkspaceHeaderActions disabled={submitting || editingRetry} />}
    />
  );

  if (!unit || accessDenied || detailKind === 'forbidden') {
    return (
      <Screen header={header}>
        <EmptyState
          icon={<Users size={30} color={theme.brandIcon} />}
          title={unit ? 'Calendar access changed' : 'Event creation unavailable'}
          body={unit ? 'Refresh workspace access to continue. The draft remains on this screen until you leave.' : 'Choose a calendar-enabled Team Workspace unit.'}
          action={unit
            ? <Action full loading={refreshingAccess} disabled={offline} onPress={() => { retryAccess().catch(() => undefined); }}>Refresh access</Action>
            : <Action full onPress={openUnitPicker}>Choose unit</Action>}
        />
      </Screen>
    );
  }
  if (editing && !readDenied && loadedVersion === null && contentState === 'loading' && !offline && detail.eligible !== false) {
    return <Screen header={header}><Skeleton label="Loading event form" variant="form" /></Screen>;
  }
  if (editing && !readDenied && !loadedEvent && !detail.isError) {
    return <Screen header={header}><EmptyState icon={<Users size={30} color={theme.brandIcon} />}
      title={offline ? 'You are offline' : 'Event is waiting to load'}
      body={offline ? 'Reconnect to load this event for editing.' : 'Return to this Team Workspace while connected to load the original event.'}
      action={<Action full disabled={!detail.eligible || offline} onPress={() => { refresh().catch(() => undefined); }}>Try again</Action>} /></Screen>;
  }
  if (editing && !readDenied && loadedEvent && loadedVersion === null) {
    return <Screen header={header}><Skeleton label="Preparing event form" variant="form" active={false} /></Screen>;
  }
  if (editing && (loadedVersion === null || readDenied)) {
    return (
      <Screen header={header}>
        <EmptyState
          icon={<Users size={30} color={theme.brandIcon} />}
          title={detailKind === 'missing' ? 'Event unavailable' : 'Could not load event'}
          body={teamCalendarErrorMessage(detail.error, offline ? 'Reconnect to load this event.' : 'Try loading the event again.')}
          action={detailKind === 'unauthorized' ? undefined : <Action full loading={refreshing} disabled={!detail.eligible} onPress={() => { refresh().catch(() => undefined); }}>Try again</Action>}
        />
      </Screen>
    );
  }

  const memberData = members.data;
  const page = memberData?.meta.page ?? memberPage;
  const limit = memberData?.meta.limit ?? 50;
  const total = memberData?.meta.total ?? 0;
  const pages = memberData?.meta.pages ?? Math.max(1, Math.ceil(total / limit));
  const searchPending = members.isFetching || memberQuery.trim() !== debouncedQuery;
  const resultMembers = memberQuery.trim() === debouncedQuery ? memberData?.data ?? [] : [];
  const saveDisabled = !canWrite || deniedEdit || needsReview;
  const footer = (
    <View style={styles.footer}>
      {error ? <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={styles.error}>{error}</Text> : null}
      {needsReview ? <Action full variant="secondary" disabled={submitting} onPress={reviewLatest}>Review latest event</Action> : null}
      <Action full loading={submitting} disabled={saveDisabled} icon={<Check size={17} color="#ffffff" />} onPress={() => { submit().catch(() => undefined); }}>
        {ambiguousRetry ? 'Retry create safely' : editing ? 'Save changes' : 'Create event'}
      </Action>
      {ambiguousRetry ? <Action full variant="secondary" disabled={submitting} onPress={() => setEditingRetry(true)}>Change draft</Action> : null}
      <Action full variant="ghost" disabled={submitting} onPress={leave}>Cancel</Action>
    </View>
  );

  return (
    <>
      <Screen header={header} keyboardAware bottomSafeArea footer={footer}>
        {offline ? <Text accessibilityRole="alert" style={styles.notice}>You are offline. Your draft is kept; reconnect to save.</Text> : null}
        {detail.isError && loadedVersion !== null ? (
          <View style={styles.refreshError}>
            <Text accessibilityRole="alert" style={styles.notice}>Could not refresh event details. Your draft and original version are kept.</Text>
            <Action variant="secondary" loading={refreshing} disabled={!detail.eligible} onPress={() => { refresh().catch(() => undefined); }}>Retry refresh</Action>
          </View>
        ) : null}
        {deniedEdit ? <Text accessibilityRole="alert" style={styles.notice}>This event can no longer be edited. Your draft is kept until you leave.</Text> : null}
        {loadedEvent && loadedVersion !== null && loadedEvent.version !== loadedVersion ? <Text accessibilityRole="alert" style={styles.notice}>A newer version is available. Your draft still uses the version you opened; saving will not overwrite newer changes.</Text> : null}
        <TextField label="Title" accessibilityLabel="Event title" value={draft.title} onChangeText={title => patch({ title })} maxLength={160} editable={!locked} style={styles.input} />
        <TextField label="Description" accessibilityLabel="Event description" value={draft.description} onChangeText={description => patch({ description })} maxLength={4000} multiline editable={!locked} style={[styles.input, styles.textarea]} />
        <TextField label="Location" accessibilityLabel="Event location" value={draft.location} onChangeText={location => patch({ location })} maxLength={160} editable={!locked} style={styles.input} />
        <ManilaDateTimeField label="Starts" value={draft.starts_at} onChange={starts_at => patch({ starts_at })} disabled={locked} />
        <ManilaDateTimeField label="Ends" value={draft.ends_at} onChange={ends_at => patch({ ends_at })} disabled={locked} />

        <Text accessibilityRole="header" style={styles.sectionTitle}>Participants ({draft.participant_ids.length}/100)</Text>
        {selectedMembers.length > 0 ? (
          <View style={styles.selectedChips}>
            {selectedMembers.filter(member => draft.participant_ids.includes(member.id)).map(member => (
              <Pressable key={member.id} accessibilityRole="button" accessibilityLabel={`Remove ${member.name}`} accessibilityState={{ disabled: locked }} disabled={locked} onPress={() => toggleMember(member)} style={[styles.selectedChip, locked && styles.disabled]}>
                <Text numberOfLines={1} style={styles.selectedChipText}>{member.name}</Text><X size={14} color={theme.brandIcon} />
              </Pressable>
            ))}
          </View>
        ) : null}
        <TextField
          label="Search team members"
          accessibilityLabel="Search team members"
          value={memberQuery}
          onChangeText={value => {
            if (requestInFlight.current || current.current.locked) { return; }
            setMemberPage(1);
            setMemberQuery(value);
          }}
          maxLength={80}
          editable={!locked}
          placeholder="Search by name"
          hint={searchPending ? 'Searching members…' : 'Active members in this unit. Up to 100 participants per event.'}
          trailing={<Search size={18} color={theme.ink500} />}
          style={styles.input}
        />
        {members.isError ? (
          <View style={styles.refreshError}>
            <Text accessibilityRole="alert" style={styles.error}>Could not load members. Selected participants are kept.</Text>
            <Action variant="secondary" disabled={locked || !members.eligible} onPress={() => { members.refetch().catch(() => undefined); }}>Retry member search</Action>
          </View>
        ) : null}
        {searchPending ? <ActivityIndicator accessibilityLabel="Searching team members" color={theme.brandIcon} style={styles.memberLoading} /> : null}
        <View accessibilityRole="list" style={styles.memberList}>
          {resultMembers.length === 0 && !searchPending && !members.isError && members.data ? <Text style={styles.noMembers}>No matching members.</Text> : resultMembers.map(member => {
            const selected = draft.participant_ids.includes(member.id);
            return (
              <Pressable
                key={member.id}
                accessibilityRole="checkbox"
                accessibilityLabel={`${member.name}, ${member.role.replace(/_/g, ' ')}`}
                accessibilityState={{ checked: selected, disabled: locked }}
                disabled={locked}
                onPress={() => toggleMember(member)}
                style={[styles.memberOption, locked && styles.disabled]}>
                <View style={[styles.checkbox, selected && styles.checkboxSelected]}>{selected ? <Check size={13} color="#ffffff" /> : null}</View>
                <View style={styles.memberCopy}><Text style={styles.memberName}>{member.name}</Text><Text style={styles.memberRole}>{member.role.replace(/_/g, ' ')}</Text></View>
              </Pressable>
            );
          })}
        </View>
        {memberData && memberQuery.trim() === debouncedQuery ? (
          <View style={styles.pagination}>
            <Text accessibilityLiveRegion="polite" style={styles.pageLabel}>Page {page} of {pages} · {total} matching members</Text>
            <View style={styles.pageActions}>
              <Action variant="secondary" disabled={locked || !members.eligible || searchPending || page <= 1} onPress={() => { if (!requestInFlight.current && !current.current.locked) { setMemberPage(Math.max(1, page - 1)); } }}>Previous members</Action>
              <Action variant="secondary" disabled={locked || !members.eligible || searchPending || page >= pages} onPress={() => { if (!requestInFlight.current && !current.current.locked) { setMemberPage(Math.min(pages, page + 1)); } }}>Next members</Action>
            </View>
          </View>
        ) : null}
      </Screen>
      <ConfirmDialog
        visible={editingRetry}
        title="Change an unconfirmed event?"
        message="The original event may already exist. Changing this draft starts a new request. Check the calendar first to avoid creating a second event."
        confirmLabel="Change draft"
        cancelLabel="Keep safe retry"
        onConfirm={() => { editAfterDismiss.current = true; setEditingRetry(false); }}
        onCancel={() => { editAfterDismiss.current = false; setEditingRetry(false); }}
        onDismiss={() => {
          if (!editAfterDismiss.current) { return; }
          editAfterDismiss.current = false;
          pendingCreate.current = null;
          setAmbiguousRetry(false);
          setError(null);
          createEvent.reset();
        }}
      />
    </>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    center: { alignItems: 'center', justifyContent: 'center' },
    input: { borderColor: theme.line },
    textarea: { minHeight: 100, paddingTop: 12, textAlignVertical: 'top' },
    sectionTitle: { ...type.cardTitle, marginTop: 21, marginBottom: 8, color: theme.ink900 },
    selectedChips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
    selectedChip: { minHeight: 44, maxWidth: '100%', flexDirection: 'row', alignItems: 'center', gap: 5, paddingHorizontal: 11, borderRadius: 22, borderWidth: 1, borderColor: theme.brand100, backgroundColor: theme.brand50 },
    selectedChipText: { ...type.caption, maxWidth: 180, color: theme.brandText },
    memberList: { overflow: 'hidden', marginTop: 8, borderRadius: 14, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    memberOption: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 12, borderBottomWidth: 1, borderBottomColor: theme.line },
    checkbox: { width: 22, height: 22, alignItems: 'center', justifyContent: 'center', borderRadius: 7, borderWidth: 1, borderColor: theme.ink400 },
    checkboxSelected: { borderColor: theme.brand, backgroundColor: theme.brand },
    memberCopy: { flex: 1 }, memberName: { ...type.bodyStrong, color: theme.ink900 }, memberRole: { ...type.caption, color: theme.ink500, textTransform: 'capitalize' },
    noMembers: { ...type.body, minHeight: 52, padding: 13, color: theme.ink500 },
    error: { ...type.bodyStrong, color: theme.mode === 'dark' ? '#ffb4ae' : '#a33b3b' },
    notice: { ...type.bodyStrong, padding: 12, marginBottom: 10, borderRadius: 11, backgroundColor: theme.mode === 'dark' ? '#30291c' : '#f9efd9', color: theme.mode === 'dark' ? '#f5d697' : '#79591f' },
    footer: { gap: 7 },
    refreshError: { gap: 8, marginTop: 8 },
    memberLoading: { marginVertical: 10 },
    pagination: { gap: 8, marginTop: 10 },
    pageLabel: { ...type.caption, color: theme.ink500 },
    pageActions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    disabled: { opacity: 0.55 },
  });
}
const makeStyles = memoizeStyles(createStyles);
