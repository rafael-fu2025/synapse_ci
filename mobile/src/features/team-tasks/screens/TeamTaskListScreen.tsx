import React, { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Keyboard, Pressable, SectionList, StyleSheet, Text, TextInput, View, type SectionListRenderItemInfo } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { CalendarDays, Check, CheckSquare2, ChevronRight, Flag, Plus, Search, SlidersHorizontal, UserRound, Users, X } from 'lucide-react-native';
import type { TeamTaskCounts, TeamTaskFilters, TeamTaskPriority, TeamTaskStatus, TeamTaskSummary } from '../../../api/endpoints/teamTasks';
import { Action } from '../../../components/Action';
import { BottomSheet } from '../../../components/BottomSheet';
import { EmptyState } from '../../../components/EmptyState';
import { IconAction } from '../../../components/IconAction';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { useAuth } from '../../../store/auth';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { WorkspaceHeaderActions } from '../../workspace/WorkspaceHeaderActions';
import type { MyTasksStackParamList, TeamTasksStackParamList } from '../../../navigation/types';
import { useTeamTasks, useTeamTaskAccessRecovery } from '../hooks';
import { groupTasks, PRIORITY_LABEL, STATUS_PRESENTATION, TASK_PRIORITIES, TASK_STATUSES } from '../model';
import { teamTaskErrorKind, teamTaskErrorMessage } from '../errors';
import { Theme, font, memoizeStyles, palette, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatCompactDate, formatManilaDayLabel } from '../../team-calendar/manila';

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
  const [filterSession, setFilterSession] = useState<object | null>(null);
  const filterLease = useRef<object | null>(null);
  const filters: TeamTaskFilters = { scope, q: search, status, priority, page };
  const query = useTeamTasks(unit, filters);
  useTeamTaskAccessRecovery(unit, query.isError ? query.error : null);
  const forbidden = query.isError && teamTaskErrorKind(query.error) === 'forbidden';
  const data = useMemo(() => forbidden ? [] : query.data?.data ?? [], [forbidden, query.data]);
  const meta = forbidden ? undefined : query.data?.meta;
  const sections = useMemo(() => groupTasks(data), [data]);
  const filtered = search !== '' || status !== 'all' || priority !== 'all';
  const canReset = filtered || draftSearch !== '' || page !== 1;
  const filterCount = Number(status !== 'all') + Number(priority !== 'all');
  const filterDescription = [status !== 'all' ? STATUS_PRESENTATION[status].label : null,
    priority !== 'all' ? `${PRIORITY_LABEL[priority]} priority` : null, search ? `“${search}”` : null].filter(Boolean).join(' · ');
  const scopeKey = `${user?.tenant_id ?? 0}:${user?.id ?? 0}:${unit ?? 'none'}:${scope}`;
  const lifetime = useRef({ key: scopeKey, mounted: true });
  lifetime.current.key = scopeKey;
  useEffect(() => {
    const screen = lifetime.current;
    screen.mounted = true;
    return () => { screen.mounted = false; filterLease.current = null; };
  }, []);

  useEffect(() => {
    draftSearchRef.current = '';
    setDraftSearch('');
    setSearch('');
    setStatus('all');
    setPriority('all');
    filterLease.current = null;
    setFilterSession(null);
    refreshingRef.current = false;
    setRefreshing(false);
    setPage(1);
  }, [scopeKey]);

  useEffect(() => {
    if (forbidden) { filterLease.current = null; setFilterSession(null); }
  }, [forbidden]);

  const openTask = useCallback((id: number) => {
    if (lifetime.current.mounted && lifetime.current.key === scopeKey) { navigation.navigate('TeamTaskDetail', { id }); }
  }, [navigation, scopeKey]);
  const renderTask = useCallback(({ item }: SectionListRenderItemInfo<TeamTaskSummary>) => <TaskRow task={item} onOpen={openTask} />, [openTask]);

  function isCurrentScope() { return lifetime.current.mounted && lifetime.current.key === scopeKey; }
  function isCurrentFilterSession() { return isCurrentScope() && filterSession !== null && filterLease.current === filterSession; }
  function openFilters() {
    if (!isCurrentScope() || forbidden) { return; }
    Keyboard.dismiss();
    const lease = {};
    filterLease.current = lease;
    setFilterSession(lease);
  }
  function closeFilters() {
    if (!isCurrentFilterSession()) { return; }
    filterLease.current = null;
    setFilterSession(null);
  }
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
    {!forbidden ? <WorkflowOverview counts={meta?.counts} narrowed={search !== '' || priority !== 'all'} previous={query.isPlaceholderData} /> : null}
    <View style={styles.toolbar}>
      <View style={styles.searchControl}>
        <Pressable accessibilityRole="button" accessibilityLabel="Search" onPress={searchTasks} style={({ pressed }) => [styles.searchSubmit, pressed && styles.pressed]}><Search size={19} color={theme.ink500} /></Pressable>
        <TextInput accessibilityLabel="Search tasks" accessibilityHint="Search by title or description. Use Search to apply your text."
          placeholder="Search tasks…" placeholderTextColor={theme.ink400} value={draftSearch} maxLength={100}
          onChangeText={value => { if (isCurrentScope()) { draftSearchRef.current = value; setDraftSearch(value); } }}
          onSubmitEditing={searchTasks} returnKeyType="search" style={styles.searchInput} />
        {draftSearch !== '' || search !== '' ? <Pressable accessibilityRole="button" accessibilityLabel="Clear task search" onPress={clearSearch}
          style={({ pressed }) => [styles.searchSubmit, pressed && styles.pressed]}><X size={17} color={theme.ink500} /></Pressable> : null}
      </View>
      <Pressable accessibilityRole="button" accessibilityLabel="Open task filters" accessibilityHint={filterDescription || 'Filter tasks by status and priority.'}
        accessibilityState={{ expanded: filterSession !== null && !forbidden, disabled: forbidden }} disabled={forbidden}
        onPress={openFilters} style={({ pressed }) => [styles.filterButton, filterCount > 0 && styles.filterButtonActive, pressed && styles.pressed]}>
        <SlidersHorizontal size={18} color={filterCount > 0 ? theme.brandText : theme.ink700} />
        <Text style={styles.filterButtonText}>Filters</Text>
        {filterCount > 0 ? <View style={styles.filterBadge}><Text style={styles.filterBadgeText}>{filterCount}</Text></View> : null}
      </Pressable>
    </View>
    <View style={styles.resultSummary}>
      <View style={styles.resultCopy}>
        <Text accessibilityLiveRegion="polite" style={styles.resultText}>{meta ? `${meta.total} ${meta.total === 1 ? 'task' : 'tasks'}${filtered ? ' found' : ''}` : 'Tasks'}</Text>
        {filterDescription ? <Text style={styles.activeFilters}>{filterDescription}</Text> : null}
      </View>
      {canReset ? <Action variant="ghost" accessibilityLabel="Reset task filters" style={styles.resetAction} onPress={resetFilters}>Reset filters</Action> : null}
    </View>
    {query.isFetching && !query.isLoading && !refreshing ? <View style={styles.refreshStatus}><ActivityIndicator size="small" accessibilityLabel="Updating task results" color={theme.brandIcon} /><Text style={styles.resultText}>{query.isPlaceholderData ? 'Loading results…' : 'Updating…'}</Text></View> : null}
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
      renderSectionHeader={({ section }) => <TaskSectionHeading status={section.status} count={section.data.length} />}
      renderItem={renderTask}
      ListHeaderComponent={listHeader} ListEmptyComponent={empty ?? undefined}
      ListFooterComponent={meta && meta.pages > 1 ? <View style={styles.paging}>
        <Action variant="secondary" disabled={meta.page <= 1 || query.isFetching} onPress={() => { if (isCurrentScope() && !query.isFetching && meta.page > 1) { setPage(meta.page - 1); } }}>Previous</Action>
        <Text style={styles.page}>Page {meta.page} of {meta.pages}</Text>
        <Action variant="secondary" disabled={meta.page >= meta.pages || query.isFetching} onPress={() => { if (isCurrentScope() && !query.isFetching && meta.page < meta.pages) { setPage(meta.page + 1); } }}>Next</Action>
      </View> : undefined}
      contentContainerStyle={styles.list} refreshing={refreshing} onRefresh={() => { refresh(true).catch(() => undefined); }} />
    <BottomSheet visible={filterSession !== null && !forbidden} title="Task filters" description="Status totals follow your search and priority. Selections are kept when you close."
      closeLabel="Close task filters" onClose={closeFilters} footer={<View style={styles.filterFooter}>
        <Action variant="ghost" accessibilityLabel="Reset filters in sheet" onPress={() => { if (isCurrentFilterSession()) { resetFilters(); } }}>Reset filters</Action>
        <Action style={styles.filterDone} accessibilityLabel="Done filtering tasks" onPress={closeFilters}>Done</Action>
      </View>}>
      <View accessibilityRole="radiogroup" accessibilityLabel="Filter by status" style={styles.filterGroup}>
        <Text accessibilityRole="header" style={styles.filterHeading}>Status</Text>
        {(['all', ...TASK_STATUSES] as const).map(value => <FilterChoice key={value} accessibilityLabel={`Status filter: ${value === 'all' ? 'All' : STATUS_PRESENTATION[value].label}`}
          label={value === 'all' ? 'All statuses' : STATUS_PRESENTATION[value].label} count={meta?.counts[value === 'all' ? 'total' : value]}
          color={value === 'all' ? theme.ink500 : statusColor(theme, value)} selected={status === value}
          onPress={() => { if (isCurrentFilterSession()) { setStatus(value); setPage(1); } }} />)}
      </View>
      <View accessibilityRole="radiogroup" accessibilityLabel="Filter by priority" style={styles.filterGroup}>
        <Text accessibilityRole="header" style={styles.filterHeading}>Priority</Text>
        {(['all', ...TASK_PRIORITIES] as const).map(value => <FilterChoice key={value} accessibilityLabel={`Priority filter: ${value === 'all' ? 'All' : PRIORITY_LABEL[value]}`}
          label={value === 'all' ? 'All priorities' : `${PRIORITY_LABEL[value]} priority`} selected={priority === value}
          onPress={() => { if (isCurrentFilterSession()) { setPriority(value); setPage(1); } }} />)}
      </View>
    </BottomSheet>
  </Screen>;
}

