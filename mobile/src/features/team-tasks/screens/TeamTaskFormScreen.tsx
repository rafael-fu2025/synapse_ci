import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import DateTimePicker, { type DateTimePickerEvent } from '@react-native-community/datetimepicker';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { CalendarDays, Check, Plus, Trash2, Users, X } from 'lucide-react-native';
import type { TeamMember, TeamTaskCreateInput, TeamTaskPriority, TeamTaskStatus, TeamTaskUpdateInput } from '../../../api/endpoints/teamTasks';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { IconAction } from '../../../components/IconAction';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { Skeleton } from '../../../components/Skeleton';
import { TextField } from '../../../components/TextField';
import { loadingState } from '../../../lib/loadingState';
import { secureClientNonce } from '../../../lib/secureNonce';
import type { TeamTasksStackParamList } from '../../../navigation/types';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { formatManilaDate, formatManilaDayLabel, manilaWallToPickerDate, pickerDateToManilaParts, todayInManila } from '../../team-calendar/manila';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { WorkspaceHeaderActions } from '../../workspace/WorkspaceHeaderActions';
import { useWorkspaceDraft } from '../../workspace/useWorkspaceDraft';
import { isAmbiguousTaskSend, isTaskAccessFailure, teamTaskErrorKind, teamTaskErrorMessage } from '../errors';
import { useCreateTeamTask, useTeamMemberSearch, useTeamTask, useTeamTaskAccessRecovery, useTeamTaskScreenScope, useUpdateTeamTask } from '../hooks';
import { checklistDraftItem, emptyTaskDraft, PRIORITY_LABEL, STATUS_PRESENTATION, taskDraft, taskDraftInput, taskDraftSnapshot, TASK_PRIORITIES, TASK_STATUSES, validDateOnly } from '../model';
import type { ChecklistDraftItem, TeamTaskDraft } from '../model';

type FormRoute = RouteProp<TeamTasksStackParamList, 'TeamTaskForm'>;
type Navigation = NativeStackNavigationProp<TeamTasksStackParamList>;
type SaveRequest = { kind: 'create'; input: TeamTaskCreateInput } | { kind: 'update'; input: TeamTaskUpdateInput };

