import React, { memo, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator, FlatList, Pressable, RefreshControl, ScrollView, StyleSheet,
  Text, TextInput, View, type ListRenderItemInfo, type ListViewToken,
  type LayoutChangeEvent, type NativeScrollEvent, type NativeSyntheticEvent,
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
import { Skeleton } from '../../../components/Skeleton';
import { secureClientNonce } from '../../../lib/secureNonce';
import { useForegroundOnline } from '../../../lib/useActiveScreen';
import { useKeyboardVisible } from '../../../lib/useKeyboardVisible';
import { useReducedMotion } from '../../../lib/useReducedMotion';
import { useRealtimeReady } from '../../../lib/useRealtimeReady';
import { CONTEXT_BAR_ACTION_CLEARANCE } from '../../../navigation/contextTabBarMetrics';
import { navigateToWorkspaceIntent, navigationRef } from '../../../navigation/navigationRef';
import type { WorkspaceTabsParamList } from '../../../navigation/types';
import { useAuth } from '../../../store/auth';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { WorkspaceHeaderActions } from '../../workspace/WorkspaceHeaderActions';
import { useWorkspaceDraft } from '../../workspace/useWorkspaceDraft';
import { TeamChatMessageBubble } from '../components/TeamChatMessageBubble';
import { TeamChatTimestamp, TeamChatTimestampProvider } from '../components/TeamChatTimestamp';
import { ChatScrollButton } from '../components/ChatScrollButton';
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
interface BottomScrollIntent {
  session: symbol;
  revision: number | null;
  layoutReady: boolean;
  started: boolean;
  measuredHeight: number;
  measuredContentHeight: number;
  correctedOffset: number | null;
}
interface ChatEntry {
  key: symbol;
  scope: string;
  navigationRevision: number;
  epoch: number;
  routeTarget: number | undefined;
  target: number | null;
  baselineLatest: number;
  retainedLatest: boolean;
}
const BOTTOM_SCROLL_THRESHOLD = 80;
const CHAT_LIST_BOTTOM_PADDING = 10;
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
  const realtime = useRealtimeReady();
  const [stale, setStale] = useState(false);
  useEffect(() => {
    if (!updatedAt || !active || realtime) { setStale(false); return; }
    const remaining = updatedAt + 15_000 - Date.now();
    setStale(remaining <= 0);
    if (remaining <= 0) { return; }
    const timer = setTimeout(() => setStale(true), remaining);
    return () => clearTimeout(timer);
  }, [active, realtime, updatedAt]);
  const kind = teamChatErrorKind(error);
  const text = kind === 'forbidden' ? 'Team Workspace access changed. Protected chat data was removed.'
    : kind === 'unauthorized' ? 'Your session expired. Protected chat data was removed.'
      : offline ? loaded ? 'Offline. Showing only previously loaded messages.' : 'Offline. Reconnect to load Team Chat.'
        : error && loaded ? mode === 'history'
          ? 'The requested history could not be loaded. Your current window is unchanged; try again.'
          : 'Updates interrupted. Showing the last loaded messages; pull to retry.'
          : !realtime && stale && mode === 'latest' ? 'Messages may be out of date. Pull to refresh.' : null;
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
  const { activeUnit, units, enterPersonal, canSwitchContext } = useWorkspace();
  const unit = activeUnit?.unit ?? null;
  const scope = `${identity.tenantId}:${identity.userId}:${unit ?? 'none'}`;
  const [taskPickerVisible, setTaskPickerVisible] = useState(false);
  const channel = useTeamChatChannel({ unit, targetMessageId, paused: taskPickerVisible });
  const sendMessage = useSendTeamChatMessage(unit);
  const resetSend = sendMessage.reset;
  const clearChannel = channel.clear;
  const showLatestChannel = channel.showLatest;
  const refreshChannel = channel.refresh;
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
  const scrollGeometry = useRef({ height: 0, contentHeight: 0, offset: 0 });
  // Intent belongs to this scope, not the arrow or a changing native viewport.
  const followingBottom = useRef(true);
  const deferredLatest = useRef<string | null>(null);
  const latestAction = useRef<() => Promise<void>>(async () => undefined);
  const observedWindow = useRef({ scope, messages: channel.messages, latestRevision: channel.latestRevision });
  const observedReplacement = useRef({ scope, revision: channel.latestRevision });
  const observedNavigation = useRef({ scope, revision: channel.navigationRevision });
  const observedTarget = useRef(targetMessageId);
  const cancelledReplacement = useRef<{ scope: string; revision: number } | null>(null);
  const [awayFromBottom, setAwayFromBottom] = useState(false);
  const bottomScroll = useRef<BottomScrollIntent | null>(null);
  const nextBottomRevision = useRef(0);
  const [bottomRevision, setBottomRevision] = useState(0);
  const [entryEpoch, setEntryEpoch] = useState(0);
  const [revealedEntry, setRevealedEntry] = useState<symbol | null>(null);
  const previousLatest = useRef(channel.latestRevision);
  const entryFocus = useRef(focused);
  const returnedToFocus = focused && !entryFocus.current;
  entryFocus.current = focused;
  const validTarget = Number.isSafeInteger(targetMessageId) && (targetMessageId ?? 0) > 0 ? targetMessageId! : null;
  const entry = useRef<ChatEntry>({ key: Symbol('chat-entry'), scope, navigationRevision: channel.navigationRevision,
    epoch: entryEpoch, routeTarget: targetMessageId, target: validTarget, baselineLatest: -1, retainedLatest: false });
  const freshTarget = entry.current.routeTarget !== targetMessageId && validTarget !== null;
  const targetWaiting = entry.current.target !== null && (!channel.loaded || channel.targetMessageId !== entry.current.target);
  if (entry.current.scope !== scope || entry.current.navigationRevision !== channel.navigationRevision || entry.current.epoch !== entryEpoch || returnedToFocus || freshTarget) {
    const entryTarget = freshTarget || entry.current.scope !== scope ? validTarget
      : !targetWaiting && (returnedToFocus || entry.current.epoch !== entryEpoch || revealedEntry === entry.current.key) ? null : entry.current.target;
    entry.current = { key: Symbol('chat-entry'), scope, navigationRevision: channel.navigationRevision, epoch: entryEpoch,
      routeTarget: targetMessageId, target: entryTarget,
      baselineLatest: entry.current.scope !== scope ? -1 : previousLatest.current,
      retainedLatest: entry.current.scope === scope && entryTarget === null && channel.loaded && channel.mode === 'latest' };
  } else { entry.current.routeTarget = targetMessageId; }
  previousLatest.current = channel.latestRevision;
  const entryKey = entry.current.key;
  // A retained window already committed its native tail; a new entry needs fresh
  // measurements, not another server response or an unchanged tail layout event.
  const entryWindow = useMemo(() => Symbol(`${scope}:${channel.latestRevision}:${channel.messages.length}:${channel.mode}:${channel.targetMessageId}`), [scope, channel.latestRevision, channel.messages, channel.mode, channel.targetMessageId]);
  const currentEntryWindow = useRef(entryWindow);
  currentEntryWindow.current = entryWindow;
  const entryReady = revealedEntry === entryKey;
  const entryReadyRef = useRef(entryReady);
  entryReadyRef.current = entryReady;
  const viewportRef = useRef<React.ComponentRef<typeof View>>(null);
  const entryCoverRef = useRef<React.ComponentRef<typeof View>>(null);
  const latestTailRef = useRef<React.ComponentRef<typeof View>>(null);
  const lastMessageRef = useRef<React.ComponentRef<typeof View>>(null);
  const targetMessageRef = useRef<React.ComponentRef<typeof View>>(null);
  const entryFrame = useRef<ReturnType<typeof requestAnimationFrame> | null>(null);
  const entryCorrection = useRef<() => void>(() => undefined);
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
  const navigationFocus = useRef(focused);
  const renderedFocus = useRef(focused);
  if (renderedFocus.current !== focused) {
    renderedFocus.current = focused;
    navigationFocus.current = focused;
  }
  const controls = useRef({ locked, blocked: false, active: false });
  controls.current = { locked, blocked: forbidden || unauthorized, active: channel.active && navigationFocus.current && !taskPickerVisible };
  const scrollSession = useMemo(() => Symbol(`${scope}:${focused}:${taskPickerVisible}:${channel.active}:${channel.navigationRevision}:${forbidden}:${unauthorized}`), [scope, focused, taskPickerVisible, channel.active, channel.navigationRevision, forbidden, unauthorized]);
  const currentScrollSession = useRef(scrollSession);
  currentScrollSession.current = scrollSession;

  const cancelBottomScroll = useCallback(() => { bottomScroll.current = null; }, []);
  useLayoutEffect(() => {
    cancelBottomScroll();
    const blur = navigation.addListener?.('blur', () => {
      navigationFocus.current = false;
      controls.current.active = false;
      entryReadyRef.current = false;
      // Frozen routes may defer React commits. Cover retained native pixels before
      // a later tab attach can expose the old offset while the route thaws.
      entryCoverRef.current?.setNativeProps({ style: { opacity: 1 }, pointerEvents: 'auto', accessibilityElementsHidden: false,
        importantForAccessibility: 'auto' });
      setRevealedEntry(null);
      setEntryEpoch(value => value + 1);
      currentViewSession.current = null;
      cancelBottomScroll();
    });
    const focus = navigation.addListener?.('focus', () => { navigationFocus.current = true; });
    return () => { blur?.(); focus?.(); cancelBottomScroll(); };
  }, [cancelBottomScroll, navigation, scrollSession]);
  useLayoutEffect(() => {
    if (observedTarget.current === targetMessageId) { return; }
    observedTarget.current = targetMessageId;
    if (Number.isSafeInteger(targetMessageId) && (targetMessageId ?? 0) > 0) {
      cancelBottomScroll();
      deferredLatest.current = null;
      followingBottom.current = false;
      positioned.current = null;
    }
  }, [cancelBottomScroll, targetMessageId]);

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
    scrollGeometry.current = { height: 0, contentHeight: 0, offset: 0 };
    followingBottom.current = true;
    deferredLatest.current = null;
    cancelledReplacement.current = null;
    setAwayFromBottom(false);
    sourceFlight.current = null;
    setSourceLoading(false); setSourceError(null); setSending(false);
    clearComposer();
    return () => {
      mounted.current = false;
      if (entryFrame.current !== null) { cancelAnimationFrame(entryFrame.current); entryFrame.current = null; }
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
  const readAllowed = entryReady && channel.active && focused && !taskPickerVisible && channel.mode === 'latest' &&
    (!channel.busy || channel.polling) && !sourceLoading && !forbidden && !unauthorized;
  const readState = useRef({ allowed: readAllowed, acknowledge: channel.acknowledge });
  readState.current = { allowed: readAllowed, acknowledge: channel.acknowledge };
  const readableSince = useRef({ session: Symbol('unreadable'), at: Infinity });
  const viewSession = useMemo(() => Symbol(`${scope}:${readAllowed}:${keyboardVisible}:${channel.targetMessageId ?? 'latest'}:${channel.latestRevision}:${channel.navigationRevision}:${String(entryWindow)}:${String(entryKey)}`), [readAllowed, scope, keyboardVisible, channel.targetMessageId, channel.latestRevision, channel.navigationRevision, entryWindow, entryKey]);
  currentViewSession.current = viewSession;
  useLayoutEffect(() => {
    readableSince.current = { session: viewSession, at: readAllowed ? Date.now() : Infinity };
    if (readAllowed) { listRef.current?.recordInteraction(); }
  }, [readAllowed, viewSession]);
  const viewabilityConfig = useRef({ itemVisiblePercentThreshold: 100, minimumViewTime: 500 }).current;
  const onViewableItemsChanged = useCallback((info: { viewableItems: ListViewToken[] }) => {
    if (!mounted.current || !readState.current.allowed || currentViewSession.current !== viewSession ||
        readableSince.current.session !== viewSession || Date.now() - readableSince.current.at < 500) { return; }
    const ids = info.viewableItems.flatMap(token => token.isViewable && token.item.kind === 'read-marker' ? [token.item.messageId as number] : []);
    if (ids.length > 0) { readState.current.acknowledge(Math.max(...ids)); }
  }, [viewSession]);

  const updateBottomDistance = useCallback(() => {
    const { height, contentHeight, offset } = scrollGeometry.current;
    const maxOffset = Math.max(0, contentHeight - height);
    const distance = maxOffset - Math.max(0, Math.min(offset, maxOffset));
    setAwayFromBottom(height > 0 && distance > BOTTOM_SCROLL_THRESHOLD);
  }, []);
  const scrollState = useRef({ mode: channel.mode, loaded: channel.loaded, busy: channel.busy, loadingLatest: channel.loadingLatest, polling: channel.polling, refreshing: channel.refreshing, reducedMotion, keyboardVisible, focused, taskPickerVisible,
    latestRevision: channel.latestRevision, target: channel.targetMessageId, lastId: channel.messages.at(-1)?.id ?? null, error: protectedError });
  scrollState.current = { mode: channel.mode, loaded: channel.loaded, busy: channel.busy, loadingLatest: channel.loadingLatest, polling: channel.polling, refreshing: channel.refreshing, reducedMotion, keyboardVisible, focused, taskPickerVisible,
    latestRevision: channel.latestRevision, target: channel.targetMessageId, lastId: channel.messages.at(-1)?.id ?? null, error: protectedError };
  const tailWindow = useRef<symbol | null>(null);
  const confirmEntry = useCallback(() => {
    const current = scrollState.current;
    const captured = entry.current;
    const measured = { ...scrollGeometry.current };
    const valid = () => mounted.current && currentEntryWindow.current === entryWindow && entry.current === captured && scrollState.current.keyboardVisible === keyboardVisible &&
      scrollGeometry.current.height === measured.height && scrollGeometry.current.contentHeight === measured.contentHeight && scrollGeometry.current.offset === measured.offset &&
      !entryReadyRef.current && controls.current.active && !controls.current.blocked && scrollState.current.focused &&
      !scrollState.current.taskPickerVisible && scrollState.current.loaded && (!scrollState.current.busy || scrollState.current.polling ||
        (captured.retainedLatest && scrollState.current.loadingLatest && scrollState.current.mode === 'latest'));
    if (!valid() || (captured.target === null && (current.mode !== 'latest' || current.latestRevision < captured.baselineLatest ||
          (!captured.retainedLatest && current.latestRevision === captured.baselineLatest))) ||
        (captured.target !== null && (current.mode !== 'history' || current.target !== captured.target))) { return; }
    const viewport = viewportRef.current;
    const tail = latestTailRef.current;
    const marker = captured.target !== null ? targetMessageRef.current : lastMessageRef.current;
    const nodesCurrent = () => viewportRef.current === viewport && (captured.target !== null
      ? targetMessageRef.current === marker : latestTailRef.current === tail && lastMessageRef.current === marker);
    if (!viewport || (current.lastId !== null && !marker) || (captured.target === null && (!tail || tailWindow.current !== entryWindow))) { return; }
    const inside = (y: number, height: number, top: number, viewportHeight: number) =>
      Number.isFinite(y) && Number.isFinite(height) && height > 0 && y < top + viewportHeight && y + height > top;
    const reveal = () => {
      if (!valid() || !nodesCurrent()) { return; }
      if (captured.target === null) {
        // Native end measurements are authoritative even when a no-op scroll
        // never delivers onScroll. Reconcile only after every check succeeds.
        scrollGeometry.current.offset = Math.max(0, measured.contentHeight - measured.height);
        positioned.current = 'latest';
        followingBottom.current = true;
        if (bottomScroll.current?.revision !== null) { cancelBottomScroll(); }
        setAwayFromBottom(false);
      }
      currentViewSession.current = null;
      entryReadyRef.current = true;
      setRevealedEntry(captured.key);
    };
    try {
      viewport.measureInWindow((_x, top, width, height) => {
        if (!valid() || !Number.isFinite(top) || !Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0 || Math.abs(height - measured.height) > 1) { return; }
        if (captured.target !== null) {
          if (measured.contentHeight <= 0 || positioned.current !== `target:${captured.target}`) { return; }
          marker?.measureInWindow((_markerX, y, _markerWidth, markerHeight) => {
            if (!valid() || !inside(y, markerHeight, top, height)) { return; }
            const center = y + markerHeight / 2;
            const viewportCenter = top + height / 2;
            const maxOffset = Math.max(0, measured.contentHeight - measured.height);
            if (Math.abs(center - viewportCenter) <= 2 || (measured.offset <= 1 && center <= viewportCenter) ||
                (measured.offset >= maxOffset - 1 && center >= viewportCenter)) { reveal(); }
          });
          return;
        }
        tail!.measureInWindow((_tailX, y, _tailWidth, tailHeight) => {
          if (!valid() || !inside(y, tailHeight, top, height) || measured.contentHeight <= 0) { return; }
          // Empty content can flex to fill the viewport with space below its tail.
          if (current.lastId === null && measured.contentHeight <= height + 1) { reveal(); return; }
          const expectedTailBottom = top + Math.min(measured.contentHeight, height) - CHAT_LIST_BOTTOM_PADDING;
          if (Math.abs(y + tailHeight - expectedTailBottom) > 1) { return; }
          if (current.lastId === null) { reveal(); return; }
          marker!.measureInWindow((_markerX, markerY, _markerWidth, markerHeight) => {
            if (valid() && inside(markerY, markerHeight, top, height) && markerY + markerHeight <= top + height + 1) { reveal(); }
          });
        });
      });
    } catch {
      // Native nodes can detach during a route/window change; the next real event can confirm.
    }
  }, [cancelBottomScroll, entryWindow, keyboardVisible]);
  const scheduleEntryCheck = useCallback(() => {
    if (entryFrame.current !== null) { cancelAnimationFrame(entryFrame.current); }
    if (entryReadyRef.current) { entryFrame.current = null; return; }
    entryFrame.current = requestAnimationFrame(() => { entryFrame.current = null; entryCorrection.current(); confirmEntry(); });
  }, [confirmEntry]);
  useLayoutEffect(() => {
    if (entryFrame.current !== null) { cancelAnimationFrame(entryFrame.current); entryFrame.current = null; }
    if (!entryReady && focused && navigationFocus.current && !taskPickerVisible && channel.loaded && !channel.busy && (protectedError || offline) && !forbidden && !unauthorized) {
      setRevealedEntry(entryKey); // A failed/offline return keeps its last good window and recovery.
    }
    return () => { if (entryFrame.current !== null) { cancelAnimationFrame(entryFrame.current); entryFrame.current = null; } };
  }, [channel.busy, channel.loaded, entryKey, entryReady, entryWindow, focused, forbidden, offline, protectedError, taskPickerVisible, unauthorized]);
  const armBottomScroll = useCallback(() => {
    const intent: BottomScrollIntent = { session: scrollSession, revision: null, layoutReady: false, started: false,
      measuredHeight: 0, measuredContentHeight: 0, correctedOffset: null };
    bottomScroll.current = intent;
    followingBottom.current = true;
    return intent;
  }, [scrollSession]);
  const commitBottomScroll = useCallback((intent: BottomScrollIntent) => {
    if (bottomScroll.current !== intent || intent.session !== currentScrollSession.current) { return; }
    intent.revision = ++nextBottomRevision.current;
    intent.layoutReady = false;
    setBottomRevision(intent.revision);
  }, []);
  const correctBottomScroll = useCallback(() => {
    const intent = bottomScroll.current;
    const current = scrollState.current;
    const geometry = scrollGeometry.current;
    if (!intent || intent.revision === null || !intent.layoutReady || intent.session !== scrollSession || currentScrollSession.current !== scrollSession ||
        !mounted.current || !controls.current.active || controls.current.blocked || !current.focused || current.taskPickerVisible ||
        !current.loaded || current.mode !== 'latest' || geometry.height <= 0 || geometry.contentHeight <= 0 || !listRef.current) { return; }
    const offset = Math.max(0, geometry.contentHeight - geometry.height);
    positioned.current = 'latest';
    followingBottom.current = true;
    setAwayFromBottom(false);
    if (!intent.started) {
      intent.started = true;
      intent.measuredHeight = geometry.height;
      intent.measuredContentHeight = geometry.contentHeight;
      // First let VirtualizedList materialize its estimated end. Subsequent
      // measured layouts correct that estimate without a timer or list remount.
      listRef.current.scrollToEnd({ animated: false });
      scheduleEntryCheck();
      return;
    }
    if (Math.abs(geometry.offset - offset) <= 1) {
      bottomScroll.current = null;
      confirmEntry();
      scheduleEntryCheck();
      return;
    }
    if (intent.measuredHeight === geometry.height && intent.measuredContentHeight === geometry.contentHeight && intent.correctedOffset === offset) { return; }
    intent.measuredHeight = geometry.height;
    intent.measuredContentHeight = geometry.contentHeight;
    listRef.current.scrollToOffset({ offset, animated: false });
    intent.correctedOffset = offset;
    scheduleEntryCheck();
  }, [confirmEntry, scheduleEntryCheck, scrollSession]);
  entryCorrection.current = correctBottomScroll;
  const onLatestTailLayout = useCallback(() => {
    if (currentEntryWindow.current !== entryWindow) { return; }
    tailWindow.current = entryWindow;
    const intent = bottomScroll.current;
    if (intent && intent.revision === bottomRevision && intent.session === scrollSession && currentScrollSession.current === scrollSession) {
      intent.layoutReady = true;
      correctBottomScroll();
    }
    confirmEntry();
    scheduleEntryCheck();
  }, [bottomRevision, confirmEntry, correctBottomScroll, entryWindow, scheduleEntryCheck, scrollSession]);

  useLayoutEffect(() => {
    const previous = observedNavigation.current;
    observedNavigation.current = { scope, revision: channel.navigationRevision };
    if (previous.scope === scope && previous.revision !== channel.navigationRevision) {
      cancelBottomScroll();
      followingBottom.current = false;
      positioned.current = null;
    }
    if (!entryReady && entry.current.target === null && channel.mode === 'latest' && channel.loaded && channel.active && focused && !taskPickerVisible && !forbidden && !unauthorized) {
      followingBottom.current = true;
      const geometry = scrollGeometry.current;
      const atEnd = geometry.height > 0 && geometry.contentHeight > 0 &&
        Math.abs(geometry.offset - Math.max(0, geometry.contentHeight - geometry.height)) <= 1;
      if (!atEnd && !bottomScroll.current) { commitBottomScroll(armBottomScroll()); }
    }
    confirmEntry();
    scheduleEntryCheck();
  }, [armBottomScroll, cancelBottomScroll, channel.active, channel.busy, channel.loaded, channel.loadingLatest, channel.mode, channel.navigationRevision, channel.polling, commitBottomScroll, confirmEntry, entryKey, entryReady, focused, forbidden, scheduleEntryCheck, scope, taskPickerVisible, unauthorized]);

  useLayoutEffect(() => {
    observedReplacement.current = { scope, revision: channel.latestRevision };
  }, [channel.latestRevision, scope]);

  useLayoutEffect(() => {
    const previous = observedWindow.current;
    observedWindow.current = { scope, messages: channel.messages, latestRevision: channel.latestRevision };
    if (!channel.loaded || !channel.active || !focused || taskPickerVisible || forbidden || unauthorized || channel.mode !== 'latest') { return; }
    const replacement = previous.scope !== scope || previous.latestRevision !== channel.latestRevision;
    const changed = previous.messages !== channel.messages;
    const cancelled = cancelledReplacement.current;
    if (replacement && cancelled?.scope === scope && cancelled.revision === channel.latestRevision) {
      cancelledReplacement.current = null;
      return;
    }
    if (!replacement && (!changed || !followingBottom.current)) { return; }
    const pendingIntent = bottomScroll.current;
    if (pendingIntent?.revision === null) { return; } // The serialized latest request still owns this intent.
    commitBottomScroll(armBottomScroll());
  }, [armBottomScroll, channel.active, channel.latestRevision, channel.loaded, channel.messages, channel.mode, commitBottomScroll, focused, forbidden, scope, taskPickerVisible, unauthorized]);

  const positionInitialWindow = useCallback(() => {
    if (!mounted.current || currentScrollSession.current !== scrollSession || !controls.current.active ||
        !focused || taskPickerVisible || rows.length === 0 || !channel.loaded || channel.busy || bottomScroll.current ||
        channel.targetMessageId === null) { return; }
    const key = `target:${channel.targetMessageId}`;
    if (positioned.current === key) { return; }
    positioned.current = key;
    followingBottom.current = false;
    const index = rows.findIndex(row => row.kind === 'message' && row.message.id === channel.targetMessageId);
    if (index < 0) { return; }
    try {
      listRef.current?.scrollToIndex({ index, viewPosition: 0.5, animated: false });
      scheduleEntryCheck();
    } catch { positioned.current = null; }
  }, [channel.busy, channel.loaded, channel.targetMessageId, focused, rows, scheduleEntryCheck, scrollSession, taskPickerVisible]);
  const onMessageLayout = useCallback(() => {
    positionInitialWindow();
    confirmEntry();
    scheduleEntryCheck();
  }, [confirmEntry, positionInitialWindow, scheduleEntryCheck]);
  const followGeometryChange = useCallback(() => {
    const current = scrollState.current;
    if (!followingBottom.current || current.mode !== 'latest' || !current.loaded || !controls.current.active || !current.focused || current.taskPickerVisible) { return; }
    if (!bottomScroll.current) { commitBottomScroll(armBottomScroll()); }
    else { correctBottomScroll(); }
  }, [armBottomScroll, commitBottomScroll, correctBottomScroll]);
  const onListLayout = useCallback((event: LayoutChangeEvent) => {
    if (!mounted.current || currentScrollSession.current !== scrollSession || !controls.current.active || !focused || taskPickerVisible) { return; }
    const height = event.nativeEvent.layout.height;
    if (!Number.isFinite(height) || height < 0) { return; }
    const changed = scrollGeometry.current.height !== height;
    scrollGeometry.current.height = height;
    updateBottomDistance();
    if (changed) { followGeometryChange(); }
    positionInitialWindow();
    confirmEntry();
    scheduleEntryCheck();
  }, [confirmEntry, focused, followGeometryChange, positionInitialWindow, scheduleEntryCheck, scrollSession, taskPickerVisible, updateBottomDistance]);
  const onListContentSizeChange = useCallback((_width: number, height: number) => {
    if (!mounted.current || currentScrollSession.current !== scrollSession || !controls.current.active || !focused || taskPickerVisible) { return; }
    if (!Number.isFinite(height) || height < 0) { return; }
    const changed = scrollGeometry.current.contentHeight !== height;
    scrollGeometry.current.contentHeight = height;
    updateBottomDistance();
    if (changed) { followGeometryChange(); }
    else { correctBottomScroll(); }
    positionInitialWindow();
    confirmEntry();
    scheduleEntryCheck();
  }, [confirmEntry, correctBottomScroll, focused, followGeometryChange, positionInitialWindow, scheduleEntryCheck, scrollSession, taskPickerVisible, updateBottomDistance]);
  const onListScroll = useCallback((event: NativeSyntheticEvent<NativeScrollEvent>) => {
    if (!mounted.current || currentScrollSession.current !== scrollSession || !controls.current.active || !focused || taskPickerVisible) { return; }
    const { contentOffset, layoutMeasurement, contentSize } = event.nativeEvent;
    const previous = scrollGeometry.current;
    if (![contentOffset.y, layoutMeasurement.height, contentSize.height].every(Number.isFinite)) { return; }
    const resized = previous.height !== layoutMeasurement.height || previous.contentHeight !== contentSize.height;
    scrollGeometry.current = { offset: contentOffset.y, height: layoutMeasurement.height, contentHeight: contentSize.height };
    updateBottomDistance();
    positionInitialWindow();
    confirmEntry();
    scheduleEntryCheck();
    if (resized) { followGeometryChange(); return; }
    const intent = bottomScroll.current;
    if (intent && !intent.started) {
      // Initial/native scroll events can precede the tail layout. Only a drag
      // cancels a committed latest intent before its first positioning pass.
      return;
    } else if (intent) {
      const bottom = Math.max(0, contentSize.height - layoutMeasurement.height);
      if (Math.abs(contentOffset.y - bottom) <= 1) { cancelBottomScroll(); }
      else { correctBottomScroll(); return; }
    }
    const maxOffset = Math.max(0, contentSize.height - layoutMeasurement.height);
    followingBottom.current = layoutMeasurement.height > 0 && maxOffset - Math.max(0, Math.min(contentOffset.y, maxOffset)) <= BOTTOM_SCROLL_THRESHOLD;
  }, [cancelBottomScroll, confirmEntry, correctBottomScroll, focused, followGeometryChange, positionInitialWindow, scheduleEntryCheck, scrollSession, taskPickerVisible, updateBottomDistance]);
  const onListDrag = useCallback(() => {
    if (!mounted.current || currentScrollSession.current !== scrollSession || !controls.current.active || !focused || taskPickerVisible) { return; }
    if (bottomScroll.current?.revision === null || (entry.current.retainedLatest && scrollState.current.loadingLatest)) {
      cancelledReplacement.current = { scope: scopeRef.current, revision: observedReplacement.current.revision + 1 };
    }
    cancelBottomScroll();
    followingBottom.current = false;
    positioned.current = 'latest';
  }, [cancelBottomScroll, focused, scrollSession, taskPickerVisible]);
  const pullRefresh = useCallback(() => {
    if (!mounted.current || currentScrollSession.current !== scrollSession || !controls.current.active || controls.current.blocked ||
        (scrollState.current.busy && !scrollState.current.polling && !scrollState.current.refreshing)) { return; }
    refreshChannel();
  }, [refreshChannel, scrollSession]);

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
    if (!unit || !controls.current.active || !channel.active || !foregroundOnline || offline || sendFlight.current || controls.current.blocked) { return; }
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
      // Read the current callback, not the submitting render's activity session.
      // Inactive success arms a fresh same-scope intent only when it resumes.
      if (controls.current.active) { await latestAction.current(); }
      else { deferredLatest.current = owner; }
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
    if (!mounted.current || currentScrollSession.current !== scrollSession || !controls.current.active || controls.current.blocked ||
        !focused || taskPickerVisible || bottomScroll.current?.revision === null) { return; }
    const intent = armBottomScroll();
    const succeeded = await showLatestChannel();
    if (!succeeded || !mounted.current || currentScrollSession.current !== scrollSession || !controls.current.active) {
      if (bottomScroll.current === intent) { bottomScroll.current = null; }
      return;
    }
    // Do not let a late completion clear a newer explicit navigation target.
    if (bottomScroll.current !== intent) { return; }
    navigation.setParams({ targetMessageId: undefined });
    setSourceError(null);
    positioned.current = 'latest';
    commitBottomScroll(intent);
  }, [armBottomScroll, commitBottomScroll, focused, showLatestChannel, navigation, scrollSession, taskPickerVisible]);
  latestAction.current = showLatest;
  useLayoutEffect(() => {
    if (deferredLatest.current !== scope || !channel.active || !focused || taskPickerVisible || forbidden || unauthorized) { return; }
    deferredLatest.current = null;
    latestAction.current();
  }, [channel.active, focused, forbidden, scope, taskPickerVisible, unauthorized]);
  const scrollToBottom = useCallback(() => {
    if (!mounted.current || currentScrollSession.current !== scrollSession || !controls.current.active || controls.current.blocked ||
        sendFlight.current || sourceFlight.current || (scrollState.current.busy && !scrollState.current.polling)) { return; }
    if (scrollState.current.mode === 'history') { showLatest(); return; }
    cancelBottomScroll();
    followingBottom.current = true;
    positioned.current = 'latest';
    listRef.current?.scrollToEnd({ animated: !scrollState.current.reducedMotion });
  }, [cancelBottomScroll, scrollSession, showLatest]);

  const openSource = useCallback((id: number) => {
    if (!entryReadyRef.current || !controls.current.active || controls.current.blocked || sourceFlight.current || sendFlight.current) { return; }
    const job = Symbol('source');
    const owner = scopeRef.current;
    sourceFlight.current = job;
    cancelBottomScroll();
    followingBottom.current = false;
    setSourceLoading(true); setSourceError(null);
    openChannelMessage(id).then(succeeded => {
      if (!mounted.current || scopeRef.current !== owner || sourceFlight.current !== job) { return; }
      if (!succeeded) { setSourceError('Could not open the original message. It may be unavailable or access may have changed. Tap the quotation to retry.'); }
      else { positioned.current = null; }
    }).finally(() => {
      if (sourceFlight.current === job) { sourceFlight.current = null; }
      if (mounted.current && scopeRef.current === owner) { setSourceLoading(false); }
    });
  }, [cancelBottomScroll, openChannelMessage]);
  const openTask = useCallback((task: TeamChatTaskReference) => {
    if (!entryReadyRef.current || !controls.current.active || controls.current.blocked || sendFlight.current) { return; }
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
    if (!entryReadyRef.current || controls.current.locked || controls.current.blocked || !controls.current.active || sendFlight.current) { return; }
    setReplyTo(message); setComposerError(null); inputRef.current?.focus();
  }, []);
  const renderRow = useCallback(({ item }: ListRenderItemInfo<TeamChatRow>) => {
    if (item.kind === 'timestamp') { return <TeamChatTimestamp createdAt={item.createdAt} messageId={item.messageId} />; }
    if (item.kind === 'unread') {
      return <View accessibilityLabel="Unread messages" style={styles.unreadRow}><Text style={styles.unreadText}>Unread messages</Text></View>;
    }
    if (item.kind === 'read-marker') {
      const last = item.messageId === channel.messages.at(-1)?.id;
      return <View ref={last ? lastMessageRef : undefined} testID={`team-chat-read-marker-${item.messageId}`} collapsable={!last}
        onLayout={last ? onMessageLayout : undefined} accessible={false} importantForAccessibility="no" style={styles.readMarker} />;
    }
    const bubble = <TeamChatMessageBubble message={item.message} own={item.message.sender.id === user?.id}
      startsGroup={item.startsGroup} endsGroup={item.endsGroup} targeted={channel.targetMessageId === item.message.id}
      replyDisabled={locked || !channel.active || forbidden || unauthorized}
      navigationDisabled={!channel.active || sourceLoading || sending || forbidden || unauthorized}
      onReply={chooseReply} onOpenSource={openSource} onOpenTask={openTask} />;
    return item.message.id === channel.targetMessageId
      ? <View ref={targetMessageRef} collapsable={false} onLayout={onMessageLayout}>{bubble}</View> : bubble;
  }, [channel.active, channel.messages, channel.targetMessageId, chooseReply, forbidden, locked, onMessageLayout, openSource, openTask, sending, sourceLoading, styles, unauthorized, user?.id]);

  const header = <PageHeader edgeToEdge title="Team Chat" subtitle={activeUnit?.label ?? 'Choose a Team Workspace'}
    action={<WorkspaceHeaderActions root disabled={sending} />} />;
  const cannotSend = body.trim() === '' || sending || !channel.active || offline || forbidden || unauthorized;
  const editable = !locked && !forbidden && !unauthorized;
  const bottomDisabled = !channel.active || (channel.busy && !channel.polling) || sourceLoading || sending || forbidden || unauthorized;
  const showBottomButton = entryReady && channel.loaded && channel.active && !forbidden && !unauthorized && (awayFromBottom || channel.mode === 'history');
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
    <Screen scroll={false} header={header} bodyStyle={styles.screen} keyboardAware footer={composer}
      footerStyle={[styles.composerFooter, canSwitchContext && !keyboardVisible && styles.raisedComposerFooter]} bottomSafeArea={false}>
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
      {channel.loadingInitial ? <Skeleton label="Loading Team Chat messages" variant="chat" />
        : channel.error && !channel.loaded && !forbidden && !unauthorized ? (
          <View style={styles.center}><Text accessibilityRole="alert" style={styles.errorText}>{teamChatErrorMessage(channel.error, 'Could not load Team Chat.')}</Text>
            <Pressable accessibilityRole="button" accessibilityLabel="Retry loading Team Chat" disabled={!channel.active || channel.busy}
              accessibilityState={{ disabled: !channel.active || channel.busy }} onPress={() => { channel.refresh(); }} style={styles.retryButton}><Text style={styles.retryText}>Try Again</Text></Pressable>
          </View>
        ) : !channel.loaded ? <View style={styles.center}><Text style={styles.emptyBody}>{forbidden || unauthorized ? 'Team Chat is unavailable.' : 'Waiting for an active connection to load Team Chat.'}</Text></View>
          : (
            <View ref={viewportRef} testID="team-chat-viewport" style={styles.listViewport} collapsable={false}>
            <TeamChatTimestampProvider active={entryReady && focused}>
            <FlatList ref={listRef} testID="team-chat-history" data={rows} extraData={viewSession} keyExtractor={item => item.key} renderItem={renderRow}
              pointerEvents={entryReady ? 'auto' : 'none'} scrollEnabled={entryReady}
              accessibilityElementsHidden={!entryReady} importantForAccessibility={entryReady ? 'auto' : 'no-hide-descendants'}
              ListHeaderComponent={channel.hasOlder ? <Pressable accessibilityRole="button" accessibilityLabel="Load older messages"
                accessibilityState={{ busy: channel.loadingOlder, disabled: channel.busy || !channel.active }} disabled={channel.busy || !channel.active}
                onPress={() => { channel.loadOlder(); }} style={styles.olderButton}>
                {channel.loadingOlder ? <ActivityIndicator size="small" color={theme.brandIcon} /> : null}<Text style={styles.olderText}>{channel.loadingOlder ? 'Loading older messages…' : 'Load older messages'}</Text>
              </Pressable> : <View style={styles.listTop} />}
              ListEmptyComponent={<View style={styles.empty}><Text style={styles.emptyTitle}>No messages yet</Text><Text style={styles.emptyBody}>Start an operational team discussion.</Text></View>}
              ListFooterComponent={<View ref={latestTailRef} key={bottomRevision} testID="team-chat-latest-tail" collapsable={false} accessible={false} importantForAccessibility="no-hide-descendants" pointerEvents="none" onLayout={onLatestTailLayout} style={styles.latestTail} />}
              refreshControl={<RefreshControl refreshing={channel.refreshing} enabled={channel.active && (!channel.busy || channel.polling || channel.refreshing)} onRefresh={pullRefresh} tintColor={theme.brandIcon} colors={[theme.brandIcon]} />}
              keyboardDismissMode="interactive" keyboardShouldPersistTaps="handled" automaticallyAdjustKeyboardInsets={false}
              maintainVisibleContentPosition={{ minIndexForVisible: 0 }}
              onViewableItemsChanged={onViewableItemsChanged} viewabilityConfig={viewabilityConfig}
              onLayout={onListLayout} onScroll={onListScroll} scrollEventThrottle={32} alwaysBounceVertical
              onScrollBeginDrag={onListDrag} onContentSizeChange={onListContentSizeChange}
              onScrollToIndexFailed={({ index, averageItemLength }) => {
                if (!mounted.current || currentScrollSession.current !== scrollSession || !controls.current.active || bottomScroll.current) { return; }
                positioned.current = null;
                listRef.current?.scrollToOffset({ offset: Math.max(0, index * averageItemLength), animated: false });
              }}
              contentContainerStyle={[styles.list, rows.length === 0 && styles.emptyList]} style={styles.listViewport}
            />
            </TeamChatTimestampProvider>
            <ChatScrollButton visible={showBottomButton} disabled={bottomDisabled} busy={channel.busy && !channel.polling}
              history={channel.mode === 'history'} onPress={scrollToBottom} />
            <View ref={entryCoverRef} collapsable={false} testID="team-chat-entry-cover"
              style={[StyleSheet.absoluteFill, styles.entryCover, entryReady && styles.entryHidden]}
              pointerEvents={entryReady ? 'none' : 'auto'} accessibilityElementsHidden={entryReady}
              importantForAccessibility={entryReady ? 'no-hide-descendants' : 'auto'}>
              <Skeleton label="Preparing Team Chat messages" variant="chat" active={!entryReady && channel.active} />
              {!entryReady && !channel.active ? <Text accessibilityLiveRegion="polite" style={styles.emptyBody}>Waiting for an active connection to prepare Team Chat.</Text> : null}
            </View>
            </View>
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
    listViewport: { flex: 1 }, list: { paddingBottom: CHAT_LIST_BOTTOM_PADDING }, emptyList: { flexGrow: 1 }, listTop: { height: 8 },
    entryCover: { backgroundColor: theme.canvas, zIndex: 1 }, entryHidden: { opacity: 0 },
    latestTail: { height: 1 },
    readMarker: { height: 2 },
    olderButton: { minHeight: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, marginHorizontal: 12, marginVertical: 8 },
    olderText: { ...type.bodyStrong, color: theme.brandText },
    unreadRow: { alignItems: 'center', marginHorizontal: 12, marginVertical: 8 },
    unreadText: { ...type.caption, color: theme.brandText },
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
    composerFooter: { backgroundColor: theme.canvas, borderTopWidth: 0 },
    raisedComposerFooter: { paddingBottom: CONTEXT_BAR_ACTION_CLEARANCE },
    composerShell: { backgroundColor: theme.canvas },
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
