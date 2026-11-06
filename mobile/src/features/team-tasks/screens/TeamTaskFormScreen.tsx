import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { Check, Plus, Trash2, Users } from 'lucide-react-native';
import type {
  TeamMember,
  TeamTaskChecklistItem,
  TeamTaskEditableInput,
  TeamTaskPriority,
  TeamTaskStatus,
} from '../../../api/endpoints/teamTasks';
import { Action } from '../../../components/Action';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { TextField } from '../../../components/TextField';
import { secureClientNonce } from '../../../lib/secureNonce';
import type { TeamTasksStackParamList } from '../../../navigation/types';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { teamTaskErrorKind, teamTaskErrorMessage } from '../errors';
import { useCreateTeamTask, useTeamMemberSearch, useTeamTask, useUpdateTeamTask } from '../hooks';
import { PRIORITY_LABEL, STATUS_PRESENTATION, TASK_PRIORITIES, TASK_STATUSES, validDateOnly } from '../model';

type FormRoute = RouteProp<TeamTasksStackParamList, 'TeamTaskForm'>;
type Navigation = NativeStackNavigationProp<TeamTasksStackParamList>;

type Draft = TeamTaskEditableInput;

const EMPTY_DRAFT: Draft = {
  title: '',
  description: '',
  status: 'todo',
  priority: 'normal',
  assignee_user_id: null,
  due_date: null,
  checklist: [],
};