function statusColor(theme: Theme, status: TeamTaskStatus): string {
  switch (status) {
    case 'in_progress': return theme.mode === 'dark' ? '#79bddc' : palette.blue600;
    case 'blocked': return theme.mode === 'dark' ? '#f2a7a7' : palette.red700;
    case 'done': return theme.mode === 'dark' ? '#89cbb5' : '#176259';
    default: return theme.ink500;
  }
}

function WorkflowOverview({ counts, narrowed, previous }: { counts?: TeamTaskCounts; narrowed: boolean; previous: boolean }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const metrics = [
    { label: 'Open', value: counts ? counts.total - counts.done : undefined, color: theme.ink900, hint: 'Includes to do, in progress and blocked tasks.' },
    { label: 'Blocked', value: counts?.blocked, color: statusColor(theme, 'blocked'), hint: 'Blocked tasks are also included in Open.' },
    { label: 'Done', value: counts?.done, color: statusColor(theme, 'done'), hint: 'Completed tasks.' },
  ];
  return <View testID="task-workflow-overview" style={styles.overview}>
    <View style={styles.overviewHeading}><Text style={styles.eyebrow}>WORKFLOW</Text><Text style={styles.overviewScope}>{previous ? 'Previous results' : narrowed ? 'Search / priority · All statuses' : 'All statuses'}</Text></View>
    <View style={styles.metrics}>
      {metrics.map((metric, index) => <View key={metric.label} accessible accessibilityLabel={`${metric.label} tasks: ${metric.value ?? 'unavailable'}`}
        accessibilityHint={metric.hint} style={[styles.metric, index > 0 && styles.metricDivider]}>
        <Text style={[styles.metricValue, { color: metric.value ? metric.color : theme.ink500 }]}>{metric.value ?? '—'}</Text>
        <View style={styles.metricLabelRow}><View style={[styles.metricDot, { backgroundColor: metric.value ? metric.color : theme.ink400 }]} /><Text style={styles.metricLabel}>{metric.label}</Text></View>
      </View>)}
    </View>
  </View>;
}

