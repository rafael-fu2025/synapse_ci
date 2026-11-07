import React, { memo, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator, FlatList, Pressable, RefreshControl, ScrollView, StyleSheet,
  Text, TextInput, View, type ListRenderItemInfo, type ListViewToken,
  type TextInputInstance, type TextInputSelectionChangeEvent,
} from 'react-native';
import { useIsFocused, useNavigation } from '@react-navigation/native';
import type { BottomTabNavigationProp } from '@react-navigation/bottom-tabs';
import { useNetInfo } from '@react-native-community/netinfo';
import { useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ArrowDown, Plus, Send, X } from 'lucide-react-native';
import type { TeamChatMember, TeamChatMentionInput, TeamChatMessage, TeamChatSendInput, TeamChatTaskReference } from '../../../api/endpoints/teamChat';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { secureClientNonce } from '../../../lib/secureNonce';
import { useForegroundOnline } from '../../../lib/useActiveScreen';
import { useKeyboardVisible } from '../../../lib/useKeyboardVisible';
import { useReducedMotion } from '../../../lib/useReducedMotion';
import { navigateToWorkspaceIntent, navigationRef } from '../../../navigation/navigationRef';
import type { WorkspaceTabsParamList } from '../../../navigation/types';
import { useAuth } from '../../../store/auth';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { WorkspaceHeaderActions } from '../../workspace/WorkspaceHeaderActions';
import { useWorkspaceDraft } from '../../workspace/useWorkspaceDraft';
import { TeamChatMessageBubble } from '../components/TeamChatMessageBubble';
import { TeamChatTaskPicker } from '../components/TeamChatTaskPicker';
import { purgeTeamChatUnit, useSendTeamChatMessage, useTeamChatChannel, useTeamChatIdentity, useTeamChatMemberSearch } from '../hooks';
import {
  buildChatRows, CHAT_MEMBER_SEARCH_LIMIT, insertMention, isAmbiguousSendFailure,
  isChatDirty, mentionQueryAt, messageValidation, normalizeMentions,
  teamChatErrorKind, teamChatErrorMessage, updateMentionRanges,
  type MentionQuery, type TeamChatMode, type TeamChatRow,
} from '../model';

interface PendingSend { input: TeamChatSendInput }
interface ComposerSelection { start: number; end: number }
function compact(value: string, maximum = 180): string {
  const characters = Array.from(value.replace(/\s+/g, ' ').trim());
  return characters.length <= maximum ? characters.join('') : `${characters.slice(0, maximum).join('')}…`;
}