export function TeamTaskFormScreen() {
  const route = useRoute<FormRoute>();
  const navigation = useNavigation<Navigation>();
  const id = route.params?.id;
  const editing = id !== undefined;
  const { activeUnit, openUnitPicker, registerDirtyDraft, requestDiscard } = useWorkspace();
  const unit = activeUnit?.unit ?? null;
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const detail = useTeamTask(unit, id ?? 0);
  const searchMembers = useTeamMemberSearch(unit);
  const createTask = useCreateTeamTask(unit);
  const updateTask = useUpdateTeamTask(unit, id ?? 0);
  const [draft, setDraft] = useState<Draft>(EMPTY_DRAFT);
  const [loadedVersion, setLoadedVersion] = useState<number | null>(null);
  const [memberQuery, setMemberQuery] = useState('');
  const [memberResults, setMemberResults] = useState<TeamMember[]>([]);
  const [memberSearching, setMemberSearching] = useState(false);
  const [checklistText, setChecklistText] = useState('');
  const [error, setError] = useState<string | null>(null);
  const pendingCreate = useRef<{ snapshot: string; nonce: string } | null>(null);

  useEffect(() => {
    const task = detail.data?.data;
    if (!editing || !task || loadedVersion !== null) { return; }
    setDraft({
      title: task.title,
      description: task.description,
      status: task.status,
      priority: task.priority,
      assignee_user_id: task.assignee?.id ?? null,
      due_date: task.due_date,
      checklist: task.checklist,
    });
    setLoadedVersion(task.version);
  }, [detail.data, editing, loadedVersion]);

  const initialSnapshot = useMemo(() => {
    if (!editing) { return JSON.stringify(EMPTY_DRAFT); }
    const task = detail.data?.data;
    return task ? JSON.stringify({
      title: task.title,
      description: task.description,
      status: task.status,
      priority: task.priority,
      assignee_user_id: task.assignee?.id ?? null,
      due_date: task.due_date,
      checklist: task.checklist,
    }) : JSON.stringify(EMPTY_DRAFT);
  }, [detail.data, editing]);
  const snapshot = JSON.stringify(draft);
  const dirty = snapshot !== initialSnapshot;

  useEffect(() => dirty ? registerDirtyDraft(`team-task-form:${unit ?? 'none'}:${id ?? 'new'}`) : undefined,
    [dirty, id, registerDirtyDraft, unit]);
  useEffect(() => navigation.addListener('beforeRemove', event => {
    if (!dirty || createTask.isPending || updateTask.isPending) { return; }
    event.preventDefault();
    requestDiscard(() => navigation.dispatch(event.data.action));
  }), [createTask.isPending, dirty, navigation, requestDiscard, updateTask.isPending]);

  useEffect(() => {
    let cancelled = false;
    setMemberSearching(true);
    const timer = setTimeout(() => {
      searchMembers(memberQuery).then(results => {
        if (!cancelled) { setMemberResults(results); }
      }).catch(() => {
        if (!cancelled) { setMemberResults([]); }
      }).finally(() => {
        if (!cancelled) { setMemberSearching(false); }
      });
    }, memberQuery.trim() === '' ? 0 : 250);
    return () => { cancelled = true; clearTimeout(timer); };
  }, [memberQuery, searchMembers]);

  const selectedMember = detail.data?.data.assignee;
  const visibleMembers = selectedMember && !memberResults.some(member => member.id === selectedMember.id)
    ? [{ id: selectedMember.id, name: selectedMember.name, role: '' }, ...memberResults]
    : memberResults;

  function patch(value: Partial<Draft>) {
    setDraft(current => ({ ...current, ...value }));
    setError(null);
    if (pendingCreate.current?.snapshot !== JSON.stringify({ ...draft, ...value })) {
      pendingCreate.current = null;
      createTask.reset();
    }
  }

  function addChecklistItem() {
    const text = checklistText.trim();
    if (text === '' || draft.checklist.length >= 20) { return; }
    patch({ checklist: [...draft.checklist, { text, done: false }] });
    setChecklistText('');
  }

  function updateChecklist(index: number, value: TeamTaskChecklistItem) {
    patch({ checklist: draft.checklist.map((item, itemIndex) => itemIndex === index ? value : item) });
  }

  async function submit() {
    const title = draft.title.trim();
    if (!unit) { setError('Choose a Team Workspace unit first.'); return; }
    if (title === '') { setError('Enter a task title.'); return; }
    if (draft.due_date && !validDateOnly(draft.due_date)) { setError('Due date must be a valid YYYY-MM-DD date.'); return; }
    const payload: Draft = { ...draft, title, description: draft.description.trim() };
    setError(null);
    try {
      if (editing) {
        if (loadedVersion === null) { return; }
        await updateTask.mutateAsync({ ...payload, version: loadedVersion });
      } else {
        const payloadSnapshot = JSON.stringify(payload);
        const pending = pendingCreate.current?.snapshot === payloadSnapshot
          ? pendingCreate.current
          : { snapshot: payloadSnapshot, nonce: secureClientNonce() };
        pendingCreate.current = pending;
        const response = await createTask.mutateAsync({ ...payload, client_nonce: pending.nonce });
        pendingCreate.current = null;
        navigation.replace('TeamTaskDetail', { id: response.data.id });
        return;
      }
      navigation.goBack();
    } catch (submitError) {
      const kind = teamTaskErrorKind(submitError);
      if (kind === 'stale') {
        setError('This task changed elsewhere. Return to the latest task details before editing again.');
      } else {
        setError(teamTaskErrorMessage(submitError, 'The task could not be saved.'));
      }
    }
  }

  const loading = editing && detail.isLoading;
  const submitting = createTask.isPending || updateTask.isPending;
  const header = (
    <PageHeader
      edgeToEdge
      title={editing ? 'Edit Task' : 'New Task'}
      subtitle={activeUnit?.label ?? 'Team Workspace'}
      onBack={() => requestDiscard(navigation.goBack)}
      action={(
        <Pressable accessibilityRole="button" accessibilityLabel="Switch Team Workspace unit" onPress={() => requestDiscard(openUnitPicker)} style={styles.headerButton}>
          <Users size={19} color={theme.brandIcon} />
        </Pressable>
      )}
    />
  );

  if (loading) {
    return <Screen scroll={false} header={header} bodyStyle={styles.center}><ActivityIndicator color={theme.brandIcon} /></Screen>;
  }

  if (editing && (detail.isError || !detail.data?.data)) {
    return (
      <Screen header={header}>
        <Text accessibilityRole="alert" style={styles.error}>{teamTaskErrorMessage(detail.error, 'The task could not be loaded for editing.')}</Text>
        <Action full variant="secondary" onPress={() => { detail.refetch().catch(() => undefined); }}>Try Again</Action>
      </Screen>
    );
  }

  return (
    <Screen header={header}>
      <TextField label="Title" value={draft.title} onChangeText={title => patch({ title })} maxLength={160} editable={!submitting} />
      <TextField label="Description" value={draft.description} onChangeText={description => patch({ description })} multiline maxLength={4000} editable={!submitting} style={styles.textarea} />
      <ChoiceGroup label="Status" values={TASK_STATUSES} value={draft.status} labelFor={value => STATUS_PRESENTATION[value].label} onChange={status => patch({ status })} disabled={submitting} />
      <ChoiceGroup label="Priority" values={TASK_PRIORITIES} value={draft.priority} labelFor={value => PRIORITY_LABEL[value]} onChange={priority => patch({ priority })} disabled={submitting} />
      <TextField label="Due Date" hint="Optional, Asia/Manila date in YYYY-MM-DD format" value={draft.due_date ?? ''} onChangeText={value => patch({ due_date: value.trim() === '' ? null : value })} placeholder="YYYY-MM-DD" editable={!submitting} />

      <Text style={styles.sectionLabel}>ASSIGNEE</Text>
      <TextField label="Search Members" value={memberQuery} onChangeText={setMemberQuery} placeholder="Search by name" editable={!submitting} hint={memberSearching ? 'Searching members…' : undefined} />
      <View accessibilityRole="radiogroup" style={styles.options}>
        <MemberOption member={null} selected={draft.assignee_user_id === null} onPress={() => patch({ assignee_user_id: null })} />
        {visibleMembers.map(member => <MemberOption key={member.id} member={member} selected={draft.assignee_user_id === member.id} onPress={() => patch({ assignee_user_id: member.id })} />)}
      </View>

      <Text style={styles.sectionLabel}>CHECKLIST</Text>
      {draft.checklist.map((item, index) => (
        <View key={`${index}:${item.text}`} style={styles.checklistRow}>
          <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: item.done }} onPress={() => updateChecklist(index, { ...item, done: !item.done })} style={[styles.check, item.done && styles.checkSelected]}>
            {item.done ? <Check size={14} color="#ffffff" /> : null}
          </Pressable>
          <TextField label={`Item ${index + 1}`} value={item.text} onChangeText={text => updateChecklist(index, { ...item, text })} maxLength={160} editable={!submitting} style={styles.checklistInput} />
          <Pressable accessibilityRole="button" accessibilityLabel={`Remove checklist item ${index + 1}`} onPress={() => patch({ checklist: draft.checklist.filter((_, itemIndex) => itemIndex !== index) })} style={styles.remove}>
            <Trash2 size={18} color="#a33b3b" />
          </Pressable>
        </View>
      ))}
      {draft.checklist.length < 20 ? (
        <View style={styles.addRow}>
          <TextField label="New Checklist Item" value={checklistText} onChangeText={setChecklistText} maxLength={160} editable={!submitting} style={styles.addInput} />
          <Pressable accessibilityRole="button" accessibilityLabel="Add checklist item" onPress={addChecklistItem} style={styles.addButton}><Plus size={19} color={theme.brandIcon} /></Pressable>
        </View>
      ) : null}

      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      <View style={styles.actions}>
        <Action full loading={submitting} onPress={() => { submit().catch(() => undefined); }}>{editing ? 'Save Changes' : 'Create Task'}</Action>
        <Action full variant="ghost" disabled={submitting} onPress={() => requestDiscard(navigation.goBack)}>Cancel</Action>
      </View>
    </Screen>
  );
}

