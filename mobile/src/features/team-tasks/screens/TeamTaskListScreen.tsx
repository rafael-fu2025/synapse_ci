import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, SectionList, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, CheckSquare2, Plus, Search, Users } from 'lucide-react-native';
import type { TeamTaskFilters, TeamTaskPriority, TeamTaskStatus, TeamTaskSummary } from '../../../api/endpoints/teamTasks';
import { queryKeys } from '../../../api/queryKeys';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { StatusPill } from '../../../components/StatusPill';
import { TextField } from '../../../components/TextField';
import { useAuth } from '../../../store/auth';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import type { MyTasksStackParamList, TeamTasksStackParamList } from '../../../navigation/types';
import { useTeamTasks } from '../hooks';
import { groupTasks, PRIORITY_LABEL, STATUS_PRESENTATION, TASK_PRIORITIES, TASK_STATUSES } from '../model';
import { teamTaskErrorKind, teamTaskErrorMessage } from '../errors';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';

interface Props { scope: 'team' | 'mine' }
type Navigation = NativeStackNavigationProp<TeamTasksStackParamList | MyTasksStackParamList>;

export function TeamTaskListScreen({ scope }: Props) {
  const { activeUnit, openUnitPicker, enterPersonal } = useWorkspace();
  const { retryBootstrap, user } = useAuth();
  const queryClient = useQueryClient();
  const accessRecoveryStarted = useRef(false);
  const unit = activeUnit?.unit ?? null;
  const navigation = useNavigation<Navigation>();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const [draftSearch, setDraftSearch] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<TeamTaskStatus | 'all'>('all');
  const [priority, setPriority] = useState<TeamTaskPriority | 'all'>('all');
  const [page, setPage] = useState(1);
  const filters: TeamTaskFilters = { scope, q: search, status, priority, page };
  const query = useTeamTasks(unit, filters);
  const rawData = useMemo(() => query.data?.data ?? [], [query.data]);
  const meta = query.data?.meta;
  const forbidden = query.isError && teamTaskErrorKind(query.error) === 'forbidden';
  const data = useMemo(() => forbidden ? [] : rawData, [forbidden, rawData]);
  const sections = useMemo(() => groupTasks(data), [data]);
  const recoverAccess = useCallback(async () => {
    if (accessRecoveryStarted.current || !activeUnit || !user) { return; }
    accessRecoveryStarted.current = true;
    await queryClient.cancelQueries({ queryKey: queryKeys.teamUnit(user.tenant_id, user.id, activeUnit.unit), exact: false });
    queryClient.removeQueries({ queryKey: queryKeys.teamUnit(user.tenant_id, user.id, activeUnit.unit), exact: false });
    enterPersonal();
    await retryBootstrap().catch(() => undefined);
  }, [activeUnit, enterPersonal, queryClient, retryBootstrap, user]);

  useEffect(() => {
    if (forbidden) { recoverAccess().catch(() => undefined); }
  }, [forbidden, recoverAccess]);

  const header = (
    <PageHeader
      edgeToEdge
      title={scope === 'mine' ? 'My Tasks' : 'Team Board'}
      subtitle={activeUnit?.label ?? 'Choose a Team Workspace'}
      action={(
        <View style={styles.headerActions}>
          <Pressable accessibilityRole="button" accessibilityLabel="Switch Team Workspace unit" onPress={openUnitPicker} style={styles.headerButton}>
            <Users size={19} color={theme.brandIcon} />
          </Pressable>
          {unit ? (
            <Pressable accessibilityRole="button" accessibilityLabel="Create task" onPress={() => navigation.navigate('TeamTaskForm', undefined)} style={styles.createIcon}>
              <Plus size={20} color="#ffffff" />
            </Pressable>
          ) : null}
        </View>
      )}
    />
  );

  if (!unit) {
    return <Screen header={header}><Text accessibilityRole="alert" style={styles.feedback}>Choose a Team Workspace unit first.</Text></Screen>;
  }

  return (
    <Screen scroll={false} bodyStyle={styles.screen} header={header}>
      <View style={styles.resultSummary}>
        <Text style={styles.resultText}>{meta ? `${meta.total} ${meta.total === 1 ? 'Task' : 'Tasks'}` : 'Tasks'}</Text>
      </View>

      <View style={styles.searchRow}>
        <View style={styles.searchField}>
          <TextField
            label="Search tasks"
            placeholder="Search by title or description"
            value={draftSearch}
            onChangeText={setDraftSearch}
            onSubmitEditing={() => { setSearch(draftSearch.trim()); setPage(1); }}
            returnKeyType="search"
          />
        </View>
        <Pressable accessibilityRole="button" accessibilityLabel="Search" onPress={() => { setSearch(draftSearch.trim()); setPage(1); }} style={styles.searchButton}>
          <Search size={19} color={theme.brandIcon} />
        </Pressable>
      </View>

      <View style={styles.filters}>
        {(['all', ...TASK_STATUSES] as const).map(value => (
          <FilterChip key={value} label={value === 'all' ? `All ${meta?.counts.total ?? ''}`.trim() : `${STATUS_PRESENTATION[value].label} ${meta?.counts[value] ?? ''}`.trim()} selected={status === value} onPress={() => { setStatus(value); setPage(1); }} />
        ))}
      </View>
      <View style={styles.filters}>
        {(['all', ...TASK_PRIORITIES] as const).map(value => (
          <FilterChip key={value} label={value === 'all' ? 'All priorities' : PRIORITY_LABEL[value]} selected={priority === value} onPress={() => { setPriority(value); setPage(1); }} />
        ))}
      </View>

      {query.isError ? (
        <View style={styles.errorBox}>
          <Text accessibilityRole="alert" style={styles.feedback}>{forbidden ? 'You no longer have access to this Team Workspace.' : teamTaskErrorMessage(query.error, data.length > 0 ? 'Could not refresh. Showing the last available tasks.' : 'Could not load tasks.')}</Text>
          {!forbidden ? <Action variant="secondary" onPress={() => { query.refetch().catch(() => undefined); }}>Retry</Action> : null}
        </View>
      ) : null}
      {query.isLoading ? <ActivityIndicator style={styles.loader} color={theme.brandIcon} /> : null}
      {!query.isLoading && data.length === 0 && !query.isError ? (
        <EmptyState icon={<CheckSquare2 size={30} color={theme.brandIcon} />} title={search || status !== 'all' || priority !== 'all' ? 'No matching tasks' : scope === 'mine' ? 'No tasks assigned to you' : 'No team tasks yet'} body="Adjust the filters or create an operational task for the team." action={<Action full onPress={() => navigation.navigate('TeamTaskForm', undefined)}>Create task</Action>} />
      ) : null}
      {data.length > 0 ? (
        <SectionList
          sections={sections}
          keyExtractor={item => String(item.id)}
          renderSectionHeader={({ section }) => <Text style={styles.section}>{section.title} ({section.data.length})</Text>}
          renderItem={({ item }) => <TaskRow task={item} onPress={() => navigation.navigate('TeamTaskDetail', { id: item.id })} />}
          contentContainerStyle={styles.list}
          refreshing={query.isRefetching}
          onRefresh={() => { query.refetch().catch(() => undefined); }}
        />
      ) : null}
      {meta && meta.pages > 1 ? (
        <View style={styles.paging}>
          <Action variant="secondary" disabled={meta.page <= 1 || query.isFetching} onPress={() => setPage(meta.page - 1)}>Previous</Action>
          <Text style={styles.page}>Page {meta.page} of {meta.pages}</Text>
          <Action variant="secondary" disabled={meta.page >= meta.pages || query.isFetching} onPress={() => setPage(meta.page + 1)}>Next</Action>
        </View>
      ) : null}
    </Screen>
  );
}