/** Only this small status component owns a freshness clock, never the message list. */
const ChatStatus = memo(function ChatStatusView({ updatedAt, active, mode, error, offline, loaded }: {
  updatedAt: number; active: boolean; mode: TeamChatMode; error: unknown; offline: boolean; loaded: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const [stale, setStale] = useState(false);
  useEffect(() => {
    if (!updatedAt || !active) { setStale(false); return; }
    const remaining = updatedAt + 15_000 - Date.now();
    setStale(remaining <= 0);
    if (remaining <= 0) { return; }
    const timer = setTimeout(() => setStale(true), remaining);
    return () => clearTimeout(timer);
  }, [active, updatedAt]);
  const kind = teamChatErrorKind(error);
  const text = kind === 'forbidden' ? 'Team Workspace access changed. Protected chat data was removed.'
    : kind === 'unauthorized' ? 'Your session expired. Protected chat data was removed.'
      : offline ? loaded ? 'Offline. Showing only previously loaded messages.' : 'Offline. Reconnect to load Team Chat.'
        : error && loaded ? mode === 'history'
          ? 'The requested history could not be loaded. Your current window is unchanged; try again.'
          : 'Updates interrupted. Showing the last loaded messages; pull to retry.'
          : stale && mode === 'latest' ? 'Messages may be out of date. Pull to refresh.' : null;
  return text ? <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={styles.status}>{text}</Text> : null;
});

export function TeamChatScreen({ targetMessageId }: { targetMessageId?: number }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<BottomTabNavigationProp<WorkspaceTabsParamList, 'TeamChat'>>();
  const focused = useIsFocused();
  const foregroundOnline = useForegroundOnline();
  const keyboardVisible = useKeyboardVisible();
  const reducedMotion = useReducedMotion();
  const network = useNetInfo();
  const queryClient = useQueryClient();
  const identity = useTeamChatIdentity();
  const { user, refreshProfile } = useAuth();
  const { activeUnit, units, enterPersonal } = useWorkspace();
  const unit = activeUnit?.unit ?? null;
  const scope = `${identity.tenantId}:${identity.userId}:${unit ?? 'none'}`;
  const [taskPickerVisible, setTaskPickerVisible] = useState(false);
  const channel = useTeamChatChannel({ unit, targetMessageId, paused: taskPickerVisible });
  const sendMessage = useSendTeamChatMessage(unit);
  const resetSend = sendMessage.reset;
  const clearChannel = channel.clear;
  const showLatestChannel = channel.showLatest;
  const openChannelMessage = channel.openMessage;
  const listRef = useRef<FlatList<TeamChatRow>>(null);
  const inputRef = useRef<TextInputInstance>(null);
  const mounted = useRef(false);
  const scopeRef = useRef(scope);
  scopeRef.current = scope;
  const composerGeneration = useRef(0);
  const sendFlight = useRef<symbol | null>(null);
  const sendPendingRef = useRef<PendingSend | null>(null);
  const bodyRef = useRef('');
  const accessRecovery = useRef(false);
  const positioned = useRef<string | null>(null);
  const currentViewSession = useRef<symbol | null>(null);
  const sourceFlight = useRef<symbol | null>(null);
  const [sourceLoading, setSourceLoading] = useState(false);
  const [sourceError, setSourceError] = useState<string | null>(null);
  const [body, setBody] = useState('');
  const [selection, setSelection] = useState<ComposerSelection>({ start: 0, end: 0 });
  const [mentions, setMentions] = useState<TeamChatMentionInput[]>([]);
  const [tasks, setTasks] = useState<TeamChatTaskReference[]>([]);
  const [replyTo, setReplyTo] = useState<TeamChatMessage | null>(null);
  const [pending, setPending] = useState<PendingSend | null>(null);
  const [sending, setSending] = useState(false);
  const [composerError, setComposerError] = useState<string | null>(null);
  const [mentionSearch, setMentionSearch] = useState('');
  const [mentionDismissed, setMentionDismissed] = useState(false);
  const offline = network.isConnected === false;
  const protectedError = channel.error ?? channel.updateError;
  const forbidden = teamChatErrorKind(protectedError) === 'forbidden';
  const unauthorized = teamChatErrorKind(protectedError) === 'unauthorized';
  const locked = pending !== null || sending;
  const controls = useRef({ locked, blocked: false, active: false });
  controls.current = { locked, blocked: forbidden || unauthorized, active: channel.active };

  const mentionMatch = useMemo<MentionQuery | null>(() => {
    if (locked || mentionDismissed || selection.start !== selection.end) { return null; }
    const match = mentionQueryAt(body, selection.start);
    if (match === null) { return null; }
    return mentions.some(item => match.start >= item.start && match.start < item.start + item.length) ? null : match;
  }, [body, locked, mentionDismissed, mentions, selection]);
  useEffect(() => {
    const timer = setTimeout(() => setMentionSearch(mentionMatch?.query ?? ''), 200);
    return () => clearTimeout(timer);
  }, [mentionMatch?.query]);
  const memberSearch = useTeamChatMemberSearch(unit, mentionSearch,
    mentionMatch !== null && mentionSearch === mentionMatch.query && !taskPickerVisible);
  const mentionChanging = mentionMatch !== null && mentionSearch !== mentionMatch.query;
  const mentionChoice = useRef({ body, selection, match: mentionMatch });
  mentionChoice.current = { body, selection, match: mentionMatch };
  const mentionRetry = useRef(false);
  const rows = useMemo(() => buildChatRows(channel.messages, channel.summary.last_read_id, user?.id ?? 0,
    channel.mode === 'latest', true), [channel.messages, channel.mode, channel.summary.last_read_id, user?.id]);

  const clearComposer = useCallback(() => {
    composerGeneration.current += 1;
    bodyRef.current = '';
    sendPendingRef.current = null;
    setBody(''); setSelection({ start: 0, end: 0 }); setMentions([]); setTasks([]);
    setReplyTo(null); setPending(null); setComposerError(null); setTaskPickerVisible(false);
    setMentionSearch(''); setMentionDismissed(false);
    resetSend();
  }, [resetSend]);
  const dirty = !accessRecovery.current && isChatDirty({ body, taskCount: tasks.length, hasReply: replyTo !== null, hasPendingSend: pending !== null });
  const { markSaved } = useWorkspaceDraft({
    key: `team-chat:${scope}`, dirty, submitting: sending, onDiscard: clearComposer,
    warning: pending && !sending
      ? 'Delivery may have succeeded. Discarding this locked message cannot undo delivery; it only removes the local retry copy.'
      : undefined,
  });

  useLayoutEffect(() => {
    mounted.current = true;
    accessRecovery.current = false;
    positioned.current = null;
    sourceFlight.current = null;
    setSourceLoading(false); setSourceError(null); setSending(false);
    clearComposer();
    return () => {
      mounted.current = false;
      composerGeneration.current += 1;
      currentViewSession.current = null;
    };
  }, [clearComposer, scope]);

  const recoverProtectedAccess = useCallback(async (error: unknown) => {
    const kind = teamChatErrorKind(error);
    if (accessRecovery.current || (kind !== 'unauthorized' && kind !== 'forbidden') || !unit) { return; }
    const owner = scope;
    accessRecovery.current = true;
    markSaved(); clearComposer(); clearChannel();
    await purgeTeamChatUnit(queryClient, identity, unit);
    if (!mounted.current || scopeRef.current !== owner) { return; }
    if (kind === 'forbidden') {
      enterPersonal();
      await refreshProfile().catch(() => undefined);
    }
  }, [clearChannel, clearComposer, enterPersonal, identity, markSaved, queryClient, refreshProfile, scope, unit]);
  useEffect(() => {
    if (protectedError) { recoverProtectedAccess(protectedError); }
  }, [protectedError, recoverProtectedAccess]);

  // Old FlatList callbacks cannot acknowledge a new scope/window or a covered list.
  const readAllowed = channel.active && channel.mode === 'latest' &&
    (!channel.busy || channel.polling) && !sourceLoading && !forbidden && !unauthorized;
  const readState = useRef({ allowed: readAllowed, acknowledge: channel.acknowledge });
  readState.current = { allowed: readAllowed, acknowledge: channel.acknowledge };
  const viewSession = useMemo(() => Symbol(`${scope}:${readAllowed}:${keyboardVisible}:${channel.targetMessageId ?? 'latest'}`), [readAllowed, scope, keyboardVisible, channel.targetMessageId]);
  currentViewSession.current = viewSession;
  const viewabilityConfig = useRef({ itemVisiblePercentThreshold: 100, minimumViewTime: 500 }).current;
  const onViewableItemsChanged = useCallback((info: { viewableItems: ListViewToken[] }) => {
    if (!mounted.current || !readState.current.allowed || currentViewSession.current !== viewSession) { return; }
    const ids = info.viewableItems.flatMap(token => token.isViewable && token.item.kind === 'read-marker' ? [token.item.messageId as number] : []);
    if (ids.length > 0) { readState.current.acknowledge(Math.max(...ids)); }
  }, [viewSession]);

  const positionInitialWindow = useCallback(() => {
    if (rows.length === 0 || !channel.loaded) { return; }
    const key = channel.targetMessageId !== null ? `target:${channel.targetMessageId}` : 'latest';
    if (positioned.current === key) { return; }
    positioned.current = key;
    const index = channel.targetMessageId !== null
      ? rows.findIndex(row => row.kind === 'message' && row.message.id === channel.targetMessageId)
      : rows.findIndex(row => row.kind === 'unread');
    if (index < 0) { listRef.current?.scrollToEnd({ animated: false }); return; }
    try { listRef.current?.scrollToIndex({ index, viewPosition: channel.mode === 'history' ? 0.5 : 0.08, animated: false }); }
    catch { positioned.current = null; }
  }, [channel.loaded, channel.mode, channel.targetMessageId, rows]);

  function changeBody(next: string) {
    if (controls.current.locked || controls.current.blocked || sendFlight.current) { return; }
    const previous = bodyRef.current;
    setMentions(current => updateMentionRanges(previous, next, current));
    bodyRef.current = next; setBody(next); setComposerError(null); setMentionDismissed(false);
    sendMessage.reset();
  }
  function changeSelection(event: TextInputSelectionChangeEvent) { setSelection(event.nativeEvent.selection); }
  function chooseMention(member: TeamChatMember) {
    const choice = mentionChoice.current;
    if (!mounted.current || scopeRef.current !== scope || !mentionMatch ||
        controls.current.locked || controls.current.blocked || !controls.current.active ||
        sendPendingRef.current || sendFlight.current || mentionChanging ||
        choice.body !== body || choice.selection.start !== selection.start ||
        choice.selection.end !== selection.end || choice.match?.start !== mentionMatch.start ||
        choice.match?.query !== mentionMatch.query) { return; }
    const inserted = insertMention(body, mentionMatch, selection.start, member);
    const shifted = updateMentionRanges(body, inserted.text, mentions);
    bodyRef.current = inserted.text; setBody(inserted.text);
    setMentions(normalizeMentions(inserted.text, [...shifted, inserted.mention]));
    setSelection({ start: inserted.cursor, end: inserted.cursor }); setComposerError(null);
    inputRef.current?.focus();
  }

  async function submit() {
    if (!unit || !channel.active || !foregroundOnline || offline || sendFlight.current || controls.current.blocked) { return; }
    let sendInput = sendPendingRef.current?.input;
    if (!sendInput) {
      const validation = messageValidation(bodyRef.current, tasks.length);
      if (validation) { setComposerError(validation); return; }
      try {
        sendInput = { body: bodyRef.current, client_nonce: secureClientNonce(), task_ids: tasks.map(task => task.id),
          mentions: normalizeMentions(bodyRef.current, mentions).map(mention => ({ ...mention })), reply_to_id: replyTo?.id ?? null };
      } catch { setComposerError('A secure send token could not be created. Please try again.'); return; }
      sendPendingRef.current = { input: sendInput };
      setPending(sendPendingRef.current);
    }
    const job = Symbol('send');
    const owner = scope;
    const version = composerGeneration.current;
    const valid = () => mounted.current && scopeRef.current === owner && composerGeneration.current === version;
    sendFlight.current = job;
    setSending(true); setComposerError(null);
    try {
      await sendMessage.mutateAsync(sendInput);
      if (!valid()) { return; }
      markSaved(); clearComposer();
      positioned.current = null;
      await channel.showLatest();
      // Never focus the keyboard or scroll behind a later route/overlay.
      if (mounted.current && scopeRef.current === owner && controls.current.active) {
        listRef.current?.scrollToEnd({ animated: !reducedMotion });
      }
    } catch (error) {
      if (!valid()) { return; }
      if (!isAmbiguousSendFailure(error)) { sendPendingRef.current = null; setPending(null); }
      setComposerError(isAmbiguousSendFailure(error)
        ? 'Delivery is unconfirmed and may have succeeded. Retry Send uses this exact locked message and the same secure token; nothing resends automatically.'
        : teamChatErrorKind(error) === 'validation'
          ? 'Not sent. Correct the message or task references and try again.'
          : teamChatErrorMessage(error, 'The message could not be sent.'));
      await recoverProtectedAccess(error);
    } finally {
      if (sendFlight.current === job) { sendFlight.current = null; }
      if (mounted.current && scopeRef.current === owner) { setSending(false); }
    }
  }

  const showLatest = useCallback(async () => {
    const owner = scopeRef.current;
    const succeeded = await showLatestChannel();
    if (!succeeded || !mounted.current || scopeRef.current !== owner) { return; }
    navigation.setParams({ targetMessageId: undefined });
    setSourceError(null);
    positioned.current = 'latest';
    if (controls.current.active) { listRef.current?.scrollToEnd({ animated: false }); }
  }, [showLatestChannel, navigation]);

  const openSource = useCallback((id: number) => {
    if (!controls.current.active || controls.current.blocked || sourceFlight.current || sendFlight.current) { return; }
    const job = Symbol('source');
    const owner = scopeRef.current;
    sourceFlight.current = job;
    setSourceLoading(true); setSourceError(null);
    openChannelMessage(id).then(succeeded => {
      if (!mounted.current || scopeRef.current !== owner || sourceFlight.current !== job) { return; }
      if (!succeeded) { setSourceError('Could not open the original message. It may be unavailable or access may have changed. Tap the quotation to retry.'); }
      else { positioned.current = null; }
    }).finally(() => {
      if (sourceFlight.current === job) { sourceFlight.current = null; }
      if (mounted.current && scopeRef.current === owner) { setSourceLoading(false); }
    });
  }, [openChannelMessage]);
  const openTask = useCallback((task: TeamChatTaskReference) => {
    if (!controls.current.active || controls.current.blocked || sendFlight.current) { return; }
    const authorizedUnit = units.find(item => item.unit === unit);
    if (!unit || !authorizedUnit || !Number.isSafeInteger(task.id) || task.id < 1) {
      setSourceError('This task is not available in your current Team Workspace.'); return;
    }
    if (!authorizedUnit.features.team_board && !authorizedUnit.features.my_tasks) {
      setSourceError('Task details are not available in your current Team Workspace.'); return;
    }
    if (!navigationRef.isReady()) { setSourceError('Task navigation is not ready. Please try again.'); return; }
    if (authorizedUnit.features.team_board) {
      navigateToWorkspaceIntent({ kind: 'team_task', unit, id: task.id });
    } else {
      navigationRef.navigate('AppTabs', {
        screen: 'WorkspaceContext',
        params: {
          screen: 'MyTasks',
          params: { screen: 'TeamTaskDetail', params: { id: task.id }, initial: false },
          initial: false,
        },
        initial: false,
      });
    }
  }, [unit, units]);
  const chooseReply = useCallback((message: TeamChatMessage) => {
    if (controls.current.locked || controls.current.blocked || !controls.current.active || sendFlight.current) { return; }
    setReplyTo(message); setComposerError(null); inputRef.current?.focus();
  }, []);
  const renderRow = useCallback(({ item }: ListRenderItemInfo<TeamChatRow>) => {
    if (item.kind === 'day') { return <Text accessibilityRole="header" style={styles.day}>{item.label}</Text>; }
    if (item.kind === 'unread') {
      return <View accessibilityLabel="Unread messages" style={styles.unreadRow}><View style={styles.unreadLine} /><Text style={styles.unreadText}>Unread messages</Text><View style={styles.unreadLine} /></View>;
    }
    if (item.kind === 'read-marker') {
      return <View testID={`team-chat-read-marker-${item.messageId}`} accessible={false} importantForAccessibility="no" style={styles.readMarker} />;
    }
    return <TeamChatMessageBubble message={item.message} own={item.message.sender.id === user?.id}
      targeted={channel.targetMessageId === item.message.id}
      replyDisabled={locked || !channel.active || forbidden || unauthorized}
      navigationDisabled={!channel.active || sourceLoading || sending || forbidden || unauthorized}
      onReply={chooseReply} onOpenSource={openSource} onOpenTask={openTask} />;
  }, [channel.active, channel.targetMessageId, chooseReply, forbidden, locked, openSource, openTask, sending, sourceLoading, styles, unauthorized, user?.id]);

  const header = <PageHeader edgeToEdge title="Team Chat" subtitle={activeUnit?.label ?? 'Choose a Team Workspace'}
    action={<WorkspaceHeaderActions root disabled={sending} />} />;
  const cannotSend = body.trim() === '' || sending || !channel.active || offline || forbidden || unauthorized;
  const editable = !locked && !forbidden && !unauthorized;
  const composer = (
    <>
      {mentionMatch && !taskPickerVisible ? (
        <ScrollView style={styles.suggestions} keyboardShouldPersistTaps="handled" accessibilityLabel="Mention team member suggestions">
          <Pressable accessibilityRole="button" accessibilityLabel="Dismiss mention suggestions" onPress={() => setMentionDismissed(true)} style={styles.dismissSuggestions}>
            <Text style={styles.retryText}>Close suggestions</Text>
          </Pressable>
          {mentionChanging || memberSearch.isFetching ? <ActivityIndicator size="small" color={theme.brandIcon} style={styles.suggestionLoader} /> : null}
          {!mentionChanging && memberSearch.isError ? (
            <View style={styles.suggestionFeedback}>
              <Text accessibilityRole="alert" style={styles.composerError}>Could not search team members. Your message is unchanged.</Text>
              <Pressable accessibilityRole="button" accessibilityLabel="Retry member search" disabled={!channel.active || memberSearch.isFetching}
                accessibilityState={{ disabled: !channel.active || memberSearch.isFetching, busy: memberSearch.isFetching }}
                onPress={() => {
                  if (!controls.current.active || mentionRetry.current || memberSearch.isFetching) { return; }
                  mentionRetry.current = true;
                  memberSearch.refetch().catch(() => undefined).finally(() => { mentionRetry.current = false; });
                }} style={styles.retryButton}><Text style={styles.retryText}>Try Again</Text></Pressable>
            </View>
          ) : !mentionChanging ? (memberSearch.data?.data ?? []).slice(0, CHAT_MEMBER_SEARCH_LIMIT).map(member => (
            <Pressable key={member.id} accessibilityRole="button" accessibilityLabel={`Mention ${member.name}`} disabled={!channel.active || locked}
              accessibilityState={{ disabled: !channel.active || locked }} onPress={() => chooseMention(member)}
              style={({ pressed }) => [styles.suggestion, pressed && styles.pressed]}>
              <Text style={styles.suggestionName}>@{member.name}</Text><Text numberOfLines={1} style={styles.suggestionRole}>{member.role.replace(/_/g, ' ')}</Text>
            </Pressable>
          )) : null}
          {!mentionChanging && !memberSearch.isFetching && !memberSearch.isError && memberSearch.data?.data.length === 0
            ? <Text style={styles.noSuggestion}>No matching team members.</Text> : null}
          {!mentionChanging && (memberSearch.data?.meta.total ?? 0) > CHAT_MEMBER_SEARCH_LIMIT
            ? <Text style={styles.noSuggestion}>Showing the first 20 members. Keep typing to narrow your search.</Text> : null}
        </ScrollView>
      ) : null}
      <View style={styles.composerShell}>
        {replyTo ? (
          <View style={styles.replyPreview}><View style={styles.replyCopy}><Text style={styles.replyTitle}>Replying to {replyTo.sender.name}</Text><Text numberOfLines={2} style={styles.replyBody}>{compact(replyTo.body)}</Text></View>
            <Pressable accessibilityRole="button" accessibilityLabel="Cancel reply" accessibilityState={{ disabled: locked }} disabled={locked}
              onPress={() => { if (!controls.current.locked && !sendFlight.current) { setReplyTo(null); } }} style={styles.previewRemove}><X size={18} color={theme.ink500} /></Pressable>
          </View>
        ) : null}
        {tasks.length > 0 ? (
          <ScrollView horizontal showsHorizontalScrollIndicator={false} keyboardShouldPersistTaps="handled" contentContainerStyle={styles.taskChips}>
            {tasks.map(task => <Pressable key={task.id} accessibilityRole="button" accessibilityLabel={`Remove task ${task.id}: ${task.title}`}
              accessibilityState={{ disabled: locked }} disabled={locked}
              onPress={() => { if (!controls.current.locked && !sendFlight.current) { setTasks(current => current.filter(item => item.id !== task.id)); } }} style={styles.taskChip}>
              <Text numberOfLines={1} style={styles.taskChipText}>#{task.id}: {task.title}</Text><X size={15} color={theme.brandIcon} />
            </Pressable>)}
          </ScrollView>
        ) : null}
        {composerError ? <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={styles.composerError}>{composerError}</Text> : null}
        {pending ? <Text accessibilityLiveRegion="polite" style={styles.lockedStatus}>{sending
          ? 'Sending this exact message. Text, reply, mentions, and task references are locked.'
          : 'Delivery is unconfirmed. Retry Send is explicit and keeps the exact text, reply, mentions, task references, and secure token.'}</Text> : null}
        <View style={styles.composerRow}>
          <Pressable accessibilityRole="button" accessibilityLabel="Add task references" accessibilityHint="Adds active task references; attachments are not supported."
            accessibilityState={{ disabled: !editable || !channel.active }} disabled={!editable || !channel.active}
            onPress={() => { if (!controls.current.locked && !controls.current.blocked && controls.current.active && !sendFlight.current) { setTaskPickerVisible(true); } }}
            style={({ pressed }) => [styles.plusButton, (!editable || !channel.active) && styles.disabled, pressed && styles.pressed]}><Plus size={21} color={theme.brandIcon} /></Pressable>
          <TextInput ref={inputRef} accessibilityLabel="Team message" accessibilityHint="Type at sign to mention a current team member. Return inserts a new line."
            value={body} selection={selection} onSelectionChange={changeSelection} onChangeText={changeBody}
            placeholder={locked ? 'Message locked until delivery is resolved' : 'Message the team'} placeholderTextColor={theme.ink400}
            editable={editable} multiline submitBehavior="newline" maxLength={4_000} textAlignVertical="top" style={styles.input} />
          <Pressable accessibilityRole="button" accessibilityLabel={pending && !sending ? 'Retry send' : 'Send message'}
            accessibilityState={{ disabled: cannotSend, busy: sending }} disabled={cannotSend} onPress={() => { submit(); }}
            style={({ pressed }) => [styles.sendButton, cannotSend && styles.disabled, pressed && styles.pressed]}>
            {sending ? <ActivityIndicator size="small" color="#ffffff" /> : <Send size={19} color="#ffffff" />}
          </Pressable>
        </View>
      </View>
    </>
  );

  if (!unit) {
    return <Screen scroll={false} header={header} bodyStyle={styles.center}><Text accessibilityRole="alert" style={styles.emptyTitle}>Choose a Team Workspace unit first.</Text></Screen>;
  }
  return (
    <Screen scroll={false} header={header} bodyStyle={styles.screen} keyboardAware footer={composer} bottomSafeArea={false}>
      <View style={styles.notice}><AlertTriangle size={14} color={theme.ink500} /><Text style={styles.noticeText}>Operational messages only. Do not include patient or confidential counseling content.</Text></View>
      {channel.mode === 'history' ? (
        <View style={styles.historyBanner}><Text accessibilityLiveRegion="polite" style={styles.historyText}>Viewing history. Live updates and read acknowledgements are paused.</Text>
          <Pressable accessibilityRole="button" accessibilityLabel="Show Latest" disabled={channel.busy || !channel.active}
            accessibilityState={{ disabled: channel.busy || !channel.active, busy: channel.busy }} onPress={() => { showLatest(); }} style={styles.latestButton}>
            <ArrowDown size={16} color="#ffffff" /><Text style={styles.latestText}>Show Latest</Text>
          </Pressable>
        </View>
      ) : null}
      <ChatStatus updatedAt={channel.updatedAt} active={channel.active} mode={channel.mode} error={protectedError} offline={offline} loaded={channel.loaded} />
      {sourceLoading ? <Text accessibilityLiveRegion="polite" style={styles.status}>Opening the original message…</Text> : null}
      {sourceError ? <Text accessibilityRole="alert" style={styles.status}>{sourceError}</Text> : null}
      {channel.loadingInitial ? <View style={styles.center} accessibilityLabel="Loading Team Chat messages"><ActivityIndicator color={theme.brandIcon} /></View>
        : channel.error && !channel.loaded && !forbidden && !unauthorized ? (
          <View style={styles.center}><Text accessibilityRole="alert" style={styles.errorText}>{teamChatErrorMessage(channel.error, 'Could not load Team Chat.')}</Text>
            <Pressable accessibilityRole="button" accessibilityLabel="Retry loading Team Chat" disabled={!channel.active || channel.busy}
              accessibilityState={{ disabled: !channel.active || channel.busy }} onPress={() => { channel.refresh(); }} style={styles.retryButton}><Text style={styles.retryText}>Try Again</Text></Pressable>
          </View>
        ) : !channel.loaded ? <View style={styles.center}><Text style={styles.emptyBody}>{forbidden || unauthorized ? 'Team Chat is unavailable.' : 'Waiting for an active connection to load Team Chat.'}</Text></View>
          : (
            <FlatList ref={listRef} testID="team-chat-history" data={rows} extraData={viewSession} keyExtractor={item => item.key} renderItem={renderRow}
              ListHeaderComponent={channel.hasOlder ? <Pressable accessibilityRole="button" accessibilityLabel="Load older messages"
                accessibilityState={{ busy: channel.loadingOlder, disabled: channel.busy || !channel.active }} disabled={channel.busy || !channel.active}
                onPress={() => { channel.loadOlder(); }} style={styles.olderButton}>
                {channel.loadingOlder ? <ActivityIndicator size="small" color={theme.brandIcon} /> : null}<Text style={styles.olderText}>{channel.loadingOlder ? 'Loading older messages…' : 'Load older messages'}</Text>
              </Pressable> : <View style={styles.listTop} />}
              ListEmptyComponent={<View style={styles.empty}><Text style={styles.emptyTitle}>No messages yet</Text><Text style={styles.emptyBody}>Start an operational team discussion.</Text></View>}
              refreshControl={<RefreshControl refreshing={channel.refreshing} enabled={channel.active && !channel.busy} onRefresh={() => { channel.refresh(); }} tintColor={theme.brandIcon} />}
              keyboardDismissMode="interactive" keyboardShouldPersistTaps="handled" automaticallyAdjustKeyboardInsets={false}
              maintainVisibleContentPosition={{ minIndexForVisible: 0 }}
              onViewableItemsChanged={onViewableItemsChanged} viewabilityConfig={viewabilityConfig}
              onContentSizeChange={positionInitialWindow}
              onScrollToIndexFailed={({ index, averageItemLength }) => {
                if (!channel.active) { return; }
                positioned.current = null;
                listRef.current?.scrollToOffset({ offset: Math.max(0, index * averageItemLength), animated: false });
              }}
              contentContainerStyle={[styles.list, rows.length === 0 && styles.emptyList]} style={styles.listViewport}
            />
          )}
      <TeamChatTaskPicker key={scope} unit={unit} visible={taskPickerVisible && focused} selected={tasks} disabled={locked || forbidden || unauthorized}
        onChange={next => { if (!controls.current.locked && !controls.current.blocked && !sendFlight.current) { setTasks(next); setComposerError(null); } }}
        onClose={() => setTaskPickerVisible(false)} />
    </Screen>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { paddingHorizontal: 0, paddingBottom: 0 },
    notice: { minHeight: 36, flexDirection: 'row', alignItems: 'center', gap: 7, paddingHorizontal: 12, paddingVertical: 7, borderBottomWidth: 1, borderBottomColor: theme.line, backgroundColor: theme.surfaceWarm },
    noticeText: { ...type.caption, flex: 1, color: theme.ink500 },
    historyBanner: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 12, paddingVertical: 6, backgroundColor: '#faedcf' },
    historyText: { ...type.caption, flex: 1, color: '#6b4a0c' },
    latestButton: { minHeight: 44, flexDirection: 'row', alignItems: 'center', gap: 5, paddingHorizontal: 12, borderRadius: 12, backgroundColor: theme.brand },
    latestText: { ...type.bodyStrong, color: '#ffffff' },
    status: { ...type.caption, paddingHorizontal: 12, paddingVertical: 8, color: '#7b4f0b', backgroundColor: '#fff5dc' },
    listViewport: { flex: 1 }, list: { paddingBottom: 10 }, emptyList: { flexGrow: 1 }, listTop: { height: 8 },
    readMarker: { height: 2, marginHorizontal: 14, backgroundColor: theme.line },
    olderButton: { minHeight: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, marginHorizontal: 12, marginVertical: 8 },
    olderText: { ...type.bodyStrong, color: theme.brandText },
    day: { ...type.caption, alignSelf: 'center', marginVertical: 9, paddingHorizontal: 12, paddingVertical: 5, borderRadius: 999, color: theme.ink500, backgroundColor: theme.surface },
    unreadRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginHorizontal: 12, marginVertical: 8 },
    unreadLine: { flex: 1, height: 1, backgroundColor: theme.brand }, unreadText: { ...type.caption, color: theme.brandText },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, padding: 24 },
    empty: { alignItems: 'center', justifyContent: 'center', gap: 5, padding: 36 },
    emptyTitle: { ...type.cardTitle, color: theme.ink900, textAlign: 'center' }, emptyBody: { ...type.body, color: theme.ink500, textAlign: 'center' },
    errorText: { ...type.body, color: '#a33b3b', textAlign: 'center' },
    retryButton: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 18, borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    retryText: { ...type.action, color: theme.brandText },
    suggestions: { maxHeight: 184, borderBottomWidth: 1, borderBottomColor: theme.line, backgroundColor: theme.surface },
    dismissSuggestions: { minHeight: 44, justifyContent: 'center', alignItems: 'flex-end' },
    suggestionFeedback: { paddingVertical: 6 }, suggestionLoader: { paddingVertical: 8 },
    suggestion: { minHeight: 48, flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 4, borderBottomWidth: 1, borderBottomColor: theme.line },
    suggestionName: { ...type.bodyStrong, flex: 1, color: theme.ink900 }, suggestionRole: { ...type.caption, maxWidth: '38%', color: theme.ink500, textTransform: 'capitalize' },
    noSuggestion: { ...type.caption, padding: 10, color: theme.ink500, textAlign: 'center' },
    composerShell: { backgroundColor: theme.surface },
    replyPreview: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 6, paddingLeft: 10, borderLeftWidth: 3, borderLeftColor: theme.brand, backgroundColor: theme.surfaceWarm },
    replyCopy: { flex: 1, paddingVertical: 6 }, replyTitle: { ...type.caption, color: theme.ink700 }, replyBody: { ...type.caption, marginTop: 2, color: theme.ink500 },
    previewRemove: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
    taskChips: { gap: 6, paddingBottom: 7 },
    taskChip: { minHeight: 44, maxWidth: 240, flexDirection: 'row', alignItems: 'center', gap: 5, paddingHorizontal: 10, borderRadius: 12, borderWidth: 1, borderColor: theme.brand100, backgroundColor: theme.brand50 },
    taskChipText: { ...type.caption, flexShrink: 1, color: theme.brandText },
    composerError: { ...type.caption, marginBottom: 5, color: '#a33b3b' }, lockedStatus: { ...type.caption, marginBottom: 5, color: '#7b4f0b' },
    composerRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 7 },
    plusButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surfaceWarm },
    input: { flex: 1, minHeight: 44, maxHeight: 104, paddingHorizontal: 12, paddingVertical: 10, borderRadius: 14, borderWidth: 1, borderColor: theme.line, color: theme.ink900, backgroundColor: theme.surfaceWarm, fontFamily: font.body, fontSize: 12, lineHeight: 19 },
    sendButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, backgroundColor: theme.brand },
    disabled: { opacity: 0.45 }, pressed: { opacity: 0.68 },
  });
}
const makeStyles = memoizeStyles(createStyles);
