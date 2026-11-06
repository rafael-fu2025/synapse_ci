import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
  type ListViewToken,
  type TextInputInstance,
  type TextInputSelectionChangeEvent,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { useNetInfo } from '@react-native-community/netinfo';
import { useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle,
  ArrowDown,
  Plus,
  Send,
  Users,
  X,
} from 'lucide-react-native';
import type {
  TeamChatMember,
  TeamChatMentionInput,
  TeamChatMessage,
  TeamChatSendInput,
  TeamChatTaskReference,
} from '../../../api/endpoints/teamChat';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { secureClientNonce } from '../../../lib/secureNonce';
import { useAuth } from '../../../store/auth';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { TeamChatMessageBubble } from '../components/TeamChatMessageBubble';
import { TeamChatTaskPicker } from '../components/TeamChatTaskPicker';
import {
  purgeTeamChatUnit,
  useSendTeamChatMessage,
  useTeamChatChannel,
  useTeamChatIdentity,
  useTeamChatMemberSearch,
} from '../hooks';
import {
  buildChatRows,
  insertMention,
  isAmbiguousSendFailure,
  isChatDirty,
  mentionQueryAt,
  messageValidation,
  normalizeMentions,
  teamChatErrorKind,
  teamChatErrorMessage,
  updateMentionRanges,
  type MentionQuery,
  type TeamChatRow,
} from '../model';

interface PendingSend {
  input: TeamChatSendInput;
}

interface ComposerSelection {
  start: number;
  end: number;
}

function compact(value: string, maximum = 180): string {
  const characters = Array.from(value.replace(/\s+/g, ' ').trim());
  return characters.length <= maximum
    ? characters.join('')
    : `${characters.slice(0, maximum).join('')}…`;
}