function FilterChip({ label, selected, onPress }: { label: string; selected: boolean; onPress: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <Pressable accessibilityRole="button" accessibilityState={{ selected }} onPress={onPress} style={[styles.chip, selected && styles.chipSelected]}><Text style={[styles.chipText, selected && styles.chipTextSelected]}>{label}</Text></Pressable>;
}

function TaskRow({ task, onPress }: { task: TeamTaskSummary; onPress: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={`Open task ${task.title}`} onPress={onPress} style={({ pressed }) => [styles.row, pressed && styles.pressed]}>
      <View style={styles.rowTop}><Text style={styles.rowTitle}>{task.title}</Text><StatusPill tone={STATUS_PRESENTATION[task.status].tone}>{STATUS_PRESENTATION[task.status].label}</StatusPill></View>
      <View style={styles.taskFacts}>
        <Text style={styles.meta}>{PRIORITY_LABEL[task.priority]} Priority</Text>
        <Text style={styles.meta}>{task.assignee?.name ?? 'Unassigned'}</Text>
        {task.due_date ? <Text style={styles.meta}>Due {task.due_date}</Text> : null}
      </View>
      {task.checklist_progress.total > 0 ? <Text style={styles.meta}>{task.checklist_progress.done}/{task.checklist_progress.total} checklist items complete</Text> : null}
      {task.status === 'blocked' ? <AlertTriangle size={16} color="#a33b3b" style={styles.warning} /> : null}
    </Pressable>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { paddingHorizontal: 18, paddingTop: 10 },
    headerActions: { flexDirection: 'row', gap: 8 },
    headerButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    createIcon: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, backgroundColor: theme.brand },
    resultSummary: { minHeight: 24, justifyContent: 'center' }, resultText: { ...type.caption, color: theme.ink500 },
    searchRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 8 }, searchField: { flex: 1, minWidth: 0 },
    searchButton: { width: 46, height: 46, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    filters: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 10 }, chip: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 11, borderRadius: 22, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    chipSelected: { backgroundColor: theme.brand, borderColor: theme.brand }, chipText: { fontFamily: font.body, fontSize: 10, fontWeight: '700', color: theme.ink500 }, chipTextSelected: { color: '#ffffff' },
    loader: { marginTop: 30 }, errorBox: { gap: 8, marginTop: 12 }, feedback: { ...type.body, color: theme.ink700 }, list: { paddingTop: 12, paddingBottom: 18 },
    section: { ...type.bodyStrong, color: theme.ink700, backgroundColor: theme.canvas, paddingVertical: 8 }, row: { position: 'relative', padding: 13, marginBottom: 8, borderRadius: 14, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    rowTop: { flexDirection: 'row', alignItems: 'flex-start', gap: 8 }, rowTitle: { ...type.cardTitle, flex: 1, color: theme.ink900 }, taskFacts: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 5 }, meta: { color: theme.ink500, fontFamily: font.body, fontSize: 10.5 }, warning: { position: 'absolute', right: 12, bottom: 10 },
    pressed: { opacity: 0.72 }, paging: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingTop: 8 }, page: { ...type.caption, color: theme.ink500 },
  });
}
const makeStyles = memoizeStyles(createStyles);