export function TeamTaskFormScreen() {
  const route = useRoute<FormRoute>();
  const navigation = useNavigation<Navigation>();
  const id = route.params?.id;
  const editing = id !== undefined;
  const { activeUnit, openUnitPicker } = useWorkspace();
  const unit = activeUnit?.unit ?? null;
  const isCurrentScope = useTeamTaskScreenScope(unit, `form:${id ?? 'new'}`);
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const detail = useTeamTask(unit, id ?? 0);
  const createTask = useCreateTeamTask(unit);
  const updateTask = useUpdateTeamTask(unit, id ?? 0);
  const { reset: resetCreate } = createTask;
  const { reset: resetUpdate } = updateTask;
  const reconcileAccess = useTeamTaskAccessRecovery(unit, detail.isError ? detail.error : null);
  const contentState = loadingState(detail);
  const paused = detail.fetchStatus === 'paused';
  const [draft, setDraft] = useState<TeamTaskDraft>(() => editing && detail.data?.data ? taskDraft(detail.data.data) : emptyTaskDraft());
  const [selectedMember, setSelectedMember] = useState<TeamMember | null>(() => editing && detail.data?.data.assignee ? { ...detail.data.data.assignee, role: '' } : null);
  const [loadedVersion, setLoadedVersion] = useState<number | null>(() => editing ? detail.data?.data.version ?? null : null);
  const initial = useRef({ draft, snapshot: taskDraftSnapshot(draft), version: loadedVersion, member: selectedMember });
  const draftRef = useRef(draft);
  const [memberQuery, setMemberQuery] = useState('');
  const [memberSearch, setMemberSearch] = useState('');
  const [checklistText, setChecklistText] = useState('');
  const checklistTextRef = useRef('');
  const [datePickerOpen, setDatePickerOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [failureKind, setFailureKind] = useState<ReturnType<typeof teamTaskErrorKind> | null>(null);
  const pendingSave = useRef<SaveRequest | null>(null);
  const [retryRequest, setRetryRequest] = useState<SaveRequest | null>(null);
  const sending = useRef(false);
  const saved = useRef(false);
  const memberRequest = useRef(false);
  const [localSubmitting, setLocalSubmitting] = useState(false);
  const submitting = localSubmitting || createTask.isPending || updateTask.isPending;
  const readDenied = detail.isError && ['forbidden', 'missing'].includes(teamTaskErrorKind(detail.error));
  const canEdit = !editing || (detail.data?.data.can_edit === true && !readDenied);
  const needsReview = failureKind === 'stale' || failureKind === 'forbidden' || failureKind === 'missing' || failureKind === 'idempotency';
  const locked = submitting || retryRequest !== null || needsReview || !canEdit || !isCurrentScope() || saved.current;
  const current = useRef({ locked, canEdit, submitting, needsReview });
  current.current = { locked, canEdit, submitting, needsReview };
  const members = useTeamMemberSearch(unit, memberSearch, !locked && !!unit);

  useEffect(() => {
    const task = detail.data?.data;
    if (!editing || !task || initial.current.version !== null || readDenied || !isCurrentScope()) { return; }
    const next = taskDraft(task);
    const member = task.assignee ? { ...task.assignee, role: '' } : null;
    initial.current = { draft: next, snapshot: taskDraftSnapshot(next), version: task.version, member };
    draftRef.current = next;
    setDraft(next);
    setSelectedMember(member);
    setLoadedVersion(task.version);
  }, [detail.data, editing, isCurrentScope, readDenied]);

  useEffect(() => {
    const timer = setTimeout(() => setMemberSearch(memberQuery.trim()), memberQuery.trim() === '' ? 0 : 250);
    return () => clearTimeout(timer);
  }, [memberQuery]);

  useEffect(() => {
    if (!selectedMember || selectedMember.role || !isCurrentScope()) { return; }
    const member = members.data?.pages.flatMap(page => page.data).find(item => item.id === selectedMember.id);
    if (member) { setSelectedMember(member); }
  }, [isCurrentScope, members.data, selectedMember]);

  const visibleMembers = useMemo(() => {
    const result: TeamMember[] = [];
    if (selectedMember && selectedMember.id === draft.assignee_user_id) { result.push(selectedMember); }
    for (const page of members.data?.pages ?? []) {
      for (const member of page.data) {
        if (!result.some(item => item.id === member.id)) { result.push(member); }
      }
    }
    return result;
  }, [draft.assignee_user_id, members.data, selectedMember]);

  const resetDraft = useCallback(() => {
    if (sending.current) { return; }
    if (!isCurrentScope()) {
      draftRef.current = emptyTaskDraft();
      setDraft(draftRef.current);
      setSelectedMember(null);
    } else {
      draftRef.current = initial.current.draft;
      setDraft(initial.current.draft);
      setSelectedMember(initial.current.member);
    }
    checklistTextRef.current = '';
    setChecklistText('');
    setMemberQuery('');
    setMemberSearch('');
    setDatePickerOpen(false);
    pendingSave.current = null;
    setRetryRequest(null);
    setError(null);
    setFailureKind(null);
    resetCreate();
    resetUpdate();
  }, [isCurrentScope, resetCreate, resetUpdate]);

  const dirty = !saved.current && (taskDraftSnapshot(draft) !== initial.current.snapshot || checklistText.length > 0 || retryRequest !== null);
  const { requestLeave, markSaved } = useWorkspaceDraft({
    key: `team-task-form:${unit ?? 'none'}:${id ?? 'new'}`,
    dirty,
    submitting,
    onDiscard: resetDraft,
    warning: retryRequest ? 'The previous request may already have reached the server. Discarding this draft will not undo it. Check the task list before creating another task.' : undefined,
  });

  function leave(action: () => void) {
    if (!isCurrentScope() || sending.current || current.current.submitting || saved.current) { return; }
    requestLeave(action);
  }

  function mayEdit() {
    return isCurrentScope() && !sending.current && !current.current.locked && pendingSave.current === null;
  }

  function patch(value: Partial<TeamTaskDraft>) {
    if (!mayEdit()) { return; }
    const next = { ...draftRef.current, ...value };
    draftRef.current = next;
    setDraft(next);
    if (!current.current.needsReview) { setError(null); setFailureKind(null); }
  }

  function changeChecklistText(value: string) {
    if (!mayEdit()) { return; }
    checklistTextRef.current = value;
    setChecklistText(value);
    if (!current.current.needsReview) { setError(null); }
  }

  function addChecklistItem() {
    if (!mayEdit()) { return; }
    const text = checklistTextRef.current.trim();
    if (text === '' || draftRef.current.checklist.length >= 20) { return; }
    patch({ checklist: [...draftRef.current.checklist, checklistDraftItem({ text, done: false })] });
    checklistTextRef.current = '';
    setChecklistText('');
  }

  function updateChecklist(clientId: string, value: Partial<Pick<ChecklistDraftItem, 'text' | 'done'>>) {
    patch({ checklist: draftRef.current.checklist.map(item => item.clientId === clientId ? { ...item, ...value } : item) });
  }

  async function loadMembers(nextPage: boolean) {
    if (!mayEdit() || members.eligible === false || memberRequest.current || members.isFetching) { return; }
    memberRequest.current = true;
    try {
      if (nextPage) { await members.fetchNextPage({ cancelRefetch: false }); }
      else { await members.refetch({ cancelRefetch: false }); }
    } finally { memberRequest.current = false; }
  }

  async function submit(retry = false) {
    if (!isCurrentScope() || sending.current || current.current.submitting || saved.current || !current.current.canEdit) { return; }
    let request = retry ? pendingSave.current : null;
    if (retry ? !request : pendingSave.current !== null || current.current.needsReview) { return; }
    if (!unit) { setError('Choose a Team Workspace unit first.'); return; }
    if (!retry) {
      const value = draftRef.current;
      if (value.title.trim() === '') { setError('Enter a task title.'); return; }
      if (checklistTextRef.current.length > 0) { setError('Add or clear the new checklist item before saving.'); return; }
      if (value.checklist.some(item => item.text.trim() === '')) { setError('Enter text for each checklist item or remove the empty item.'); return; }
      if (value.due_date && !validDateOnly(value.due_date)) { setError('Choose a valid due date.'); return; }
      const payload = taskDraftInput(value);
      payload.title = payload.title.trim();
      payload.description = payload.description.trim();
      payload.checklist = payload.checklist.map(({ text, done }) => ({ text: text.trim(), done }));
      if (editing) {
        if (initial.current.version === null) { return; }
        request = { kind: 'update', input: { ...payload, version: initial.current.version } };
      } else {
        request = { kind: 'create', input: { ...payload, client_nonce: secureClientNonce() } };
      }
    }
    if (!request) { return; }
    sending.current = true;
    pendingSave.current = request;
    setLocalSubmitting(true);
    setDatePickerOpen(false);
    setError(null);
    try {
      const response = request.kind === 'create'
        ? await createTask.mutateAsync(request.input)
        : await updateTask.mutateAsync(request.input);
      if (!isCurrentScope()) { return; }
      saved.current = true;
      pendingSave.current = null;
      setRetryRequest(null);
      markSaved();
      if (request.kind === 'create') { navigation.replace('TeamTaskDetail', { id: response.data.id }); }
      else { navigation.goBack(); }
    } catch (submitError) {
      if (!isCurrentScope()) { return; }
      const kind = teamTaskErrorKind(submitError);
      if (isAmbiguousTaskSend(submitError) || kind === 'idempotency') {
        setRetryRequest(request);
      } else {
        pendingSave.current = null;
        setRetryRequest(null);
      }
      setFailureKind(kind);
      setError(teamTaskErrorMessage(submitError, 'The task could not be saved.'));
      if (isTaskAccessFailure(submitError)) { reconcileAccess(); }
    } finally {
      sending.current = false;
      if (isCurrentScope()) { setLocalSubmitting(false); }
    }
  }

  function dateChanged(event: DateTimePickerEvent, selected?: Date) {
    if (!mayEdit()) { return; }
    if (Platform.OS === 'android' || event.type === 'dismissed') { setDatePickerOpen(false); }
    if (event.type === 'set' && selected) { patch({ due_date: formatManilaDate(pickerDateToManilaParts(selected)) }); }
  }

  const header = (
    <PageHeader edgeToEdge title={editing ? 'Edit Task' : 'New Task'} subtitle={activeUnit?.label ?? 'Team Workspace'}
      backDisabled={submitting}
      onBack={() => leave(() => navigation.goBack())}
      action={<WorkspaceHeaderActions disabled={submitting} />} />
  );

  if (!isCurrentScope()) {
    return <Screen header={header}><Text style={styles.hint}>This task belongs to the previous workspace. Open it again from the task list.</Text></Screen>;
  }
  if (!unit) {
    return <Screen header={header}><EmptyState icon={<Users size={30} color={theme.brandIcon} />} title="Choose a Team Workspace unit" body="Select a unit before creating a task." action={<Action full onPress={openUnitPicker}>Choose unit</Action>} /></Screen>;
  }
  if (editing && !readDenied && !detail.data?.data && contentState === 'loading' && !paused && detail.eligible !== false) {
    return <Screen header={header}><Skeleton label="Loading task for editing" variant="form" /></Screen>;
  }
  if (editing && !readDenied && !detail.data?.data && !detail.isError) {
    return <Screen header={header}><EmptyState icon={<Users size={30} color={theme.brandIcon} />}
      title={paused ? 'You are offline' : 'Task is waiting to load'}
      body={paused ? 'Reconnect to load this task for editing.' : 'Return to this Team Workspace while connected to load the original task.'}
      action={<Action full variant="secondary" disabled={detail.eligible === false || paused} onPress={() => { detail.refetch().catch(() => undefined); }}>Try again</Action>} /></Screen>;
  }
  if (editing && !readDenied && detail.data?.data && loadedVersion === null) {
    return <Screen header={header}><Skeleton label="Preparing task form" variant="form" active={false} /></Screen>;
  }
  if (editing && (readDenied || !detail.data?.data || loadedVersion === null)) {
    return <Screen header={header}>
      <EmptyState icon={<Users size={30} color={theme.brandIcon} />} title="Task unavailable for editing" body={teamTaskErrorMessage(detail.error, 'The task could not be loaded for editing.')} action={<Action full variant="secondary" disabled={submitting || detail.eligible === false} loading={detail.isFetching} onPress={() => { if (!sending.current && isCurrentScope()) { detail.refetch().catch(() => undefined); } }}>Try again</Action>} />
    </Screen>;
  }

  const footer = (
    <View style={styles.actions}>
      {retryRequest && failureKind !== 'idempotency' ? (
        <Action full loading={submitting} disabled={!canEdit} onPress={() => { submit(true); }}>Retry original request</Action>
      ) : needsReview ? (
        <Action full variant="secondary" disabled={submitting} onPress={() => leave(() => navigation.goBack())}>{editing ? 'Review latest task' : 'Review task list'}</Action>
      ) : (
        <Action full loading={submitting} disabled={!canEdit} onPress={() => { submit(); }}>{editing ? 'Save Changes' : 'Create Task'}</Action>
      )}
      <Action full variant="ghost" disabled={submitting} onPress={() => leave(() => navigation.goBack())}>Cancel</Action>
    </View>
  );

  return (
    <Screen header={header} keyboardAware bottomSafeArea footer={footer}>
      <Text style={styles.intro}>Keep the team aligned with a clear task, an optional assignee, and a checklist.</Text>
      {!canEdit ? <Text accessibilityRole="alert" style={styles.error}>You can no longer edit this task. Your draft is still here; return to the task to review your access.</Text> : null}
      {detail.isError && !readDenied ? <Text accessibilityRole="alert" style={styles.error}>Could not refresh task details. Your original draft and version have been kept.</Text> : null}
      {editing && detail.data?.data.version !== loadedVersion ? <Text accessibilityRole="alert" style={styles.hint}>This task has a newer version. Your original draft has not been replaced.</Text> : null}
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      {retryRequest ? <Text accessibilityRole="alert" style={styles.hint}>The previous request may have been received. Your exact request is kept unchanged. Retry it explicitly, or cancel and check the task before sending anything new.</Text> : null}

      <TextField label="Title" accessibilityLabel="Task title" value={draft.title} onChangeText={title => patch({ title })} maxLength={160} editable={!locked} />
      <TextField label="Description" accessibilityLabel="Task description" value={draft.description} onChangeText={description => patch({ description })} multiline maxLength={4000} editable={!locked} style={styles.textarea} />
      <ChoiceGroup label="Status" values={TASK_STATUSES} value={draft.status} labelFor={value => STATUS_PRESENTATION[value].label} onChange={status => patch({ status })} disabled={locked} />
      <ChoiceGroup label="Priority" values={TASK_PRIORITIES} value={draft.priority} labelFor={value => PRIORITY_LABEL[value]} onChange={priority => patch({ priority })} disabled={locked} />

      <Text accessibilityRole="header" style={styles.sectionLabel}>DUE DATE</Text>
      <View style={styles.dateRow}>
        <Pressable accessibilityRole="button" accessibilityLabel="Choose due date" accessibilityHint="Optional date in Asia/Manila" accessibilityState={{ disabled: locked }} disabled={locked}
          onPress={() => { if (mayEdit()) { setDatePickerOpen(true); } }} style={({ pressed }) => [styles.dateButton, pressed && styles.pressed, locked && styles.disabled]}>
          <CalendarDays size={18} color={theme.brandIcon} />
          <Text style={styles.dateLabel}>{draft.due_date ? formatManilaDayLabel(draft.due_date) : 'No due date'}</Text>
        </Pressable>
        {draft.due_date ? <IconAction label="Clear due date" icon={<X size={18} color={theme.ink700} />} disabled={locked} onPress={() => patch({ due_date: null })} /> : null}
      </View>
      <Text style={styles.hint}>Optional · Asia/Manila date</Text>
      {datePickerOpen && !locked ? <View style={styles.picker}>
        <DateTimePicker value={manilaWallToPickerDate(`${draft.due_date ?? todayInManila()}T12:00`)} mode="date" timeZoneName="Asia/Manila" display={Platform.OS === 'ios' ? 'spinner' : 'default'} onChange={dateChanged} />
        {Platform.OS === 'ios' ? <Action variant="secondary" onPress={() => { if (mayEdit()) { setDatePickerOpen(false); } }}>Done</Action> : null}
      </View> : null}

      <Text accessibilityRole="header" style={styles.sectionLabel}>ASSIGNEE</Text>
      <TextField label="Search Members" accessibilityLabel="Search members" value={memberQuery} maxLength={100} onChangeText={value => { if (mayEdit()) { setMemberQuery(value); } }} placeholder="Search by name" editable={!locked} />
      <View accessibilityRole="radiogroup" accessibilityLabel="Task assignee" style={styles.options}>
        <MemberOption member={null} selected={draft.assignee_user_id === null} disabled={locked} onPress={() => { if (mayEdit()) { setSelectedMember(null); patch({ assignee_user_id: null }); } }} />
        {visibleMembers.map(member => <MemberOption key={member.id} member={member} selected={draft.assignee_user_id === member.id} disabled={locked} onPress={() => { if (mayEdit()) { setSelectedMember(member); patch({ assignee_user_id: member.id }); } }} />)}
      </View>
      {members.isFetching || memberQuery.trim() !== memberSearch ? <ActivityIndicator style={styles.memberLoader} accessibilityLabel="Loading members" color={theme.brandIcon} /> : null}
      {members.isError ? <View style={styles.memberFeedback}>
        <Text accessibilityRole="alert" style={styles.error}>Could not load {members.isFetchNextPageError ? 'more members' : 'members'}. Your selected assignee is kept.</Text>
        <Action variant="secondary" disabled={locked || members.eligible === false} loading={members.isFetching} onPress={() => { loadMembers(members.isFetchNextPageError).catch(() => undefined); }}>Retry members</Action>
      </View> : null}
      {!members.isFetching && !members.isError && members.data && members.data.pages.every(page => page.data.length === 0) ? <Text style={styles.hint}>No members match this search.</Text> : null}
      {members.hasNextPage && !members.isFetchNextPageError ? <Action variant="secondary" disabled={locked || members.eligible === false || members.isFetching} loading={members.isFetchingNextPage} onPress={() => { loadMembers(true).catch(() => undefined); }}>Load more members</Action> : null}

      <Text accessibilityRole="header" style={styles.sectionLabel}>CHECKLIST · {draft.checklist.length}/20</Text>
      {draft.checklist.length === 0 ? <Text style={styles.hint}>Break the task into smaller steps.</Text> : null}
      {draft.checklist.map((item, index) => (
        <View key={item.clientId} style={styles.checklistRow}>
          <Pressable accessibilityRole="checkbox" accessibilityLabel={`Complete checklist item ${index + 1}`} accessibilityState={{ checked: item.done, disabled: locked }} disabled={locked}
            onPress={() => { const value = draftRef.current.checklist.find(row => row.clientId === item.clientId); if (value) { updateChecklist(item.clientId, { done: !value.done }); } }}
            style={({ pressed }) => [styles.check, item.done && styles.checkSelected, pressed && styles.pressed, locked && styles.disabled]}>
            {item.done ? <Check size={16} color="#ffffff" /> : null}
          </Pressable>
          <View style={styles.flexField}><TextField label={`Item ${index + 1}`} accessibilityLabel={`Checklist item ${index + 1}`} value={item.text} onChangeText={text => updateChecklist(item.clientId, { text })} maxLength={160} editable={!locked} /></View>
          <Pressable accessibilityRole="button" accessibilityLabel={`Remove checklist item ${index + 1}`} accessibilityState={{ disabled: locked }} disabled={locked}
            onPress={() => patch({ checklist: draftRef.current.checklist.filter(row => row.clientId !== item.clientId) })} style={({ pressed }) => [styles.remove, pressed && styles.pressed, locked && styles.disabled]}>
            <Trash2 size={18} color={theme.mode === 'dark' ? theme.ink700 : '#a33b3b'} />
          </Pressable>
        </View>
      ))}
      {draft.checklist.length < 20 ? <View style={styles.checklistRow}>
        <View style={styles.flexField}><TextField label="New Checklist Item" accessibilityLabel="New checklist item" value={checklistText} onChangeText={changeChecklistText} onSubmitEditing={addChecklistItem} maxLength={160} editable={!locked} returnKeyType="done" /></View>
        <Pressable accessibilityRole="button" accessibilityLabel="Add checklist item" accessibilityState={{ disabled: locked || checklistText.trim() === '' }} disabled={locked || checklistText.trim() === ''} onPress={addChecklistItem}
          style={({ pressed }) => [styles.check, pressed && styles.pressed, (locked || checklistText.trim() === '') && styles.disabled]}><Plus size={19} color={theme.brandIcon} /></Pressable>
      </View> : <Text style={styles.hint}>Maximum of 20 checklist items reached.</Text>}
    </Screen>
  );
}

function ChoiceGroup<T extends TeamTaskStatus | TeamTaskPriority>({ label, values, value, labelFor, onChange, disabled }: {
  label: string; values: readonly T[]; value: T; labelFor: (value: T) => string; onChange: (value: T) => void; disabled: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <View>
    <Text accessibilityRole="header" style={styles.sectionLabel}>{label.toUpperCase()}</Text>
    <View accessibilityRole="radiogroup" accessibilityLabel={label} style={styles.choiceRow}>
      {values.map(option => <Pressable key={option} accessibilityRole="radio" accessibilityLabel={`${label}: ${labelFor(option)}`} accessibilityState={{ checked: value === option, disabled }} disabled={disabled} onPress={() => onChange(option)}
        style={({ pressed }) => [styles.choice, value === option && styles.choiceSelected, pressed && styles.pressed, disabled && styles.disabled]}><Text style={[styles.choiceText, value === option && styles.choiceTextSelected]}>{labelFor(option)}</Text></Pressable>)}
    </View>
  </View>;
}

function MemberOption({ member, selected, onPress, disabled }: { member: TeamMember | null; selected: boolean; onPress: () => void; disabled: boolean }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <Pressable accessibilityRole="radio" accessibilityLabel={member ? `Assign to ${member.name}` : 'Unassigned'} accessibilityState={{ checked: selected, disabled }} disabled={disabled} onPress={onPress}
    style={({ pressed }) => [styles.memberOption, pressed && styles.pressed, disabled && styles.disabled]}>
    <View style={[styles.radio, selected && styles.radioSelected]}>{selected ? <Check size={11} color="#ffffff" /> : null}</View>
    <View style={styles.memberCopy}><Text style={styles.memberName}>{member?.name ?? 'Unassigned'}</Text>{member?.role ? <Text style={styles.memberRole}>{member.role.replace(/_/g, ' ')}</Text> : null}</View>
  </Pressable>;
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    center: { alignItems: 'center', justifyContent: 'center' },
    intro: { ...type.body, color: theme.ink500, lineHeight: 18 },
    textarea: { minHeight: 100, paddingTop: 12, textAlignVertical: 'top' },
    sectionLabel: { marginTop: 20, marginBottom: 8, color: theme.ink500, fontSize: 9.5, fontWeight: '800', letterSpacing: 1.1, fontFamily: font.body },
    choiceRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    choice: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 13, borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    choiceSelected: { borderColor: theme.brand, backgroundColor: theme.brand },
    choiceText: { ...type.bodyStrong, color: theme.ink700 }, choiceTextSelected: { color: '#ffffff' },
    options: { marginTop: 10, overflow: 'hidden', borderRadius: 14, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    memberOption: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 12, borderBottomWidth: 1, borderBottomColor: theme.line },
    radio: { width: 20, height: 20, alignItems: 'center', justifyContent: 'center', borderRadius: 10, borderWidth: 1, borderColor: theme.ink400 }, radioSelected: { borderColor: theme.brand, backgroundColor: theme.brand },
    memberCopy: { flex: 1 }, memberName: { ...type.bodyStrong, color: theme.ink900 }, memberRole: { ...type.caption, marginTop: 2, color: theme.ink500, textTransform: 'capitalize' },
    memberLoader: { marginVertical: 10 }, memberFeedback: { gap: 8 },
    checklistRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 8 },
    check: { width: 44, height: 46, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface }, checkSelected: { borderColor: theme.brand, backgroundColor: theme.brand },
    flexField: { flex: 1, minWidth: 0 }, remove: { width: 44, height: 46, alignItems: 'center', justifyContent: 'center', borderRadius: 12 },
    dateRow: { flexDirection: 'row', alignItems: 'center', gap: 10 }, dateButton: { flex: 1, minHeight: 46, flexDirection: 'row', alignItems: 'center', gap: 9, paddingHorizontal: 12, borderWidth: 1, borderRadius: 12, borderColor: theme.line, backgroundColor: theme.surfaceWarm }, dateLabel: { ...type.bodyStrong, flex: 1, color: theme.ink700 }, picker: { marginTop: 8 },
    error: { ...type.bodyStrong, marginTop: 12, color: theme.mode === 'dark' ? '#f2a7a7' : '#a33b3b' }, hint: { ...type.caption, marginTop: 6, lineHeight: 16, color: theme.ink500 },
    actions: { gap: 4 },
    pressed: { opacity: 0.7 }, disabled: { opacity: 0.5 },
  });
}
const makeStyles = memoizeStyles(createStyles);