export function TeamChatScreen({ targetMessageId }: { targetMessageId?: number }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation();
  const network = useNetInfo();
  const queryClient = useQueryClient();
  const identity = useTeamChatIdentity();
  const { user, retryBootstrap } = useAuth();
  const {
    activeUnit,
    enterPersonal,
    openUnitPicker,
    registerDirtyDraft,
    requestDiscard,
  } = useWorkspace();
  const unit = activeUnit?.unit ?? null;
  const channel = useTeamChatChannel({ unit, targetMessageId });
  const sendMessage = useSendTeamChatMessage(unit);
  const listRef = useRef<FlatList<TeamChatRow>>(null);
  const inputRef = useRef<TextInputInstance>(null);
  const bodyRef = useRef('');
  const acknowledgeRef = useRef(channel.acknowledge);
  const accessRecovery = useRef(false);
  const positioned = useRef(false);
  const [body, setBody] = useState('');
  const [selection, setSelection] = useState<ComposerSelection>({ start: 0, end: 0 });
  const [mentions, setMentions] = useState<TeamChatMentionInput[]>([]);
  const [tasks, setTasks] = useState<TeamChatTaskReference[]>([]);
  const [replyTo, setReplyTo] = useState<TeamChatMessage | null>(null);
  const [taskPickerVisible, setTaskPickerVisible] = useState(false);
  const [pending, setPending] = useState<PendingSend | null>(null);
  const [composerError, setComposerError] = useState<string | null>(null);
  const [mentionSearch, setMentionSearch] = useState('');
  const [now, setNow] = useState(Date.now());
  const locked = pending !== null || sendMessage.isPending;

  const mentionMatch = useMemo<MentionQuery | null>(() => {
    if (locked || selection.start !== selection.end) { return null; }
    const match = mentionQueryAt(body, selection.start);
    if (match === null) { return null; }
    const insideBoundMention = mentions.some(item =>
      match.start >= item.start && match.start < item.start + item.length,
    );
    return insideBoundMention ? null : match;
  }, [body, locked, mentions, selection]);

  useEffect(() => {
    const timer = setTimeout(() => setMentionSearch(mentionMatch?.query ?? ''), 200);
    return () => clearTimeout(timer);
  }, [mentionMatch?.query]);
  const memberSearch = useTeamChatMemberSearch(unit, mentionSearch, mentionMatch !== null);

  const rows = useMemo(
    () => buildChatRows(
      channel.messages,
      channel.summary.last_read_id,
      user?.id ?? 0,
      channel.mode === 'latest',
    ),
    [channel.messages, channel.mode, channel.summary.last_read_id, user?.id],
  );

  const dirty = !accessRecovery.current && isChatDirty({
    body,
    taskCount: tasks.length,
    hasReply: replyTo !== null,
    hasPendingSend: pending !== null,
  });

  useEffect(() => {
    acknowledgeRef.current = channel.acknowledge;
  }, [channel.acknowledge]);

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 5_000);
    return () => clearInterval(timer);
  }, []);

  useEffect(() => dirty
    ? registerDirtyDraft(`team-chat:${identity.tenantId}:${identity.userId}:${unit ?? 'none'}`)
    : undefined,
  [dirty, identity.tenantId, identity.userId, registerDirtyDraft, unit]);

  useEffect(() => navigation.addListener('beforeRemove', event => {
    if (!dirty || sendMessage.isPending) { return; }
    event.preventDefault();
    requestDiscard(() => navigation.dispatch(event.data.action));
  }), [dirty, navigation, requestDiscard, sendMessage.isPending]);

  const clearComposer = useCallback(() => {
    bodyRef.current = '';
    setBody('');
    setSelection({ start: 0, end: 0 });
    setMentions([]);
    setTasks([]);
    setReplyTo(null);
    setPending(null);
    setComposerError(null);
    setTaskPickerVisible(false);
    sendMessage.reset();
  }, [sendMessage]);

  useEffect(() => {
    accessRecovery.current = false;
    positioned.current = false;
    clearComposer();
  // The unit/identity transition owns draft disposal; clearComposer is intentionally
  // omitted so mutation object churn cannot erase typed text.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [identity.tenantId, identity.userId, unit]);

  const recoverProtectedAccess = useCallback(async (error: unknown) => {
    const kind = teamChatErrorKind(error);
    if (
      accessRecovery.current ||
      (kind !== 'unauthorized' && kind !== 'forbidden') ||
      !unit
    ) { return; }
    accessRecovery.current = true;
    clearComposer();
    channel.clear();
    await purgeTeamChatUnit(queryClient, identity, unit);
    if (kind === 'forbidden') {
      enterPersonal();
      await retryBootstrap().catch(() => undefined);
    }
  }, [channel, clearComposer, enterPersonal, identity, queryClient, retryBootstrap, unit]);

  const protectedError = channel.error ?? channel.updateError;
  useEffect(() => {
    if (protectedError) { recoverProtectedAccess(protectedError).catch(() => undefined); }
  }, [protectedError, recoverProtectedAccess]);

  const onViewableItemsChanged = useRef((info: { viewableItems: ListViewToken[] }) => {
    let last = 0;
    for (const token of info.viewableItems) {
      if (token.isViewable && token.item.kind === 'message') {
        last = Math.max(last, token.item.message.id);
      }
    }
    if (last > 0) { acknowledgeRef.current(last); }
  }).current;
  const viewabilityConfig = useRef({
    itemVisiblePercentThreshold: 100,
    minimumViewTime: 500,
  }).current;

  const positionInitialWindow = useCallback(() => {
    if (positioned.current || rows.length === 0) { return; }
    positioned.current = true;
    if (channel.mode === 'history' || channel.summary.last_read_id <= 0) {
      listRef.current?.scrollToEnd({ animated: false });
      return;
    }
    const unreadIndex = rows.findIndex(row => row.kind === 'unread');
    if (unreadIndex < 0) {
      listRef.current?.scrollToEnd({ animated: false });
      return;
    }
    try {
      listRef.current?.scrollToIndex({ index: unreadIndex, viewPosition: 0.08, animated: false });
    } catch {
      listRef.current?.scrollToEnd({ animated: false });
    }
  }, [channel.mode, channel.summary.last_read_id, rows]);

  function changeBody(next: string) {
    if (locked) { return; }
    const previous = bodyRef.current;
    setMentions(current => updateMentionRanges(previous, next, current));
    bodyRef.current = next;
    setBody(next);
    setComposerError(null);
    sendMessage.reset();
  }

  function changeSelection(event: TextInputSelectionChangeEvent) {
    setSelection(event.nativeEvent.selection);
  }

  function chooseMention(member: TeamChatMember) {
    if (!mentionMatch || locked) { return; }
    const inserted = insertMention(body, mentionMatch, selection.start, member);
    const shifted = updateMentionRanges(body, inserted.text, mentions);
    bodyRef.current = inserted.text;
    setBody(inserted.text);
    setMentions(normalizeMentions(inserted.text, [...shifted, inserted.mention]));
    setSelection({ start: inserted.cursor, end: inserted.cursor });
    setComposerError(null);
    requestAnimationFrame(() => inputRef.current?.focus());
  }

  async function submit() {
    if (!unit || locked && pending === null) { return; }
    let sendInput = pending?.input;
    if (sendInput === undefined) {
      const validation = messageValidation(body, tasks.length);
      if (validation !== null) { setComposerError(validation); return; }
      sendInput = {
        body,
        client_nonce: secureClientNonce(),
        task_ids: tasks.map(task => task.id),
        mentions: normalizeMentions(body, mentions),
        reply_to_id: replyTo?.id ?? null,
      };
      setPending({ input: sendInput });
    }
    setComposerError(null);
    try {
      await sendMessage.mutateAsync(sendInput);
      clearComposer();
      await channel.showLatest();
      requestAnimationFrame(() => {
        listRef.current?.scrollToEnd({ animated: true });
        inputRef.current?.focus();
      });
    } catch (error) {
      if (!isAmbiguousSendFailure(error)) {
        // Validation/conflict is a confirmed non-send. Unlock edits; the next
        // attempt receives a new nonce rather than mutating this one.
        setPending(null);
      }
      if (isAmbiguousSendFailure(error)) {
        setComposerError('Delivery is unconfirmed. Reconnect and Retry Send; the locked message will reuse the same secure token.');
      } else if (teamChatErrorKind(error) === 'validation') {
        setComposerError('Not sent. Correct the message or task references and try again.');
      } else {
        setComposerError(teamChatErrorMessage(error, 'The message could not be sent.'));
      }
      await recoverProtectedAccess(error);
    }
  }

  async function showLatest() {
    positioned.current = true;
    await channel.showLatest();
    requestAnimationFrame(() => listRef.current?.scrollToEnd({ animated: false }));
  }

  const offline = network.isConnected === false || network.isInternetReachable === false;
  const stale = channel.updatedAt > 0 && now - channel.updatedAt > 15_000;
  const refreshFailed = channel.updateError !== null && channel.messages.length > 0;
  const forbidden = teamChatErrorKind(protectedError) === 'forbidden';
  const unauthorized = teamChatErrorKind(protectedError) === 'unauthorized';
  const statusMessage = forbidden
    ? 'Team Workspace access changed. Protected chat data was removed.'
    : unauthorized
      ? 'Your session expired. Protected chat data was removed.'
      : offline
        ? 'Offline. Showing only messages already loaded in memory.'
        : refreshFailed
          ? 'Updates interrupted. Showing the last loaded messages and retrying while focused.'
          : stale
            ? 'Messages may be out of date. Pull to refresh.'
            : null;

  const header = (
    <PageHeader
      edgeToEdge
      title="Team Chat"
      subtitle={activeUnit?.label ?? 'Choose a Team Workspace'}
      action={(
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Switch Team Workspace unit"
          onPress={() => requestDiscard(openUnitPicker)}
          style={styles.headerButton}>
          <Users size={19} color={theme.brandIcon} />
        </Pressable>
      )}
    />
  );

  if (!unit) {
    return (
      <Screen scroll={false} header={header} bodyStyle={styles.center}>
        <Text accessibilityRole="alert" style={styles.emptyTitle}>Choose a Team Workspace unit first.</Text>
      </Screen>
    );
  }

  return (
    <Screen scroll={false} header={header} bodyStyle={styles.screen}>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        keyboardVerticalOffset={0}
        style={styles.flex}>
        <View style={styles.notice}>
          <AlertTriangle size={14} color={theme.ink500} />
          <Text style={styles.noticeText}>Operational messages only. Do not include patient or confidential counseling content.</Text>
        </View>
        {channel.mode === 'history' ? (
          <View style={styles.historyBanner}>
            <Text accessibilityLiveRegion="polite" style={styles.historyText}>
              Viewing history. Live updates and read acknowledgements are paused.
            </Text>
            <Pressable accessibilityRole="button" onPress={() => { showLatest().catch(() => undefined); }} style={styles.latestButton}>
              <ArrowDown size={16} color="#ffffff" />
              <Text style={styles.latestText}>Show Latest</Text>
            </Pressable>
          </View>
        ) : null}
        {statusMessage ? (
          <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={styles.status}>{statusMessage}</Text>
        ) : null}
        {channel.loadingInitial ? (
          <View style={styles.center} accessibilityLabel="Loading Team Chat messages">
            <ActivityIndicator color={theme.brandIcon} />
          </View>
        ) : channel.error && channel.messages.length === 0 && !forbidden && !unauthorized ? (
          <View style={styles.center}>
            <Text accessibilityRole="alert" style={styles.errorText}>
              {teamChatErrorMessage(channel.error, 'Could not load Team Chat.')}
            </Text>
            <Pressable accessibilityRole="button" onPress={() => { channel.refresh().catch(() => undefined); }} style={styles.retryButton}>
              <Text style={styles.retryText}>Try Again</Text>
            </Pressable>
          </View>
        ) : (
          <FlatList
            ref={listRef}
            testID="team-chat-history"
            data={rows}
            keyExtractor={item => item.key}
            renderItem={({ item }) => {
              if (item.kind === 'day') {
                return <Text accessibilityRole="header" style={styles.day}>{item.label}</Text>;
              }
              if (item.kind === 'unread') {
                return (
                  <View accessibilityLabel="Unread messages" style={styles.unreadRow}>
                    <View style={styles.unreadLine} />
                    <Text style={styles.unreadText}>Unread messages</Text>
                    <View style={styles.unreadLine} />
                  </View>
                );
              }
              return (
                <TeamChatMessageBubble
                  message={item.message}
                  own={item.message.sender.id === user?.id}
                  targeted={targetMessageId === item.message.id}
                  replyDisabled={locked || forbidden || unauthorized}
                  onReply={message => {
                    if (!locked) { setReplyTo(message); setComposerError(null); inputRef.current?.focus(); }
                  }}
                />
              );
            }}
            ListHeaderComponent={channel.hasOlder ? (
              <Pressable
                accessibilityRole="button"
                accessibilityState={{ busy: channel.loadingOlder }}
                disabled={channel.loadingOlder}
                onPress={() => { channel.loadOlder().catch(() => undefined); }}
                style={styles.olderButton}>
                {channel.loadingOlder ? <ActivityIndicator size="small" color={theme.brandIcon} /> : null}
                <Text style={styles.olderText}>{channel.loadingOlder ? 'Loading older messages…' : 'Load older messages'}</Text>
              </Pressable>
            ) : <View style={styles.listTop} />}
            ListEmptyComponent={<View style={styles.empty}><Text style={styles.emptyTitle}>No messages yet</Text><Text style={styles.emptyBody}>Start an operational team discussion.</Text></View>}
            refreshControl={<RefreshControl refreshing={channel.polling} onRefresh={() => { channel.refresh().catch(() => undefined); }} tintColor={theme.brandIcon} />}
            keyboardDismissMode="interactive"
            keyboardShouldPersistTaps="handled"
            maintainVisibleContentPosition={{ minIndexForVisible: 0 }}
            onViewableItemsChanged={onViewableItemsChanged}
            viewabilityConfig={viewabilityConfig}
            onContentSizeChange={positionInitialWindow}
            onScrollToIndexFailed={() => listRef.current?.scrollToEnd({ animated: false })}
            contentContainerStyle={[styles.list, rows.length === 0 && styles.emptyList]}
            style={styles.listViewport}
          />
        )}

        {mentionMatch !== null ? (
          <View style={styles.suggestions} accessibilityLabel="Mention team member suggestions">
            {memberSearch.isFetching ? <ActivityIndicator size="small" color={theme.brandIcon} style={styles.suggestionLoader} /> : null}
            {(memberSearch.data?.data ?? []).map(member => (
              <Pressable
                key={member.id}
                accessibilityRole="button"
                accessibilityLabel={`Mention ${member.name}`}
                onPress={() => chooseMention(member)}
                style={({ pressed }) => [styles.suggestion, pressed && styles.pressed]}>
                <Text style={styles.suggestionName}>@{member.name}</Text>
                <Text numberOfLines={1} style={styles.suggestionRole}>{member.role.replace(/_/g, ' ')}</Text>
              </Pressable>
            ))}
            {!memberSearch.isFetching && memberSearch.data && memberSearch.data.data.length === 0 ? (
              <Text style={styles.noSuggestion}>No matching team members.</Text>
            ) : null}
          </View>
        ) : null}

        <View style={styles.composerShell}>
          {replyTo ? (
            <View style={styles.replyPreview}>
              <View style={styles.replyCopy}>
                <Text style={styles.replyTitle}>Replying to {replyTo.sender.name}</Text>
                <Text numberOfLines={2} style={styles.replyBody}>{compact(replyTo.body)}</Text>
              </View>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel="Cancel reply"
                accessibilityState={{ disabled: locked }}
                disabled={locked}
                onPress={() => setReplyTo(null)}
                style={styles.previewRemove}>
                <X size={18} color={theme.ink500} />
              </Pressable>
            </View>
          ) : null}
          {tasks.length > 0 ? (
            <ScrollView horizontal showsHorizontalScrollIndicator={false} keyboardShouldPersistTaps="handled" contentContainerStyle={styles.taskChips}>
              {tasks.map(task => (
                <Pressable
                  key={task.id}
                  accessibilityRole="button"
                  accessibilityLabel={`Remove task ${task.id}: ${task.title}`}
                  accessibilityState={{ disabled: locked }}
                  disabled={locked}
                  onPress={() => setTasks(current => current.filter(item => item.id !== task.id))}
                  style={styles.taskChip}>
                  <Text numberOfLines={1} style={styles.taskChipText}>#{task.id}: {task.title}</Text>
                  <X size={15} color={theme.brandIcon} />
                </Pressable>
              ))}
            </ScrollView>
          ) : null}
          {composerError ? (
            <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={styles.composerError}>{composerError}</Text>
          ) : null}
          {pending ? (
            <Text accessibilityLiveRegion="polite" style={styles.lockedStatus}>
              Delivery is unconfirmed. Text, reply, mentions, and task references stay locked; Retry Send reuses the same secure token.
            </Text>
          ) : null}
          <View style={styles.composerRow}>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Add task references"
              accessibilityHint="The plus button adds active task references; attachments are not supported."
              accessibilityState={{ disabled: locked }}
              disabled={locked}
              onPress={() => setTaskPickerVisible(true)}
              style={({ pressed }) => [styles.plusButton, locked && styles.disabled, pressed && styles.pressed]}>
              <Plus size={21} color={theme.brandIcon} />
            </Pressable>
            <TextInput
              ref={inputRef}
              accessibilityLabel="Team message"
              accessibilityHint="Type at sign to mention a current team member."
              value={body}
              selection={selection}
              onSelectionChange={changeSelection}
              onChangeText={changeBody}
              placeholder={locked ? 'Message locked until delivery is resolved' : 'Message the team'}
              placeholderTextColor={theme.ink400}
              editable={!locked && !forbidden && !unauthorized}
              multiline
              maxLength={4_000}
              textAlignVertical="top"
              style={styles.input}
            />
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={pending ? 'Retry send' : 'Send message'}
              accessibilityState={{ disabled: body.trim() === '' || sendMessage.isPending, busy: sendMessage.isPending }}
              disabled={body.trim() === '' || sendMessage.isPending || forbidden || unauthorized}
              onPress={() => { submit().catch(() => undefined); }}
              style={({ pressed }) => [styles.sendButton, (body.trim() === '' || forbidden || unauthorized) && styles.disabled, pressed && styles.pressed]}>
              {sendMessage.isPending ? <ActivityIndicator size="small" color="#ffffff" /> : <Send size={19} color="#ffffff" />}
            </Pressable>
          </View>
        </View>
      </KeyboardAvoidingView>
      <TeamChatTaskPicker
        unit={unit}
        visible={taskPickerVisible}
        selected={tasks}
        onChange={next => { if (!locked) { setTasks(next); setComposerError(null); } }}
        onClose={() => setTaskPickerVisible(false)}
      />
    </Screen>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { paddingHorizontal: 0, paddingBottom: 0 },
    flex: { flex: 1 },
    headerButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    notice: { minHeight: 36, flexDirection: 'row', alignItems: 'center', gap: 7, paddingHorizontal: 12, paddingVertical: 7, borderBottomWidth: 1, borderBottomColor: theme.line, backgroundColor: theme.surfaceWarm },
    noticeText: { ...type.caption, flex: 1, color: theme.ink500 },
    historyBanner: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 12, paddingVertical: 6, backgroundColor: '#faedcf' },
    historyText: { ...type.caption, flex: 1, color: '#6b4a0c' },
    latestButton: { minHeight: 44, flexDirection: 'row', alignItems: 'center', gap: 5, paddingHorizontal: 12, borderRadius: 12, backgroundColor: theme.brand },
    latestText: { ...type.bodyStrong, color: '#ffffff' },
    status: { ...type.caption, paddingHorizontal: 12, paddingVertical: 8, color: '#7b4f0b', backgroundColor: '#fff5dc' },
    listViewport: { flex: 1 },
    list: { paddingBottom: 10 },
    emptyList: { flexGrow: 1 },
    listTop: { height: 8 },
    olderButton: { minHeight: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, marginHorizontal: 12, marginVertical: 8 },
    olderText: { ...type.bodyStrong, color: theme.brandText },
    day: { ...type.caption, alignSelf: 'center', marginVertical: 9, paddingHorizontal: 12, paddingVertical: 5, borderRadius: 999, color: theme.ink500, backgroundColor: theme.surface },
    unreadRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginHorizontal: 12, marginVertical: 8 },
    unreadLine: { flex: 1, height: 1, backgroundColor: theme.brand },
    unreadText: { ...type.caption, color: theme.brandText },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, padding: 24 },
    empty: { alignItems: 'center', justifyContent: 'center', gap: 5, padding: 36 },
    emptyTitle: { ...type.cardTitle, color: theme.ink900, textAlign: 'center' },
    emptyBody: { ...type.body, color: theme.ink500, textAlign: 'center' },
    errorText: { ...type.body, color: '#a33b3b', textAlign: 'center' },
    retryButton: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 18, borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    retryText: { ...type.action, color: theme.brandText },
    suggestions: { maxHeight: 184, borderTopWidth: 1, borderTopColor: theme.line, backgroundColor: theme.surface },
    suggestionLoader: { paddingVertical: 8 },
    suggestion: { minHeight: 48, flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 14, borderBottomWidth: 1, borderBottomColor: theme.line },
    suggestionName: { ...type.bodyStrong, flex: 1, color: theme.ink900 },
    suggestionRole: { ...type.caption, maxWidth: '38%', color: theme.ink500, textTransform: 'capitalize' },
    noSuggestion: { ...type.caption, padding: 14, color: theme.ink500, textAlign: 'center' },
    composerShell: { paddingHorizontal: 10, paddingTop: 7, paddingBottom: 8, borderTopWidth: 1, borderTopColor: theme.line, backgroundColor: theme.surface },
    replyPreview: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 6, paddingLeft: 10, borderLeftWidth: 3, borderLeftColor: theme.brand, backgroundColor: theme.surfaceWarm },
    replyCopy: { flex: 1, paddingVertical: 6 },
    replyTitle: { ...type.caption, color: theme.ink700 },
    replyBody: { ...type.caption, marginTop: 2, color: theme.ink500 },
    previewRemove: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
    taskChips: { gap: 6, paddingBottom: 7 },
    taskChip: { minHeight: 44, maxWidth: 240, flexDirection: 'row', alignItems: 'center', gap: 5, paddingHorizontal: 10, borderRadius: 12, borderWidth: 1, borderColor: theme.brand100, backgroundColor: theme.brand50 },
    taskChipText: { ...type.caption, flexShrink: 1, color: theme.brandText },
    composerError: { ...type.caption, marginBottom: 5, color: '#a33b3b' },
    lockedStatus: { ...type.caption, marginBottom: 5, color: '#7b4f0b' },
    composerRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 7 },
    plusButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surfaceWarm },
    input: { flex: 1, minHeight: 44, maxHeight: 104, paddingHorizontal: 12, paddingVertical: 10, borderRadius: 14, borderWidth: 1, borderColor: theme.line, color: theme.ink900, backgroundColor: theme.surfaceWarm, fontFamily: font.body, fontSize: 12, lineHeight: 19 },
    sendButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, backgroundColor: theme.brand },
    disabled: { opacity: 0.45 },
    pressed: { opacity: 0.68 },
  });
}

const makeStyles = memoizeStyles(createStyles);
