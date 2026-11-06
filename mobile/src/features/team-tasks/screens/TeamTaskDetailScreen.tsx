import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useQueryClient } from '@tanstack/react-query';
import {
  Check,
  CheckSquare2,
  Edit3,
  MessageSquare,
  RefreshCw,
  Square,
  Users,
} from 'lucide-react-native';
import type { TeamTaskStatus } from '../../../api/endpoints/teamTasks';
import { queryKeys } from '../../../api/queryKeys';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { StatusPill } from '../../../components/StatusPill';
import { TextField } from '../../../components/TextField';
import { formatDateTime } from '../../../lib/format';
import { secureClientNonce } from '../../../lib/secureNonce';
import type { TeamTasksStackParamList } from '../../../navigation/types';
import { useAuth } from '../../../store/auth';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { teamTaskErrorKind, teamTaskErrorMessage } from '../errors';
import { useChangeTeamTaskStatus, useCommentTeamTask, useTeamTask } from '../hooks';
import { PRIORITY_LABEL, STATUS_PRESENTATION, TASK_STATUSES } from '../model';

type DetailRoute = RouteProp<TeamTasksStackParamList, 'TeamTaskDetail'>;
type Navigation = NativeStackNavigationProp<TeamTasksStackParamList>;

export function TeamTaskDetailScreen() {
  const route = useRoute<DetailRoute>();
  const navigation = useNavigation<Navigation>();
  const queryClient = useQueryClient();
  const { retryBootstrap, user } = useAuth();
  const {
    activeUnit,
    openUnitPicker,
    registerDirtyDraft,
    requestDiscard,
  } = useWorkspace();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const id = route.params.id;
  const unit = activeUnit?.unit ?? null;
  const query = useTeamTask(unit, id);
  const statusMutation = useChangeTeamTaskStatus(unit, id);
  const commentMutation = useCommentTeamTask(unit, id);
  const [commentBody, setCommentBody] = useState('');
  const pendingComment = useRef<{ body: string; client_nonce: string } | null>(null);
  const accessRecoveryStarted = useRef(false);
  const task = query.data?.data;

  const recoverAccess = useCallback(async () => {
    if (accessRecoveryStarted.current || !activeUnit || !user) {
      return;
    }
    accessRecoveryStarted.current = true;
    queryClient.removeQueries({
      queryKey: queryKeys.teamUnit(user.tenant_id, user.id, activeUnit.unit),
      exact: false,
    });
    if (navigation.canGoBack()) {
      navigation.goBack();
    }
    await retryBootstrap().catch(() => undefined);
  }, [activeUnit, navigation, queryClient, retryBootstrap, user]);

  const queryErrorKind = query.isError ? teamTaskErrorKind(query.error) : null;
  useEffect(() => {
    if (queryErrorKind === 'forbidden') {
      recoverAccess().catch(() => undefined);
    }
  }, [queryErrorKind, recoverAccess]);

  const commentDirty = commentBody.trim().length > 0;
  useEffect(() => {
    if (!commentDirty) {
      return undefined;
    }
    return registerDirtyDraft(`team-task-comment:${unit}:${id}`);
  }, [commentDirty, id, registerDirtyDraft, unit]);

  useEffect(() => navigation.addListener('beforeRemove', event => {
    if (!commentDirty || commentMutation.isPending) {
      return;
    }
    event.preventDefault();
    requestDiscard(() => navigation.dispatch(event.data.action));
  }), [commentDirty, commentMutation.isPending, navigation, requestDiscard]);

  const submitComment = async () => {
    const body = commentBody.trim();
    if (body === '' || commentMutation.isPending) {
      return;
    }
    const payload = pendingComment.current?.body === body
      ? pendingComment.current
      : { body, client_nonce: secureClientNonce() };
    pendingComment.current = payload;
    try {
      await commentMutation.mutateAsync(payload);
      pendingComment.current = null;
      setCommentBody('');
    } catch (error) {
      if (teamTaskErrorKind(error) === 'forbidden') {
        recoverAccess().catch(() => undefined);
      }
    }
  };

  const changeStatus = async (status: TeamTaskStatus) => {
    if (!task || task.status === status || statusMutation.isPending) {
      return;
    }
    try {
      await statusMutation.mutateAsync({ status, version: task.version });
    } catch (error) {
      if (teamTaskErrorKind(error) === 'forbidden') {
        recoverAccess().catch(() => undefined);
      }
    }
  };

  const reviewLatest = () => {
    statusMutation.reset();
    query.refetch().catch(() => undefined);
  };

  const header = (
    <PageHeader
      edgeToEdge
      title="Task details"
      subtitle={activeUnit?.label ?? 'Team Workspace'}
      onBack={() => requestDiscard(navigation.goBack)}
      action={(
        <View style={styles.headerActions}>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Switch Team Workspace unit"
            onPress={() => requestDiscard(openUnitPicker)}
            style={styles.headerButton}>
            <Users size={19} color={theme.brandIcon} />
          </Pressable>
          {task?.can_edit ? (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Edit task"
              onPress={() => requestDiscard(() => navigation.navigate('TeamTaskForm', { id }))}
              style={styles.headerButton}>
              <Edit3 size={18} color={theme.brandIcon} />
            </Pressable>
          ) : null}
        </View>
      )}
    />
  );

  if (!activeUnit) {
    return (
      <Screen header={header}>
        <EmptyState
          icon={<Users size={30} color={theme.brandIcon} />}
          title="Choose a Team Workspace unit"
          body="Select a unit to view this task."
          action={<Action full onPress={openUnitPicker}>Choose unit</Action>}
        />
      </Screen>
    );
  }

  if (query.isLoading) {
    return (
      <Screen scroll={false} header={header} bodyStyle={styles.center}>
        <ActivityIndicator accessibilityLabel="Loading task" color={theme.brandIcon} />
      </Screen>
    );
  }

  if (query.isError && !task) {
    const kind = teamTaskErrorKind(query.error);
    return (
      <Screen header={header}>
        <EmptyState
          icon={<RefreshCw size={30} color={theme.brandIcon} />}
          title={kind === 'missing' ? 'Task unavailable' : kind === 'offline' ? 'You are offline' : kind === 'forbidden' ? 'Access changed' : 'Could not load task'}
          body={teamTaskErrorMessage(query.error, 'Try loading the task again.')}
          action={kind === 'forbidden' ? undefined : <Action full onPress={() => { query.refetch().catch(() => undefined); }}>Try again</Action>}
        />
      </Screen>
    );
  }

  if (!task) {
    return null;
  }

  const statusErrorKind = statusMutation.isError ? teamTaskErrorKind(statusMutation.error) : null;
  const commentErrorKind = commentMutation.isError ? teamTaskErrorKind(commentMutation.error) : null;

  return (
    <Screen header={header}>
      {query.isError ? (
        <View accessibilityRole="alert" style={styles.notice}>
          <Text style={styles.noticeText}>{teamTaskErrorMessage(query.error, 'Could not refresh. Showing the last available task details.')}</Text>
        </View>
      ) : null}

      <View style={styles.card}>
        <View style={styles.titleRow}>
          <Text style={styles.taskTitle}>{task.title}</Text>
          <StatusPill tone={STATUS_PRESENTATION[task.status].tone}>{STATUS_PRESENTATION[task.status].label}</StatusPill>
        </View>
        <View style={styles.factGrid}>
          <Fact label="Priority" value={PRIORITY_LABEL[task.priority]} />
          <Fact label="Assignee" value={task.assignee?.name ?? 'Unassigned'} />
          <Fact label="Due date" value={task.due_date ?? 'No due date'} />
          <Fact label="Updated" value={formatTimestamp(task.updated_at)} />
        </View>
        {task.description.trim() !== '' ? <Text style={styles.description}>{task.description}</Text> : null}
      </View>

      <SectionTitle title="Status" />
      <View style={styles.statusControls}>
        {TASK_STATUSES.map(status => (
          <Pressable
            key={status}
            accessibilityRole="button"
            accessibilityState={{ selected: task.status === status, disabled: statusMutation.isPending }}
            disabled={statusMutation.isPending}
            onPress={() => { changeStatus(status).catch(() => undefined); }}
            style={[styles.statusButton, task.status === status && styles.statusButtonSelected]}>
            {task.status === status ? <Check size={15} color="#ffffff" /> : null}
            <Text style={[styles.statusButtonText, task.status === status && styles.statusButtonTextSelected]}>{STATUS_PRESENTATION[status].label}</Text>
          </Pressable>
        ))}
      </View>
      {statusMutation.isPending ? <ActivityIndicator style={styles.inlineLoader} color={theme.brandIcon} /> : null}
      {statusMutation.isError ? (
        <View accessibilityRole="alert" style={styles.inlineError}>
          <Text style={styles.errorText}>{teamTaskErrorMessage(statusMutation.error, 'Could not change the task status.')}</Text>
          {statusErrorKind === 'stale' ? <Action variant="secondary" onPress={reviewLatest}>Review latest</Action> : null}
          {statusErrorKind === 'offline' ? <Action variant="secondary" onPress={() => { changeStatus(statusMutation.variables.status).catch(() => undefined); }}>Retry</Action> : null}
        </View>
      ) : null}

      <SectionTitle title={`Checklist (${task.checklist_progress.done} of ${task.checklist_progress.total})`} />
      <View style={styles.card}>
        {task.checklist.length === 0 ? (
          <Text style={styles.muted}>No checklist items.</Text>
        ) : task.checklist.map((item, index) => (
          <View key={`${index}:${item.text}`} style={styles.checklistRow}>
            {item.done
              ? <CheckSquare2 size={20} color={theme.brandIcon} />
              : <Square size={20} color={theme.ink400} />}
            <Text style={[styles.checklistText, item.done && styles.checklistDone]}>{item.text}</Text>
          </View>
        ))}
      </View>

      <SectionTitle title={`Comments (${task.comments_total})`} />
      <View style={styles.card}>
        {task.comments.length === 0 ? (
          <Text style={styles.muted}>No comments yet.</Text>
        ) : task.comments.map(comment => (
          <View key={comment.id} style={styles.timelineItem}>
            <View style={styles.timelineHeader}>
              <Text style={styles.actor}>{comment.actor.name}</Text>
              <Text style={styles.timestamp}>{formatTimestamp(comment.created_at)}</Text>
            </View>
            <Text style={styles.bodyText}>{comment.body}</Text>
          </View>
        ))}
        {task.comments_total > task.comments.length ? (
          <Text style={styles.limitNote}>Showing the latest {task.comments.length} of {task.comments_total} comments.</Text>
        ) : null}
        <TextField
          label="Add a comment"
          value={commentBody}
          onChangeText={value => {
            setCommentBody(value);
            if (pendingComment.current?.body !== value.trim()) {
              pendingComment.current = null;
              commentMutation.reset();
            }
          }}
          placeholder="Share an update"
          multiline
          maxLength={2000}
          style={styles.commentInput}
          editable={!commentMutation.isPending}
        />
        {commentMutation.isError ? (
          <View accessibilityRole="alert" style={styles.inlineError}>
            <Text style={styles.errorText}>{teamTaskErrorMessage(commentMutation.error, 'Could not add the comment.')}</Text>
            {commentErrorKind === 'idempotency' ? <Text style={styles.errorHint}>Edit the comment to create a new request, or review the task before retrying.</Text> : null}
          </View>
        ) : null}
        <Action
          full
          loading={commentMutation.isPending}
          disabled={commentBody.trim() === ''}
          onPress={() => { submitComment().catch(() => undefined); }}>
          <MessageSquare size={17} color="#ffffff" />
          Add comment
        </Action>
      </View>

      <SectionTitle title={`Activity (${task.activity_total})`} />
      <View style={styles.card}>
        {task.activity.length === 0 ? (
          <Text style={styles.muted}>No activity recorded.</Text>
        ) : task.activity.map(activity => (
          <View key={activity.id} style={styles.timelineItem}>
            <View style={styles.timelineHeader}>
              <Text style={styles.actor}>{activity.actor.name}</Text>
              <Text style={styles.timestamp}>{formatTimestamp(activity.created_at)}</Text>
            </View>
            <Text style={styles.bodyText}>{activityLabel(activity.event, activity.old_status, activity.new_status)}</Text>
          </View>
        ))}
        {task.activity_total > task.activity.length ? (
          <Text style={styles.limitNote}>Showing the latest {task.activity.length} of {task.activity_total} activity entries.</Text>
        ) : null}
      </View>
    </Screen>
  );
}