function FilterChoice({ label, accessibilityLabel, selected, count, color, onPress }: {
  label: string; accessibilityLabel: string; selected: boolean; count?: number; color?: string; onPress: () => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <Pressable accessibilityRole="radio" accessibilityLabel={accessibilityLabel} accessibilityState={{ checked: selected }}
    accessibilityHint={count === undefined ? undefined : `${count} ${count === 1 ? 'task' : 'tasks'}`}
    onPress={onPress} style={({ pressed }) => [styles.filterChoice, selected && styles.filterChoiceSelected, pressed && styles.pressed]}>
    {color ? <View style={[styles.statusDot, { backgroundColor: color }]} /> : null}
    <Text style={[styles.filterChoiceText, selected && styles.filterChoiceTextSelected]}>{label}</Text>
    {count !== undefined ? <Text style={styles.filterChoiceCount}>{count}</Text> : null}
    <View style={[styles.radio, selected && styles.radioSelected]}>{selected ? <Check size={13} color="#ffffff" strokeWidth={3} /> : null}</View>
  </Pressable>;
}

function TaskSectionHeading({ status, count }: { status: TeamTaskStatus; count: number }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const label = STATUS_PRESENTATION[status].label;
  return <View accessible accessibilityRole="header" accessibilityLabel={`${label}, ${count} ${count === 1 ? 'task' : 'tasks'} on this page`} style={styles.section}>
    <View style={[styles.statusDot, { backgroundColor: statusColor(theme, status) }]} /><Text style={styles.sectionTitle}>{label}</Text>
    <Text style={styles.sectionCount}>{count}</Text><View style={styles.sectionRule} />
  </View>;
}

const TaskRow = memo(function TaskRowView({ task, onOpen }: { task: TeamTaskSummary; onOpen: (id: number) => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const checklist = task.checklist_progress;
  const priorityColor = task.priority === 'high' ? statusColor(theme, 'blocked') : theme.ink500;
  const hint = [STATUS_PRESENTATION[task.status].label, `${PRIORITY_LABEL[task.priority]} priority`, task.assignee?.name ?? 'Unassigned',
    task.due_date ? `Due ${formatManilaDayLabel(task.due_date)}` : 'No due date',
    checklist.total > 0 ? `${checklist.done} of ${checklist.total} checklist items complete` : null].filter(Boolean).join(', ');
  return <Pressable accessibilityRole="button" accessibilityLabel={`Open task ${task.title}`} accessibilityHint={hint}
    onPress={() => onOpen(task.id)} style={({ pressed }) => [styles.row, pressed && styles.pressed]}>
    <View style={styles.rowTop}><Text style={styles.rowTitle}>{task.title}</Text><ChevronRight size={18} color={theme.ink400} /></View>
    <View style={styles.ownerRow}><View style={styles.ownerIcon}><UserRound size={13} color={theme.ink500} /></View><Text style={styles.ownerName}>{task.assignee?.name ?? 'Unassigned'}</Text></View>
    <View style={styles.taskFacts}>
      <View style={styles.fact}><Flag size={13} color={priorityColor} /><Text style={[styles.factText, { color: priorityColor }]}>{PRIORITY_LABEL[task.priority]} priority</Text></View>
      <View style={styles.fact}><CalendarDays size={13} color={theme.ink500} /><Text style={styles.factText}>{task.due_date ? `Due ${formatCompactDate(task.due_date)}` : 'No due date'}</Text></View>
    </View>
    {checklist.total > 0 ? <View style={styles.checklist}>
      <View style={styles.checklistHeading}><CheckSquare2 size={13} color={theme.ink500} /><Text style={styles.factText}>{checklist.done}/{checklist.total} complete</Text></View>
      <View accessible={false} style={styles.progressTrack}><View style={[styles.progressFill, { width: `${Math.min(100, Math.max(0, checklist.done / checklist.total * 100))}%` }]} /></View>
    </View> : null}
  </Pressable>;
});

function createStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { paddingHorizontal: 18, paddingTop: 16, paddingBottom: 0 },
    overview: { padding: 16, borderRadius: 18, backgroundColor: theme.surface, marginBottom: 16 },
    overviewHeading: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', alignItems: 'center', gap: 6 },
    eyebrow: { fontFamily: font.body, fontSize: 9, fontWeight: '800', letterSpacing: 1.3, color: theme.ink500 },
    overviewScope: { ...type.caption, color: theme.ink500 },
    metrics: { flexDirection: 'row', marginTop: 14 },
    metric: { flex: 1, minWidth: 0, gap: 5 },
    metricDivider: { paddingLeft: 16, borderLeftWidth: 1, borderLeftColor: theme.mode === 'dark' ? '#303030' : theme.line },
    metricValue: { fontFamily: font.display, fontSize: 26, fontWeight: '700', fontVariant: ['tabular-nums'] },
    metricLabelRow: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 5 },
    metricDot: { width: 5, height: 5, borderRadius: 3 }, metricLabel: { fontFamily: font.body, fontSize: 11, fontWeight: '600', color: theme.ink500 },
    toolbar: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 8 },
    searchControl: { flex: 1, minWidth: 160, flexDirection: 'row', alignItems: 'center', borderRadius: 14, backgroundColor: theme.surfaceWarm },
    searchSubmit: { width: 44, minHeight: 50, justifyContent: 'center', alignItems: 'center' },
    searchInput: { flex: 1, minWidth: 0, minHeight: 50, paddingVertical: 12, paddingLeft: 0, paddingRight: 10, fontFamily: font.body, fontSize: 12, color: theme.ink900 },
    filterButton: { minHeight: 50, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 7, paddingHorizontal: 12, paddingVertical: 10, borderRadius: 14, backgroundColor: theme.surfaceWarm },
    filterButtonActive: { backgroundColor: theme.brand50 },
    filterButtonText: { fontFamily: font.body, fontSize: 12, fontWeight: '700', color: theme.ink700 },
    filterBadge: { minWidth: 18, minHeight: 18, paddingHorizontal: 4, alignItems: 'center', justifyContent: 'center', borderRadius: 9, backgroundColor: theme.brand },
    filterBadgeText: { fontFamily: font.body, fontSize: 10, fontWeight: '800', color: '#ffffff' },
    resultSummary: { minHeight: 42, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginTop: 8 },
    resultCopy: { flex: 1, gap: 3 }, resultText: { ...type.caption, color: theme.ink500 },
    activeFilters: { fontFamily: font.body, fontSize: 11, fontWeight: '600', color: theme.ink700 }, resetAction: { paddingHorizontal: 4 },
    refreshStatus: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingBottom: 8 },
    loader: { marginTop: 30 }, errorBox: { gap: 8, marginVertical: 12 }, feedback: { ...type.body, color: theme.ink700 },
    previousResults: { ...type.caption, color: theme.ink500, marginBottom: 10 }, list: { flexGrow: 1, paddingBottom: 36 }, emptyActions: { gap: 8, width: '100%' },
    section: { flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: theme.canvas, paddingVertical: 10, marginTop: 4 },
    statusDot: { width: 7, height: 7, borderRadius: 4 }, sectionTitle: { fontFamily: font.body, fontSize: 12, fontWeight: '800', color: theme.ink700 },
    sectionCount: { fontFamily: font.body, fontSize: 11, fontWeight: '700', color: theme.ink500 }, sectionRule: { flex: 1, height: 1, backgroundColor: theme.mode === 'dark' ? '#303030' : theme.line, marginLeft: 4 },
    row: { padding: 16, marginBottom: 10, borderRadius: 18, borderWidth: 1, borderColor: theme.mode === 'dark' ? '#303030' : theme.line, backgroundColor: theme.surface },
    rowTop: { flexDirection: 'row', alignItems: 'flex-start', gap: 12 }, rowTitle: { fontFamily: font.display, fontSize: 15, fontWeight: '700', lineHeight: 23, flex: 1, color: theme.ink900 },
    ownerRow: { flexDirection: 'row', alignItems: 'center', gap: 7, marginTop: 10 }, ownerIcon: { width: 24, height: 24, borderRadius: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: theme.surfaceWarm },
    ownerName: { flex: 1, fontFamily: font.body, fontSize: 12, color: theme.ink500 },
    taskFacts: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 10, marginTop: 14, paddingTop: 12, borderTopWidth: 1, borderTopColor: theme.mode === 'dark' ? '#303030' : theme.line },
    fact: { flexDirection: 'row', alignItems: 'center', gap: 5, flexShrink: 1 }, factText: { fontFamily: font.body, fontSize: 11, color: theme.ink500, flexShrink: 1 },
    checklist: { gap: 7, marginTop: 12 }, checklistHeading: { flexDirection: 'row', alignItems: 'center', gap: 5 },
    progressTrack: { height: 3, borderRadius: 2, overflow: 'hidden', backgroundColor: theme.mode === 'dark' ? '#303030' : theme.line },
    progressFill: { height: 3, borderRadius: 2, backgroundColor: theme.mode === 'dark' ? '#b76e6e' : theme.brand },
    filterGroup: { gap: 6, marginBottom: 22 }, filterHeading: { ...type.action, color: theme.ink900, marginBottom: 5 },
    filterChoice: { minHeight: 50, flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 12, paddingHorizontal: 12, borderRadius: 12, borderWidth: 1, borderColor: 'transparent', backgroundColor: theme.surfaceWarm },
    filterChoiceSelected: { borderColor: theme.brand100, backgroundColor: theme.brand50 },
    filterChoiceText: { flex: 1, fontFamily: font.body, fontSize: 13, fontWeight: '600', color: theme.ink700 }, filterChoiceTextSelected: { color: theme.brandText },
    filterChoiceCount: { ...type.bodyStrong, color: theme.ink500 }, radio: { width: 20, height: 20, borderRadius: 10, borderWidth: 1, borderColor: theme.ink400, alignItems: 'center', justifyContent: 'center' },
    radioSelected: { backgroundColor: theme.brand, borderColor: theme.brand }, filterFooter: { flexDirection: 'row', alignItems: 'center', gap: 12 }, filterDone: { flex: 1 },
    pressed: { opacity: 0.72 }, paging: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 10, gap: 8 }, page: { ...type.caption, color: theme.ink500 },
  });
}
const makeStyles = memoizeStyles(createStyles);