function ChoiceGroup<T extends TeamTaskStatus | TeamTaskPriority>({ label, values, value, labelFor, onChange, disabled }: {
  label: string;
  values: readonly T[];
  value: T;
  labelFor: (value: T) => string;
  onChange: (value: T) => void;
  disabled: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View>
      <Text style={styles.sectionLabel}>{label.toUpperCase()}</Text>
      <View accessibilityRole="radiogroup" style={styles.choiceRow}>
        {values.map(option => {
          const selected = value === option;
          return (
            <Pressable key={option} accessibilityRole="radio" accessibilityState={{ checked: selected, disabled }} disabled={disabled} onPress={() => onChange(option)} style={[styles.choice, selected && styles.choiceSelected]}>
              <Text style={[styles.choiceText, selected && styles.choiceTextSelected]}>{labelFor(option)}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

function MemberOption({ member, selected, onPress }: { member: TeamMember | null; selected: boolean; onPress: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Pressable accessibilityRole="radio" accessibilityState={{ checked: selected }} onPress={onPress} style={styles.memberOption}>
      <View style={[styles.radio, selected && styles.radioSelected]}>{selected ? <Check size={11} color="#ffffff" /> : null}</View>
      <View style={styles.memberCopy}>
        <Text style={styles.memberName}>{member?.name ?? 'Unassigned'}</Text>
        {member ? <Text style={styles.memberRole}>{member.role.replace(/_/g, ' ')}</Text> : null}
      </View>
    </Pressable>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    center: { alignItems: 'center', justifyContent: 'center' },
    headerButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    textarea: { minHeight: 100, paddingTop: 12, textAlignVertical: 'top' },
    sectionLabel: { marginTop: 18, marginBottom: 8, color: theme.ink500, fontSize: 9, fontWeight: '800', letterSpacing: 0.9, fontFamily: font.body },
    choiceRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    choice: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 13, borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    choiceSelected: { borderColor: theme.brand, backgroundColor: theme.brand },
    choiceText: { ...type.bodyStrong, color: theme.ink700 }, choiceTextSelected: { color: '#ffffff' },
    options: { overflow: 'hidden', borderRadius: 14, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    memberOption: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 12, borderBottomWidth: 1, borderBottomColor: theme.line },
    radio: { width: 20, height: 20, alignItems: 'center', justifyContent: 'center', borderRadius: 10, borderWidth: 1, borderColor: theme.ink400 }, radioSelected: { borderColor: theme.brand, backgroundColor: theme.brand },
    memberCopy: { flex: 1 }, memberName: { ...type.bodyStrong, color: theme.ink900 }, memberRole: { ...type.caption, marginTop: 2, color: theme.ink500, textTransform: 'capitalize' },
    checklistRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 8 },
    check: { width: 44, height: 46, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface }, checkSelected: { borderColor: theme.brand, backgroundColor: theme.brand },
    checklistInput: { minWidth: 0 }, remove: { width: 44, height: 46, alignItems: 'center', justifyContent: 'center' },
    addRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 8 }, addInput: { minWidth: 0 }, addButton: { width: 46, height: 46, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    error: { ...type.bodyStrong, marginTop: 14, color: '#a33b3b' }, actions: { gap: 7, marginTop: 22 },
  });
}

const makeStyles = memoizeStyles(createStyles);
