import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { Check, Search, Users, X } from 'lucide-react-native';
import type {
  TeamCalendarEventEditableInput,
} from '../../../api/endpoints/teamCalendar';
import type { TeamMember } from '../../../api/endpoints/teamTasks';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { TextField } from '../../../components/TextField';
import { secureClientNonce } from '../../../lib/secureNonce';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, memoizeStyles, type } from '../../../theme/tokens';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { ManilaDateTimeField } from '../components/ManilaDateTimeField';
import {
  isAmbiguousCreateError,
  teamCalendarErrorKind,
  teamCalendarErrorMessage,
} from '../errors';
import {
  useCreateTeamCalendarEvent,
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
    return {
      title: '',
      description: '',
      location: '',
      starts_at,
      ends_at,
      participant_ids: [],
    };
  }
  return {
    title: '',
    description: '',
    location: '',
    starts_at: walls.starts_at,
    ends_at: walls.ends_at,
    participant_ids: [],
  };
}

export function TeamCalendarEventFormScreen() {
  const route = useRoute<FormRoute>();
  const navigation = useNavigation<Navigation>();
  const id = route.params?.id;
  const editing = id !== undefined;
  const { activeUnit, openUnitPicker, registerDirtyDraft, requestDiscard } = useWorkspace();
  const unit = activeUnit?.features.team_calendar === true ? activeUnit.unit : null;
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const initialDraft = useMemo(() => newDraft(route.params?.initialDate), [route.params?.initialDate]);
  const detail = useTeamCalendarEvent(unit, id ?? 0);
  const searchMembers = useTeamCalendarMemberSearch(unit);
  const createEvent = useCreateTeamCalendarEvent(unit);
  const updateEvent = useUpdateTeamCalendarEvent(unit, id ?? 0);
  const [draft, setDraft] = useState<Draft>(initialDraft);
  const [loadedVersion, setLoadedVersion] = useState<number | null>(null);
  const [initialSnapshot, setInitialSnapshot] = useState(JSON.stringify(initialDraft));
  const [selectedMembers, setSelectedMembers] = useState<TeamMember[]>([]);
  const [memberQuery, setMemberQuery] = useState('');
  const [memberResults, setMemberResults] = useState<TeamMember[]>([]);
  const [memberSearching, setMemberSearching] = useState(false);
  const [memberSearchError, setMemberSearchError] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [ambiguousRetry, setAmbiguousRetry] = useState(false);
  const pendingCreate = useRef<{ snapshot: string; nonce: string } | null>(null);

  useEffect(() => {
    const event = detail.data?.data;
    if (!editing || !event || loadedVersion !== null) { return; }
    const loaded: Draft = {
      title: event.title,
      description: event.description,
      location: event.location,
      starts_at: instantToManilaWall(event.starts_at) ?? defaultEventWalls().starts_at,
      ends_at: instantToManilaWall(event.ends_at) ?? defaultEventWalls().ends_at,
      participant_ids: normalizeParticipantIds(event.participants.map(member => member.id)),
    };
    setDraft(loaded);
    setInitialSnapshot(JSON.stringify(loaded));
    setSelectedMembers(event.participants);
    setLoadedVersion(event.version);
  }, [detail.data, editing, loadedVersion]);

  const snapshot = JSON.stringify(draft);
  const dirty = editing ? loadedVersion !== null && snapshot !== initialSnapshot : snapshot !== initialSnapshot;
  const submitting = createEvent.isPending || updateEvent.isPending;

  useEffect(() => {
    if (!dirty) { return undefined; }
    return registerDirtyDraft(`team-calendar-event:${unit ?? 'none'}:${id ?? 'new'}`);
  }, [dirty, id, registerDirtyDraft, unit]);

  useEffect(() => navigation.addListener('beforeRemove', event => {
    if (!dirty || submitting) { return; }
    event.preventDefault();
    requestDiscard(() => navigation.dispatch(event.data.action));
  }), [dirty, navigation, requestDiscard, submitting]);

  useEffect(() => {
    let cancelled = false;
    setMemberSearching(true);
    setMemberSearchError(false);
    const timer = setTimeout(() => {
      searchMembers(memberQuery, 1).then(results => {
        if (!cancelled) { setMemberResults(results); }
      }).catch(() => {
        if (!cancelled) { setMemberSearchError(true); }
      }).finally(() => {
        if (!cancelled) { setMemberSearching(false); }
      });
    }, memberQuery.trim() === '' ? 0 : 250);
    return () => { cancelled = true; clearTimeout(timer); };
  }, [memberQuery, searchMembers]);

  function patch(value: Partial<Draft>) {
    setDraft(current => {
      const next = { ...current, ...value };
      if (pendingCreate.current?.snapshot !== JSON.stringify(next)) {
        pendingCreate.current = null;
        setAmbiguousRetry(false);
        createEvent.reset();
      }
      return next;
    });
    setError(null);
  }

  function toggleMember(member: TeamMember) {
    const selected = draft.participant_ids.includes(member.id);
    if (!selected && draft.participant_ids.length >= 100) {
      setError('An event can have at most 100 participants.');
      return;
    }
    const participant_ids = selected
      ? draft.participant_ids.filter(value => value !== member.id)
      : normalizeParticipantIds([...draft.participant_ids, member.id]);
    setSelectedMembers(current => selected
      ? current.filter(value => value.id !== member.id)
      : current.some(value => value.id === member.id) ? current : [...current, member]);
    patch({ participant_ids });
  }

  async function submit() {
    if (!unit) { setError('Choose a calendar-enabled Team Workspace unit first.'); return; }
    if (!editing && !activeUnit?.can_manage) { setError('You are not authorized to create Team Calendar events.'); return; }
    const title = draft.title.trim();
    if (title === '') { setError('Enter an event title.'); return; }
    if (!parseManilaWall(draft.starts_at) || !parseManilaWall(draft.ends_at)) { setError('Choose valid start and end times in Asia/Manila.'); return; }
    const start = manilaWallToPickerDate(draft.starts_at).getTime();
    const end = manilaWallToPickerDate(draft.ends_at).getTime();
    if (end <= start) { setError('End time must be after start time.'); return; }
    if (end - start > 31 * 24 * 60 * 60 * 1000) { setError('An event cannot last longer than 31 days.'); return; }
    const payload: Draft = {
      title,
      description: draft.description.trim(),
      location: draft.location.trim(),
      starts_at: draft.starts_at,
      ends_at: draft.ends_at,
      participant_ids: normalizeParticipantIds(draft.participant_ids),
    };
    setError(null);
    try {
      if (editing) {
        const event = detail.data?.data;
        if (loadedVersion === null || !event?.can_edit || event.status === 'cancelled') {
          setError('You are not authorized to edit this event.');
          return;
        }
        await updateEvent.mutateAsync({ ...payload, version: loadedVersion });
        navigation.goBack();
        return;
      }
      const payloadSnapshot = JSON.stringify(payload);
      const attempt = pendingCreate.current?.snapshot === payloadSnapshot
        ? pendingCreate.current
        : { snapshot: payloadSnapshot, nonce: secureClientNonce() };
      pendingCreate.current = attempt;
      let response;
      try {
        response = await createEvent.mutateAsync({ ...payload, client_nonce: attempt.nonce });
      } catch (firstError) {
        if (!isAmbiguousCreateError(firstError)) { throw firstError; }
        // A dropped response is ambiguous. Immediately replay the byte-equivalent
        // request once with the same nonce; the server returns the original event
        // if the first write committed, and no duplicate can be created.
        try {
          response = await createEvent.mutateAsync({ ...payload, client_nonce: attempt.nonce });
        } catch (retryError) {
          throw retryError;
        }
      }
      pendingCreate.current = null;
      setAmbiguousRetry(false);
      navigation.replace('TeamCalendarEventDetail', { id: response.data.id });
    } catch (submitError) {
      const kind = teamCalendarErrorKind(submitError);
      if (isAmbiguousCreateError(submitError) && !editing) {
        setAmbiguousRetry(true);
        setError('The create result is unknown because the connection ended. Retry sends the exact request with the same nonce, so it cannot create a duplicate.');
      } else {
        if (!editing) { pendingCreate.current = null; }
        setAmbiguousRetry(false);
        setError(kind === 'stale'
          ? 'This event changed elsewhere. Return to the latest event details before editing again.'
          : teamCalendarErrorMessage(submitError, 'The event could not be saved.'));
      }
    }
  }

  const header = (
    <PageHeader
      edgeToEdge
      title={editing ? 'Edit Event' : 'New Event'}
      subtitle={activeUnit?.label ?? 'Team Workspace'}
      onBack={() => requestDiscard(navigation.goBack)}
      action={(
        <Pressable accessibilityRole="button" accessibilityLabel="Switch Team Workspace unit" onPress={() => requestDiscard(openUnitPicker)} style={styles.headerButton}>
          <Users size={19} color={theme.brandIcon} />
        </Pressable>
      )}
    />
  );

  if (!unit || (!editing && !activeUnit?.can_manage)) {
    return (
      <Screen header={header}>
        <EmptyState
          icon={<Users size={30} color={theme.brandIcon} />}
          title="Event creation unavailable"
          body={!unit ? 'Choose a calendar-enabled Team Workspace unit.' : 'Only authorized workspace managers can create events.'}
          action={!unit ? <Action full onPress={openUnitPicker}>Choose unit</Action> : undefined}
        />
      </Screen>
    );
  }

  if (editing && detail.isLoading) {
    return <Screen scroll={false} bodyStyle={styles.center} header={header}><ActivityIndicator accessibilityLabel="Loading event form" color={theme.brandIcon} /></Screen>;
  }

  const loadedEvent = detail.data?.data;
  if (editing && (detail.isError || !loadedEvent || !loadedEvent.can_edit || loadedEvent.status === 'cancelled')) {
    return (
      <Screen header={header}>
        <EmptyState
          icon={<Users size={30} color={theme.brandIcon} />}
          title={loadedEvent && (!loadedEvent.can_edit || loadedEvent.status === 'cancelled') ? 'Event cannot be edited' : 'Could not load event'}
          body={loadedEvent && (!loadedEvent.can_edit || loadedEvent.status === 'cancelled')
            ? 'You are not authorized to edit this event, or it has been cancelled.'
            : teamCalendarErrorMessage(detail.error, 'Try loading the event again.')}
          action={!loadedEvent ? <Action full onPress={() => { detail.refetch().catch(() => undefined); }}>Try again</Action> : undefined}
        />
      </Screen>
    );
  }

  const resultMembers = mergeMembers(selectedMembers, memberResults);

  return (
    <Screen header={header}>
      <TextField label="Title" value={draft.title} onChangeText={title => patch({ title })} maxLength={160} editable={!submitting} />
      <TextField label="Description" value={draft.description} onChangeText={description => patch({ description })} maxLength={4000} multiline editable={!submitting} style={styles.textarea} />
      <TextField label="Location" value={draft.location} onChangeText={location => patch({ location })} maxLength={160} editable={!submitting} />
      <ManilaDateTimeField label="Starts" value={draft.starts_at} onChange={starts_at => patch({ starts_at })} disabled={submitting} />
      <ManilaDateTimeField label="Ends" value={draft.ends_at} onChange={ends_at => patch({ ends_at })} disabled={submitting} />

      <Text accessibilityRole="header" style={styles.sectionTitle}>Participants ({draft.participant_ids.length}/100)</Text>
      {selectedMembers.length > 0 ? (
        <View style={styles.selectedChips}>
          {selectedMembers.filter(member => draft.participant_ids.includes(member.id)).map(member => (
            <Pressable key={member.id} accessibilityRole="button" accessibilityLabel={`Remove ${member.name}`} onPress={() => toggleMember(member)} style={styles.selectedChip}>
              <Text numberOfLines={1} style={styles.selectedChipText}>{member.name}</Text><X size={14} color={theme.brandIcon} />
            </Pressable>
          ))}
        </View>
      ) : null}
      <TextField
        label="Search Team Members"
        value={memberQuery}
        onChangeText={setMemberQuery}
        maxLength={80}
        editable={!submitting}
        placeholder="Search by name"
        hint={memberSearching ? 'Searching members…' : 'Results are limited to active members in this unit.'}
        trailing={<Search size={18} color={theme.ink500} />}
      />
      {memberSearchError ? <Text accessibilityRole="alert" style={styles.memberError}>Could not search members. Change the search or try again.</Text> : null}
      <View accessibilityRole="list" style={styles.memberList}>
        {resultMembers.length === 0 && !memberSearching ? <Text style={styles.noMembers}>No matching members.</Text> : resultMembers.map(member => {
          const selected = draft.participant_ids.includes(member.id);
          return (
            <Pressable
              key={member.id}
              accessibilityRole="checkbox"
              accessibilityState={{ checked: selected, disabled: submitting }}
              disabled={submitting}
              onPress={() => toggleMember(member)}
              style={styles.memberOption}>
              <View style={[styles.checkbox, selected && styles.checkboxSelected]}>{selected ? <Check size={13} color="#ffffff" /> : null}</View>
              <View style={styles.memberCopy}><Text style={styles.memberName}>{member.name}</Text><Text style={styles.memberRole}>{member.role.replace(/_/g, ' ')}</Text></View>
            </Pressable>
          );
        })}
      </View>

      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      <View style={styles.actions}>
        <Action full loading={submitting} onPress={() => { submit().catch(() => undefined); }}>{ambiguousRetry ? 'Retry Create Safely' : editing ? 'Save Changes' : 'Create Event'}</Action>
        <Action full variant="ghost" disabled={submitting} onPress={() => requestDiscard(navigation.goBack)}>Cancel</Action>
      </View>
    </Screen>
  );
}

function mergeMembers(selected: TeamMember[], results: TeamMember[]): TeamMember[] {
  const byId = new Map<number, TeamMember>();
  selected.forEach(member => byId.set(member.id, member));
  results.forEach(member => byId.set(member.id, member));
  return [...byId.values()].slice(0, 100);
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    center: { alignItems: 'center', justifyContent: 'center' },
    headerButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
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
    memberError: { ...type.bodyStrong, marginTop: 7, color: '#a33b3b' },
    error: { ...type.bodyStrong, marginTop: 14, color: '#a33b3b' },
    actions: { gap: 7, marginTop: 22 },
  });
}

const makeStyles = memoizeStyles(createStyles);
