import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, SectionList, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { AlertTriangle, CheckSquare2, Plus, Search, Users, X } from 'lucide-react-native';
import type { TeamTaskFilters, TeamTaskPriority, TeamTaskStatus, TeamTaskSummary } from '../../../api/endpoints/teamTasks';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { IconAction } from '../../../components/IconAction';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { StatusPill } from '../../../components/StatusPill';
import { TextField } from '../../../components/TextField';
import { useAuth } from '../../../store/auth';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { WorkspaceHeaderActions } from '../../workspace/WorkspaceHeaderActions';
import type { MyTasksStackParamList, TeamTasksStackParamList } from '../../../navigation/types';
import { useTeamTasks, useTeamTaskAccessRecovery } from '../hooks';
import { groupTasks, PRIORITY_LABEL, STATUS_PRESENTATION, TASK_PRIORITIES, TASK_STATUSES } from '../model';
import { teamTaskErrorKind, teamTaskErrorMessage } from '../errors';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatManilaDayLabel } from '../../team-calendar/manila';

interface Props { scope: 'team' | 'mine' }
type Navigation = NativeStackNavigationProp<TeamTasksStackParamList | MyTasksStackParamList>;

export function TeamTaskListScreen({ scope }: Props) {
  const { activeUnit, openUnitPicker } = useWorkspace();
  const { user } = useAuth();
  const unit = activeUnit?.unit ?? null;
  const navigation = useNavigation<Navigation>();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const [draftSearch, setDraftSearch] = useState('');
  const draftSearchRef = useRef('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<TeamTaskStatus | 'all'>('all');
  const [priority, setPriority] = useState<TeamTaskPriority | 'all'>('all');
  const [page, setPage] = useState(1);
  const [refreshing, setRefreshing] = useState(false);
  const refreshingRef = useRef(false);
  const filters: TeamTaskFilters = { scope, q: search, status, priority, page };
  const query = useTeamTasks(unit, filters);
  useTeamTaskAccessRecovery(unit, query.isError ? query.error : null);
  const forbidden = query.isError && teamTaskErrorKind(query.error) === 'forbidden';
  const data = useMemo(() => forbidden ? [] : query.data?.data ?? [], [forbidden, query.data]);
  const meta = forbidden ? undefined : query.data?.meta;
  const sections = useMemo(() => groupTasks(data), [data]);
  const filtered = search !== '' || status !== 'all' || priority !== 'all';
  const canReset = filtered || draftSearch !== '' || page !== 1;
  const scopeKey = `${user?.tenant_id ?? 0}:${user?.id ?? 0}:${unit ?? 'none'}:${scope}`;
  const lifetime = useRef({ key: scopeKey, mounted: true });
  lifetime.current.key = scopeKey;
  useEffect(() => {
    const screen = lifetime.current;
    screen.mounted = true;
    return () => { screen.mounted = false; };
  }, []);

  useEffect(() => {
    draftSearchRef.current = '';
    setDraftSearch('');
    setSearch('');
    setStatus('all');
    setPriority('all');
    refreshingRef.current = false;
    setRefreshing(false);
    setPage(1);
  }, [scopeKey]);

  function isCurrentScope() { return lifetime.current.mounted && lifetime.current.key === scopeKey; }
  function resetFilters() {
    if (!isCurrentScope()) { return; }
    draftSearchRef.current = '';
    setDraftSearch('');
    setSearch('');
    setStatus('all');
    setPriority('all');
    setPage(1);
  }
  function searchTasks() { if (isCurrentScope()) { setSearch(draftSearchRef.current.trim()); setPage(1); } }
  function clearSearch() { if (isCurrentScope()) { draftSearchRef.current = ''; setDraftSearch(''); setSearch(''); setPage(1); } }
  async function refresh(pull = false) {
    if (!isCurrentScope() || query.eligible === false || refreshingRef.current) { return; }
    refreshingRef.current = true;
    if (pull) { setRefreshing(true); }
    try { await query.refetch({ cancelRefetch: false }); }
    finally { if (isCurrentScope()) { refreshingRef.current = false; setRefreshing(false); } }
  }
  function createTask() { if (isCurrentScope() && unit && !forbidden) { navigation.navigate('TeamTaskForm', undefined); } }

  const header = <PageHeader edgeToEdge title={scope === 'mine' ? 'My Tasks' : 'Team Board'} subtitle={activeUnit?.label ?? 'Choose a Team Workspace'}
    action={<WorkspaceHeaderActions root>{unit && !forbidden ? <IconAction label="Create task" onPress={createTask} icon={<Plus size={20} color={theme.brandIcon} />} /> : null}</WorkspaceHeaderActions>} />;

  if (!unit) {
    return <Screen header={header}><EmptyState icon={<Users size={30} color={theme.brandIcon} />} title="Choose a Team Workspace unit" body="Select a unit to see its tasks." action={<Action full onPress={openUnitPicker}>Choose unit</Action>} /></Screen>;
  }

  const listHeader = <View>
    <View style={styles.resultSummary}>
      <Text accessibilityLiveRegion="polite" style={styles.resultText}>{meta ? `${meta.total} ${meta.total === 1 ? 'task' : 'tasks'}` : 'Tasks'}</Text>
      {query.isFetching && !query.isLoading && !refreshing ? <View style={styles.refreshStatus}><ActivityIndicator size="small" accessibilityLabel="Updating task results" color={theme.brandIcon} /><Text style={styles.resultText}>{query.isPlaceholderData ? 'Loading results…' : 'Updating…'}</Text></View> : null}
    </View>
    <View style={styles.searchRow}>
      <View style={styles.searchField}><TextField label="Search tasks" accessibilityLabel="Search tasks" placeholder="Search by title or description" value={draftSearch} maxLength={100}
        onChangeText={value => { if (isCurrentScope()) { draftSearchRef.current = value; setDraftSearch(value); } }} onSubmitEditing={searchTasks} returnKeyType="search"
        trailing={draftSearch !== '' || search !== '' ? <Pressable accessibilityRole="button" accessibilityLabel="Clear task search" onPress={clearSearch} style={({ pressed }) => [styles.clearSearch, pressed && styles.pressed]}><X size={17} color={theme.ink500} /></Pressable> : undefined} /></View>
      <Pressable accessibilityRole="button" accessibilityLabel="Search" onPress={searchTasks} style={({ pressed }) => [styles.searchButton, pressed && styles.pressed]}><Search size={19} color={theme.brandIcon} /></Pressable>
    </View>
    <View accessibilityRole="radiogroup" accessibilityLabel="Filter by status" style={styles.filters}>
      {(['all', ...TASK_STATUSES] as const).map(value => <FilterChip key={value} accessibilityLabel={`Status filter: ${value === 'all' ? 'All' : STATUS_PRESENTATION[value].label}`}
        label={value === 'all' ? `All ${meta?.counts.total ?? ''}`.trim() : `${STATUS_PRESENTATION[value].label} ${meta?.counts[value] ?? ''}`.trim()} selected={status === value} onPress={() => { if (isCurrentScope()) { setStatus(value); setPage(1); } }} />)}
    </View>
    <View accessibilityRole="radiogroup" accessibilityLabel="Filter by priority" style={styles.filters}>
      {(['all', ...TASK_PRIORITIES] as const).map(value => <FilterChip key={value} accessibilityLabel={`Priority filter: ${value === 'all' ? 'All' : PRIORITY_LABEL[value]}`} label={value === 'all' ? 'All priorities' : PRIORITY_LABEL[value]} selected={priority === value} onPress={() => { if (isCurrentScope()) { setPriority(value); setPage(1); } }} />)}
    </View>
    {canReset ? <Action variant="ghost" accessibilityLabel="Reset task filters" onPress={resetFilters}>Reset filters</Action> : null}
    {query.isPlaceholderData ? <Text style={styles.previousResults}>Showing the previous results until this page finishes loading.</Text> : null}
    {query.isError ? <View style={styles.errorBox}>
      <Text accessibilityRole="alert" style={styles.feedback}>{forbidden ? 'You no longer have access to this Team Workspace. Your access is being checked.' : teamTaskErrorMessage(query.error, data.length > 0 ? 'Could not refresh. Showing the last available tasks.' : 'Could not load tasks.')}</Text>
      <Action variant="secondary" disabled={query.eligible === false} loading={query.isFetching} onPress={() => { refresh().catch(() => undefined); }}>Retry</Action>
    </View> : null}
  </View>;

  const empty = query.isLoading ? <ActivityIndicator accessibilityLabel="Loading tasks" style={styles.loader} color={theme.brandIcon} /> : !query.isError ? <EmptyState
    icon={<CheckSquare2 size={30} color={theme.brandIcon} />}
    title={filtered ? 'No matching tasks' : scope === 'mine' ? 'No tasks assigned to you' : 'No team tasks yet'}
    body={filtered ? 'Try another search or reset the filters to see all tasks.' : scope === 'mine' ? 'Tasks assigned to you will appear here. New tasks stay unassigned unless you choose an assignee.' : 'Create an operational task to keep your team aligned.'}
    action={<View style={styles.emptyActions}>
      {filtered ? <Action full onPress={resetFilters}>Reset filters</Action> : <Action full onPress={createTask}>Create task</Action>}
      <Action full variant="secondary" disabled={query.eligible === false} loading={query.isFetching} onPress={() => { refresh(true).catch(() => undefined); }}>Refresh tasks</Action>
    </View>} /> : null;

  return <Screen scroll={false} bodyStyle={styles.screen} header={header}>
    <SectionList sections={sections} keyExtractor={item => String(item.id)} keyboardShouldPersistTaps="handled" stickySectionHeadersEnabled={false}
      renderSectionHeader={({ section }) => <Text accessibilityRole="header" style={styles.section}>{section.title} ({section.data.length})</Text>}
      renderItem={({ item }) => <TaskRow task={item} onPress={() => { if (isCurrentScope()) { navigation.navigate('TeamTaskDetail', { id: item.id }); } }} />}
      ListHeaderComponent={listHeader} ListEmptyComponent={empty ?? undefined}
      ListFooterComponent={meta && meta.pages > 1 ? <View style={styles.paging}>
        <Action variant="secondary" disabled={meta.page <= 1 || query.isFetching} onPress={() => { if (isCurrentScope() && !query.isFetching && meta.page > 1) { setPage(meta.page - 1); } }}>Previous</Action>
        <Text style={styles.page}>Page {meta.page} of {meta.pages}</Text>
        <Action variant="secondary" disabled={meta.page >= meta.pages || query.isFetching} onPress={() => { if (isCurrentScope() && !query.isFetching && meta.page < meta.pages) { setPage(meta.page + 1); } }}>Next</Action>
      </View> : undefined}
      contentContainerStyle={styles.list} refreshing={refreshing} onRefresh={() => { refresh(true).catch(() => undefined); }} />
  </Screen>;
}

function FilterChip({ label, accessibilityLabel, selected, onPress }: { label: string; accessibilityLabel: string; selected: boolean; onPress: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <Pressable accessibilityRole="radio" accessibilityLabel={accessibilityLabel} accessibilityState={{ checked: selected }} onPress={onPress} style={({ pressed }) => [styles.chip, selected && styles.chipSelected, pressed && styles.pressed]}><Text style={[styles.chipText, selected && styles.chipTextSelected]}>{label}</Text></Pressable>;
}
function TaskRow({ task, onPress }: { task: TeamTaskSummary; onPress: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <Pressable accessibilityRole="button" accessibilityLabel={`Open task ${task.title}`} accessibilityHint={`${STATUS_PRESENTATION[task.status].label}, ${PRIORITY_LABEL[task.priority]} priority, ${task.assignee?.name ?? 'Unassigned'}`} onPress={onPress} style={({ pressed }) => [styles.row, pressed && styles.pressed]}>
    <View style={styles.rowTop}><Text style={styles.rowTitle}>{task.title}</Text><StatusPill tone={STATUS_PRESENTATION[task.status].tone}>{STATUS_PRESENTATION[task.status].label}</StatusPill></View>
    <View style={styles.taskFacts}><Text style={styles.meta}>{PRIORITY_LABEL[task.priority]} priority</Text><Text style={styles.meta}>{task.assignee?.name ?? 'Unassigned'}</Text>{task.due_date ? <Text style={styles.meta}>Due {formatManilaDayLabel(task.due_date, false)}</Text> : null}</View>
    {task.checklist_progress.total > 0 ? <Text style={styles.meta}>{task.checklist_progress.done}/{task.checklist_progress.total} checklist items complete</Text> : null}
    {task.status === 'blocked' ? <AlertTriangle size={16} color={theme.mode === 'dark' ? '#f2a7a7' : '#a33b3b'} style={styles.warning} /> : null}
  </Pressable>;
}
function createStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { paddingHorizontal: 18, paddingTop: 10, paddingBottom: 0 },
    resultSummary: { minHeight: 24, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 }, resultText: { ...type.caption, color: theme.ink500 }, refreshStatus: { flexDirection: 'row', alignItems: 'center', gap: 6 },
    searchRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 8 }, searchField: { flex: 1, minWidth: 0 }, searchButton: { width: 46, height: 46, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface }, clearSearch: { width: 44, height: 44, justifyContent: 'center', alignItems: 'center' },
    filters: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 10 }, chip: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 11, borderRadius: 22, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface }, chipSelected: { backgroundColor: theme.brand, borderColor: theme.brand }, chipText: { fontFamily: font.body, fontSize: 10, fontWeight: '700', color: theme.ink500 }, chipTextSelected: { color: '#ffffff' },
    loader: { marginTop: 30 }, errorBox: { gap: 8, marginVertical: 12 }, feedback: { ...type.body, color: theme.ink700 }, previousResults: { ...type.caption, color: theme.ink500, marginTop: 10 }, list: { flexGrow: 1, paddingBottom: 22 }, emptyActions: { gap: 8, width: '100%' },
    section: { ...type.bodyStrong, color: theme.ink700, backgroundColor: theme.canvas, paddingVertical: 8, marginTop: 8 }, row: { position: 'relative', minHeight: 80, padding: 13, marginBottom: 8, borderRadius: 14, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface }, rowTop: { flexDirection: 'row', alignItems: 'flex-start', gap: 8 }, rowTitle: { ...type.cardTitle, flex: 1, color: theme.ink900 }, taskFacts: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 5 }, meta: { color: theme.ink500, fontFamily: font.body, fontSize: 10.5 }, warning: { position: 'absolute', right: 12, bottom: 10 },
    pressed: { opacity: 0.72 }, paging: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 10, gap: 8 }, page: { ...type.caption, color: theme.ink500 },
  });
}
const makeStyles = memoizeStyles(createStyles);