function SectionTitle({ title }: { title: string }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return <Text accessibilityRole="header" style={styles.sectionTitle}>{title}</Text>;
}

function Fact({ label, value }: { label: string; value: string }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.fact}>
      <Text style={styles.factLabel}>{label}</Text>
      <Text style={styles.factValue}>{value}</Text>
    </View>
  );
}

function formatTimestamp(value: string): string {
  return formatDateTime(value);
}

function activityLabel(event: string, oldStatus: TeamTaskStatus | null, newStatus: TeamTaskStatus | null): string {
  if (oldStatus && newStatus) {
    return `${event}: ${STATUS_PRESENTATION[oldStatus].label} to ${STATUS_PRESENTATION[newStatus].label}`;
  }
  return event.replace(/_/g, ' ');
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    center: { alignItems: 'center', justifyContent: 'center' },
    headerActions: { flexDirection: 'row', gap: 8 },
    headerButton: {
      width: 44,
      height: 44,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 12,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    notice: { marginBottom: 12, padding: 12, borderRadius: 12, backgroundColor: theme.brand50 },
    noticeText: { ...type.body, color: theme.ink700 },
    card: {
      padding: 14,
      borderRadius: 15,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    titleRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
    taskTitle: { ...type.cardTitle, flex: 1, color: theme.ink900 },
    factGrid: { flexDirection: 'row', flexWrap: 'wrap', marginTop: 12, rowGap: 10 },
    fact: { width: '50%', paddingRight: 8 },
    factLabel: { ...type.micro, color: theme.ink500, textTransform: 'uppercase' },
    factValue: { ...type.bodyStrong, marginTop: 2, color: theme.ink700 },
    description: { ...type.body, marginTop: 13, lineHeight: 18, color: theme.ink700 },
    sectionTitle: { ...type.cardTitle, marginTop: 20, marginBottom: 8, color: theme.ink900 },
    statusControls: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    statusButton: {
      minHeight: 44,
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'center',
      gap: 5,
      paddingHorizontal: 12,
      borderRadius: 12,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    statusButtonSelected: { borderColor: theme.brand, backgroundColor: theme.brand },
    statusButtonText: { ...type.bodyStrong, color: theme.ink700 },
    statusButtonTextSelected: { color: '#ffffff' },
    inlineLoader: { marginTop: 10 },
    inlineError: { gap: 8, marginTop: 10 },
    errorText: { ...type.bodyStrong, color: '#a33b3b' },
    errorHint: { ...type.caption, color: theme.ink500 },
    checklistRow: { minHeight: 44, flexDirection: 'row', alignItems: 'center', gap: 10 },
    checklistText: { ...type.body, flex: 1, color: theme.ink700 },
    checklistDone: { color: theme.ink500, textDecorationLine: 'line-through' },
    muted: { ...type.body, color: theme.ink500 },
    timelineItem: { paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: theme.line },
    timelineHeader: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', gap: 8 },
    actor: { ...type.bodyStrong, flex: 1, color: theme.ink900 },
    timestamp: { fontFamily: font.body, fontSize: 9.5, color: theme.ink500 },
    bodyText: { ...type.body, marginTop: 4, lineHeight: 17, color: theme.ink700 },
    limitNote: { ...type.caption, marginTop: 10, color: theme.ink500 },
    commentInput: { minHeight: 92, paddingTop: 12, textAlignVertical: 'top' },
  });
}

const makeStyles = memoizeStyles(createStyles);
