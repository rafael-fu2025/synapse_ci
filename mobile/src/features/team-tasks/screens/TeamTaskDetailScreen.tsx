import React, { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { Check, CheckSquare2, Edit3, MessageSquare, RefreshCw, Square, Users } from 'lucide-react-native';
import type { TeamTaskCommentInput, TeamTaskStatus, TeamTaskUpdateInput } from '../../../api/endpoints/teamTasks';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { IconAction } from '../../../components/IconAction';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { Skeleton } from '../../../components/Skeleton';
import { StatusPill } from '../../../components/StatusPill';
import { TextField } from '../../../components/TextField';
import { formatDateTime } from '../../../lib/format';
import { loadingState } from '../../../lib/loadingState';
import { secureClientNonce } from '../../../lib/secureNonce';
import type { TeamTasksStackParamList } from '../../../navigation/types';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { formatManilaDayLabel } from '../../team-calendar/manila';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { WorkspaceHeaderActions } from '../../workspace/WorkspaceHeaderActions';
import { useWorkspaceDraft } from '../../workspace/useWorkspaceDraft';
import { isAmbiguousTaskSend, isTaskAccessFailure, teamTaskErrorKind, teamTaskErrorMessage } from '../errors';
import { useChangeTeamTaskStatus, useCommentTeamTask, useTeamTask, useTeamTaskAccessRecovery, useTeamTaskScreenScope, useUpdateTeamTask } from '../hooks';
import { checklistDraftItem, PRIORITY_LABEL, STATUS_PRESENTATION, taskEditableInput, TASK_STATUSES } from '../model';

type DetailRoute = RouteProp<TeamTasksStackParamList, 'TeamTaskDetail'>;
type Navigation = NativeStackNavigationProp<TeamTasksStackParamList>;
type TaskRequest =
  | { kind: 'status'; input: { status: TeamTaskStatus; version: number } }
  | { kind: 'checklist'; input: TeamTaskUpdateInput }
  | { kind: 'comment'; input: TeamTaskCommentInput };
type Failure = { request: TaskRequest; error: unknown };

export function TeamTaskDetailScreen() {
  const route = useRoute<DetailRoute>();
  const navigation = useNavigation<Navigation>();
  const { activeUnit, openUnitPicker } = useWorkspace();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const id = route.params.id;
  const unit = activeUnit?.unit ?? null;
  const isCurrentScope = useTeamTaskScreenScope(unit, `detail:${id}`);
  const query = useTeamTask(unit, id);
  const statusMutation = useChangeTeamTaskStatus(unit, id);
  const checklistMutation = useUpdateTeamTask(unit, id);
  const commentMutation = useCommentTeamTask(unit, id);
  const { reset: resetComment } = commentMutation;
  const { reset: resetStatus } = statusMutation;
  const { reset: resetChecklist } = checklistMutation;
  const reconcileAccess = useTeamTaskAccessRecovery(unit, query.isError ? query.error : null);
  const [commentBody, setCommentBody] = useState('');
  const commentRef = useRef('');
  const pendingRequest = useRef<TaskRequest | null>(null);
  const sending = useRef(false);
  const [inFlight, setInFlight] = useState<TaskRequest['kind'] | null>(null);
  const [failure, setFailure] = useState<Failure | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const refreshInFlight = useRef(false);
  const checklistIds = useRef<string[]>([]);
  const task = query.data?.data;
  const contentState = loadingState(query);
  const paused = query.fetchStatus === 'paused';
  const readErrorKind = query.isError ? teamTaskErrorKind(query.error) : null;
  const readDenied = readErrorKind === 'forbidden' || readErrorKind === 'missing';
  const failureKind = failure ? teamTaskErrorKind(failure.error) : null;
  const uncertain = failure !== null && (isAmbiguousTaskSend(failure.error) || failureKind === 'idempotency');
  const needsReview = failureKind === 'stale' || failureKind === 'forbidden' || failureKind === 'missing' || failureKind === 'idempotency';
  const submitting = inFlight !== null || statusMutation.isPending || checklistMutation.isPending || commentMutation.isPending;
  const locked = submitting || uncertain || needsReview || readDenied || refreshInFlight.current || !isCurrentScope();
  const current = useRef({ task, locked, submitting, readDenied });
  current.current = { task, locked, submitting, readDenied };
  const failureRef = useRef(failure);
  failureRef.current = failure;

  const resetDraft = useCallback(() => {
    if (sending.current) { return; }
    commentRef.current = '';
    setCommentBody('');
    pendingRequest.current = null;
    failureRef.current = null;
    setFailure(null);
    resetComment();
    resetStatus();
    resetChecklist();
  }, [resetChecklist, resetComment, resetStatus]);

  const { requestLeave, markSaved } = useWorkspaceDraft({
    key: `team-task-comment:${unit ?? 'none'}:${id}`,
    dirty: commentBody.length > 0 || uncertain,
    submitting,
    onDiscard: resetDraft,
    warning: uncertain ? 'The previous request may have reached the server. Discarding this local draft will not undo it. Review the task before sending anything new.' : undefined,
  });

  function leave(action: () => void) {
    if (!isCurrentScope() || sending.current || current.current.submitting) { return; }
    requestLeave(action);
  }

  function mayMutate() {
    return isCurrentScope() && !sending.current && !refreshInFlight.current && !current.current.locked && pendingRequest.current === null && !!current.current.task && !!unit;
  }

  async function send(request: TaskRequest, retry = false) {
    if (!isCurrentScope() || sending.current || refreshInFlight.current || current.current.submitting || current.current.readDenied || !current.current.task || !unit) { return; }
    if (retry ? pendingRequest.current !== request : !mayMutate()) { return; }
    if (request.kind !== 'comment' && !current.current.task.can_edit) { return; }
    sending.current = true;
    pendingRequest.current = request;
    setInFlight(request.kind);
    setFailure(null);
    try {
      if (request.kind === 'status') { await statusMutation.mutateAsync(request.input); }
      else if (request.kind === 'checklist') { await checklistMutation.mutateAsync(request.input); }
      else {
        await commentMutation.mutateAsync(request.input);
        if (!isCurrentScope()) { return; }
        commentRef.current = '';
        setCommentBody('');
        markSaved();
      }
      if (!isCurrentScope()) { return; }
      pendingRequest.current = null;
      failureRef.current = null;
      setFailure(null);
    } catch (error) {
      if (!isCurrentScope()) { return; }
      const nextFailure = { request, error };
      failureRef.current = nextFailure;
      setFailure(nextFailure);
      if (!isAmbiguousTaskSend(error) && teamTaskErrorKind(error) !== 'idempotency') { pendingRequest.current = null; }
      if (isTaskAccessFailure(error)) { reconcileAccess(); }
    } finally {
      sending.current = false;
      if (isCurrentScope()) { setInFlight(null); }
    }
  }

  function changeStatus(status: TeamTaskStatus) {
    const latest = current.current.task;
    if (!mayMutate() || !latest?.can_edit || latest.status === status) { return; }
    send({ kind: 'status', input: { status, version: latest.version } });
  }

  function toggleChecklist(index: number) {
    const latest = current.current.task;
    if (!mayMutate() || !latest?.can_edit || !latest.checklist[index]) { return; }
    const payload = taskEditableInput(latest);
    payload.checklist = payload.checklist.map((item, itemIndex) => ({ text: item.text, done: itemIndex === index ? !item.done : item.done }));
    send({ kind: 'checklist', input: { ...payload, version: latest.version } });
  }

  function submitComment() {
    if (!mayMutate() || commentRef.current.trim() === '') { return; }
    send({ kind: 'comment', input: { body: commentRef.current.trim(), client_nonce: secureClientNonce() } });
  }

  function changeComment(value: string) {
    if (!isCurrentScope() || sending.current || current.current.locked || pendingRequest.current !== null) { return; }
    commentRef.current = value;
    setCommentBody(value);
    if (failureRef.current?.request.kind === 'comment') {
      failureRef.current = null;
      setFailure(null);
      commentMutation.reset();
    }
  }

  async function refresh(pull = false, review = false) {
    if (!isCurrentScope() || query.eligible === false || sending.current || current.current.submitting || refreshInFlight.current) { return; }
    refreshInFlight.current = true;
    if (pull) { setRefreshing(true); }
    try {
      const result = await query.refetch({ cancelRefetch: false });
      if (!isCurrentScope()) { return; }
      // Reading never silently drops a possibly committed request or retries a write.
      if (review && !result.isError && pendingRequest.current === null) {
        failureRef.current = null;
        setFailure(null);
        statusMutation.reset();
        checklistMutation.reset();
        commentMutation.reset();
      }
    } finally { refreshInFlight.current = false; if (isCurrentScope()) { setRefreshing(false); } }
  }

  function retryOriginal() {
    const request = pendingRequest.current;
    if (request && failureRef.current && teamTaskErrorKind(failureRef.current.error) !== 'idempotency') { send(request, true); }
  }

  const header = (
    <PageHeader edgeToEdge title="Task details" subtitle={activeUnit?.label ?? 'Team Workspace'} backDisabled={submitting}
      onBack={() => leave(() => navigation.goBack())}
      action={<WorkspaceHeaderActions disabled={submitting}>
        {task?.can_edit && !readDenied ? <IconAction label="Edit task" disabled={locked} icon={<Edit3 size={18} color={theme.brandIcon} />}
          onPress={() => { if (!sending.current && !current.current.locked && current.current.task?.can_edit) { leave(() => navigation.navigate('TeamTaskForm', { id })); } }} /> : null}
      </WorkspaceHeaderActions>} />
  );
  const refreshControl = <RefreshControl refreshing={refreshing} enabled={!submitting && query.eligible !== false} tintColor={theme.brandIcon} colors={[theme.brand]} onRefresh={() => { refresh(true).catch(() => undefined); }} />;

  if (!isCurrentScope()) {
    return <Screen header={header}><Text style={styles.muted}>This task belongs to the previous workspace. Open it again from the task list.</Text></Screen>;
  }
  if (!activeUnit) {
    return <Screen header={header}><EmptyState icon={<Users size={30} color={theme.brandIcon} />} title="Choose a Team Workspace unit" body="Select a unit to view this task." action={<Action full onPress={openUnitPicker}>Choose unit</Action>} /></Screen>;
  }
  if (!readDenied && !task && contentState === 'loading' && !paused && query.eligible !== false) {
    return <Screen header={header}><Skeleton label="Loading task" variant="detail" /></Screen>;
  }
  if (!readDenied && !task && !query.isError) {
    return <Screen header={header}><EmptyState icon={<RefreshCw size={30} color={theme.brandIcon} />}
      title={paused ? 'You are offline' : 'Task is waiting to load'}
      body={paused ? 'Reconnect to load this task.' : 'Return to this Team Workspace while connected to load the task.'}
      action={<Action full disabled={query.eligible === false || paused} onPress={() => { refresh().catch(() => undefined); }}>Try again</Action>} /></Screen>;
  }
  if (!task || readDenied) {
    return <Screen header={header} refreshControl={refreshControl}>
      <EmptyState icon={<RefreshCw size={30} color={theme.brandIcon} />}
        title={readErrorKind === 'missing' ? 'Task unavailable' : readErrorKind === 'offline' ? 'You are offline' : readErrorKind === 'forbidden' ? 'Access changed' : 'Could not load task'}
        body={readErrorKind === 'forbidden' ? 'Your access to this Team Workspace is being checked. You can retry after your access is restored.' : teamTaskErrorMessage(query.error, 'Try loading the task again.')}
        action={<Action full disabled={submitting || query.eligible === false} loading={query.isFetching} onPress={() => { refresh().catch(() => undefined); }}>Try again</Action>} />
    </Screen>;
  }

  return (
    <Screen header={header} keyboardAware refreshControl={refreshControl}>
      {query.isFetching && !refreshing ? <View style={styles.refreshStatus}><ActivityIndicator size="small" color={theme.brandIcon} /><Text style={styles.muted}>Updating task…</Text></View> : null}
      {query.isError ? <View accessibilityRole="alert" style={styles.notice}><Text style={styles.noticeText}>{teamTaskErrorMessage(query.error, 'Could not refresh. Showing the last available task details.')}</Text><Action variant="secondary" disabled={submitting || query.eligible === false} loading={query.isFetching} onPress={() => { refresh().catch(() => undefined); }}>Retry refresh</Action></View> : null}
      <View style={styles.card}>
        <View style={styles.titleRow}><Text accessibilityRole="header" style={styles.taskTitle}>{task.title}</Text><StatusPill tone={STATUS_PRESENTATION[task.status].tone}>{STATUS_PRESENTATION[task.status].label}</StatusPill></View>
        <View style={styles.factGrid}>
          <Fact label="Priority" value={PRIORITY_LABEL[task.priority]} /><Fact label="Assignee" value={task.assignee?.name ?? 'Unassigned'} />
          <Fact label="Due date" value={task.due_date ? formatManilaDayLabel(task.due_date) : 'No due date'} /><Fact label="Updated" value={formatDateTime(task.updated_at)} />
        </View>
        {task.description.trim() !== '' ? <Text style={styles.description}>{task.description}</Text> : null}
      </View>

      {!task.can_edit ? <Text style={styles.limitNote}>Only the task creator, assignee, or a unit manager can change this task. You can still add a comment.</Text> : null}
      <SectionTitle title="Status" />
      <View accessibilityRole="radiogroup" accessibilityLabel="Task status" style={styles.statusControls}>
        {TASK_STATUSES.map(status => <Pressable key={status} accessibilityRole="radio" accessibilityLabel={`Status: ${STATUS_PRESENTATION[status].label}`}
          accessibilityState={{ checked: task.status === status, disabled: locked || !task.can_edit }} disabled={locked || !task.can_edit} onPress={() => changeStatus(status)}
          style={({ pressed }) => [styles.statusButton, task.status === status && styles.statusButtonSelected, pressed && styles.pressed, (locked || !task.can_edit) && styles.disabled]}>
          {task.status === status ? <Check size={15} color="#ffffff" /> : null}<Text style={[styles.statusButtonText, task.status === status && styles.statusButtonTextSelected]}>{STATUS_PRESENTATION[status].label}</Text>
        </Pressable>)}
      </View>
      {inFlight === 'status' ? <ActivityIndicator accessibilityLabel="Saving status" style={styles.inlineLoader} color={theme.brandIcon} /> : null}

      <SectionTitle title={`Checklist (${task.checklist_progress.done} of ${task.checklist_progress.total})`} />
      <View style={styles.card}>
        {task.checklist.length === 0 ? <Text style={styles.muted}>No checklist items.</Text> : task.checklist.map((item, index) => {
          const clientId = checklistIds.current[index] ?? (checklistIds.current[index] = checklistDraftItem(item).clientId);
          return <Pressable key={clientId} accessibilityRole="checkbox" accessibilityLabel={item.text} accessibilityHint={task.can_edit ? 'Updates this checklist item' : 'This task is read-only'}
            accessibilityState={{ checked: item.done, disabled: locked || !task.can_edit }} disabled={locked || !task.can_edit} onPress={() => toggleChecklist(index)}
            style={({ pressed }) => [styles.checklistRow, pressed && styles.pressed, locked && styles.disabled]}>
            {item.done ? <CheckSquare2 size={20} color={theme.brandIcon} /> : <Square size={20} color={theme.ink400} />}
            <Text style={[styles.checklistText, item.done && styles.checklistDone]}>{item.text}</Text>
          </Pressable>;
        })}
        {inFlight === 'checklist' ? <ActivityIndicator accessibilityLabel="Saving checklist" color={theme.brandIcon} /> : null}
      </View>

      {failure && failure.request.kind !== 'comment' ? <View style={styles.inlineError}>
        <Text accessibilityRole="alert" style={styles.errorText}>{teamTaskErrorMessage(failure.error, 'The task could not be updated.')}</Text>
        {uncertain ? <Text style={styles.errorHint}>The change may have reached the server. Retry the original versioned request, or review the latest task. No changes are retried automatically.</Text> : null}
        {uncertain && failureKind !== 'idempotency' ? <Action variant="secondary" disabled={submitting || !task.can_edit} onPress={retryOriginal}>{failure.request.kind === 'status' ? 'Retry status change' : 'Retry checklist update'}</Action> : null}
        <Action variant="secondary" disabled={submitting || query.eligible === false} loading={query.isFetching} onPress={() => { refresh(false, true).catch(() => undefined); }}>Review latest</Action>
        {uncertain ? <Action variant="ghost" disabled={submitting} onPress={() => leave(() => { refresh().catch(() => undefined); })}>Discard pending change</Action> : null}
      </View> : null}

      <SectionTitle title={`Comments (${task.comments_total})`} />
      <View style={styles.card}>
        {task.comments.length === 0 ? <Text style={styles.muted}>No comments yet.</Text> : task.comments.map(comment => <View key={comment.id} style={styles.timelineItem}>
          <View style={styles.timelineHeader}><Text style={styles.actor}>{comment.actor.name}</Text><Text style={styles.timestamp}>{formatDateTime(comment.created_at)}</Text></View><Text style={styles.bodyText}>{comment.body}</Text>
        </View>)}
        {task.comments_total > task.comments.length ? <Text style={styles.limitNote}>Showing the latest {task.comments.length} of {task.comments_total} comments.</Text> : null}
        <TextField label="Add a comment" accessibilityLabel="Comment" keyboardRevealOffset={72} value={commentBody} onChangeText={changeComment} placeholder="Share an update" multiline maxLength={2000} style={styles.commentInput} editable={!locked} />
        {failure?.request.kind === 'comment' ? <View style={styles.inlineError}>
          <Text accessibilityRole="alert" style={styles.errorText}>{teamTaskErrorMessage(failure.error, 'Could not add the comment.')}</Text>
          {uncertain ? <Text style={styles.errorHint}>Your comment may already have been received. Its original text and retry identifier are kept unchanged.</Text> : null}
          {uncertain && failureKind !== 'idempotency' ? <Action variant="secondary" disabled={submitting} onPress={retryOriginal}>Retry original comment</Action> : null}
          {needsReview ? <Action variant="secondary" disabled={submitting || query.eligible === false} loading={query.isFetching} onPress={() => { refresh(false, true).catch(() => undefined); }}>Review latest</Action> : null}
          {uncertain ? <Action variant="ghost" disabled={submitting} onPress={() => leave(() => undefined)}>Discard comment draft</Action> : null}
        </View> : null}
        <Action full style={styles.commentAction} icon={<MessageSquare size={17} color="#ffffff" />} loading={inFlight === 'comment'} disabled={locked || commentBody.trim() === ''} onPress={submitComment}>Add comment</Action>
      </View>

      <SectionTitle title={`Activity (${task.activity_total})`} />
      <View style={styles.card}>
        {task.activity.length === 0 ? <Text style={styles.muted}>No activity recorded.</Text> : task.activity.map(activity => <View key={activity.id} style={styles.timelineItem}>
          <View style={styles.timelineHeader}><Text style={styles.actor}>{activity.actor.name}</Text><Text style={styles.timestamp}>{formatDateTime(activity.created_at)}</Text></View>
          <Text style={styles.bodyText}>{activityLabel(activity.event, activity.old_status, activity.new_status)}</Text>
        </View>)}
        {task.activity_total > task.activity.length ? <Text style={styles.limitNote}>Showing the latest {task.activity.length} of {task.activity_total} activity entries.</Text> : null}
      </View>
    </Screen>
  );
}

function SectionTitle({ title }: { title: string }) {
  const { theme } = useTheme();
  return <Text accessibilityRole="header" style={makeStyles(theme).sectionTitle}>{title}</Text>;
}
function Fact({ label, value }: { label: string; value: string }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <View style={styles.fact}><Text style={styles.factLabel}>{label}</Text><Text style={styles.factValue}>{value}</Text></View>;
}
function activityLabel(event: string, oldStatus: TeamTaskStatus | null, newStatus: TeamTaskStatus | null): string {
  const label = event.replace(/_/g, ' ');
  return oldStatus && newStatus ? `${label}: ${STATUS_PRESENTATION[oldStatus].label} to ${STATUS_PRESENTATION[newStatus].label}` : label;
}
function createStyles(theme: Theme) {
  return StyleSheet.create({
    center: { alignItems: 'center', justifyContent: 'center' },
    refreshStatus: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 10 },
    notice: { gap: 10, marginBottom: 12, padding: 12, borderRadius: 12, backgroundColor: theme.brand50 }, noticeText: { ...type.body, color: theme.ink700 },
    card: { padding: 14, borderRadius: 15, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    titleRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 }, taskTitle: { ...type.cardTitle, flex: 1, color: theme.ink900 },
    factGrid: { flexDirection: 'row', flexWrap: 'wrap', marginTop: 12, rowGap: 10 }, fact: { width: '50%', paddingRight: 8 }, factLabel: { ...type.micro, color: theme.ink500, textTransform: 'uppercase' }, factValue: { ...type.bodyStrong, marginTop: 2, color: theme.ink700 },
    description: { ...type.body, marginTop: 13, lineHeight: 18, color: theme.ink700 }, sectionTitle: { ...type.cardTitle, marginTop: 20, marginBottom: 8, color: theme.ink900 },
    statusControls: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 }, statusButton: { minHeight: 44, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 5, paddingHorizontal: 12, borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface }, statusButtonSelected: { borderColor: theme.brand, backgroundColor: theme.brand }, statusButtonText: { ...type.bodyStrong, color: theme.ink700 }, statusButtonTextSelected: { color: '#ffffff' },
    inlineLoader: { marginTop: 10 }, inlineError: { gap: 8, marginVertical: 10 }, errorText: { ...type.bodyStrong, color: theme.mode === 'dark' ? '#f2a7a7' : '#a33b3b' }, errorHint: { ...type.caption, color: theme.ink500, lineHeight: 16 },
    checklistRow: { minHeight: 44, flexDirection: 'row', alignItems: 'center', gap: 10, borderRadius: 8 }, checklistText: { ...type.body, flex: 1, color: theme.ink700 }, checklistDone: { color: theme.ink500, textDecorationLine: 'line-through' }, muted: { ...type.body, color: theme.ink500 },
    timelineItem: { paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: theme.line }, timelineHeader: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', gap: 8 }, actor: { ...type.bodyStrong, flex: 1, color: theme.ink900 }, timestamp: { fontFamily: font.body, fontSize: 9.5, color: theme.ink500 }, bodyText: { ...type.body, marginTop: 4, lineHeight: 17, color: theme.ink700 }, limitNote: { ...type.caption, marginTop: 10, lineHeight: 16, color: theme.ink500 }, commentInput: { minHeight: 92, paddingTop: 12, textAlignVertical: 'top' },
    commentAction: { marginTop: 12 },
    pressed: { opacity: 0.7 }, disabled: { opacity: 0.5 },
  });
}
const makeStyles = memoizeStyles(createStyles);
