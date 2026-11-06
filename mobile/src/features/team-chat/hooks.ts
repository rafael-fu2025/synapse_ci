import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useIsFocused } from '@react-navigation/native';
import {
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query';
import * as api from '../../api/endpoints/teamChat';
import type { TeamUnit } from '../../api/types';
import { useAuth } from '../../store/auth';
import {
  CHAT_POLL_MS,
  mergeChatMessages,
  teamChatErrorKind,
  type TeamChatMergeDirection,
  type TeamChatMode,
} from './model';

export interface TeamChatIdentity {
  tenantId: number;
  userId: number;
}

/** Local until the shared query-key module is integrated. */
export const teamChatKeys = {
  root: (identity: TeamChatIdentity) =>
    ['team-chat', identity.tenantId, identity.userId] as const,
  unit: (identity: TeamChatIdentity, unit: TeamUnit) =>
    ['team-chat', identity.tenantId, identity.userId, unit] as const,
  history: (
    identity: TeamChatIdentity,
    unit: TeamUnit,
    cursor: api.TeamChatHistoryCursor,
  ) => ['team-chat', identity.tenantId, identity.userId, unit, 'history', cursor] as const,
  tasks: (identity: TeamChatIdentity, unit: TeamUnit, q: string) =>
    ['team-chat', identity.tenantId, identity.userId, unit, 'tasks', q.trim()] as const,
  members: (identity: TeamChatIdentity, unit: TeamUnit, q: string) =>
    ['team-chat', identity.tenantId, identity.userId, unit, 'members', q.trim()] as const,
} as const;

export function useTeamChatIdentity(): TeamChatIdentity {
  const { user } = useAuth();
  const tenantId = user?.tenant_id ?? 0;
  const userId = user?.id ?? 0;
  return useMemo(() => ({ tenantId, userId }), [tenantId, userId]);
}

function requireUnit(unit: TeamUnit | null): TeamUnit {
  if (unit === null) { throw new Error('A Team Workspace unit is required.'); }
  return unit;
}

export async function purgeTeamChatUnit(
  client: QueryClient,
  identity: TeamChatIdentity,
  unit: TeamUnit,
): Promise<void> {
  const key = teamChatKeys.unit(identity, unit);
  await client.cancelQueries({ queryKey: key, exact: false });
  client.removeQueries({ queryKey: key, exact: false });
}

interface UseTeamChatChannelOptions {
  unit: TeamUnit | null;
  targetMessageId?: number;
}

export interface TeamChatChannelState {
  mode: TeamChatMode;
  targetMessageId: number | null;
  messages: api.TeamChatMessage[];
  summary: api.TeamChatReadSummary;
  hasOlder: boolean;
  loadingInitial: boolean;
  loadingOlder: boolean;
  polling: boolean;
  error: unknown;
  updateError: unknown;
  updatedAt: number;
  loadOlder: () => Promise<void>;
  showLatest: () => Promise<void>;
  refresh: () => Promise<void>;
  acknowledge: (messageId: number) => void;
  clear: () => void;
}

const EMPTY_SUMMARY: api.TeamChatReadSummary = {
  last_read_id: 0,
  unread_count: 0,
  latest_id: 0,
};

/**
 * Bounded in-memory channel window. Polling uses `after`, drains bounded server
 * pages, and runs only while the chat route is focused and in latest mode.
 */
export function useTeamChatChannel({
  unit,
  targetMessageId,
}: UseTeamChatChannelOptions): TeamChatChannelState {
  const identity = useTeamChatIdentity();
  const client = useQueryClient();
  const focused = useIsFocused();
  const target = Number.isSafeInteger(targetMessageId) && (targetMessageId ?? 0) > 0
    ? targetMessageId!
    : null;
  const initialMode: TeamChatMode = target === null ? 'latest' : 'history';
  const [mode, setMode] = useState<TeamChatMode>(initialMode);
  const [activeTarget, setActiveTarget] = useState<number | null>(target);
  const [messages, setMessages] = useState<api.TeamChatMessage[]>([]);
  const [summary, setSummary] = useState<api.TeamChatReadSummary>(EMPTY_SUMMARY);
  const [hasOlder, setHasOlder] = useState(false);
  const [loadingOlder, setLoadingOlder] = useState(false);
  const [polling, setPolling] = useState(false);
  const [updateError, setUpdateError] = useState<unknown>(null);
  const [updatedAt, setUpdatedAt] = useState(0);
  const generation = useRef(0);
  const messagesRef = useRef<api.TeamChatMessage[]>([]);
  const maximumRef = useRef(0);
  const modeRef = useRef<TeamChatMode>(initialMode);
  const readQueued = useRef(0);
  const readCursorRef = useRef(0);
  const readInFlight = useRef(false);

  const enabled = unit !== null && identity.tenantId > 0 && identity.userId > 0;
  const initialCursor = useMemo<api.TeamChatHistoryCursor>(
    () => target === null ? {} : { around: target },
    [target],
  );
  const initial = useQuery({
    queryKey: unit === null
      ? [...teamChatKeys.root(identity), 'disabled'] as const
      : teamChatKeys.history(identity, unit, initialCursor),
    queryFn: () => api.history(unit!, initialCursor),
    enabled,
    staleTime: 0,
    retry: false,
    refetchOnWindowFocus: false,
  });

  const applyPage = useCallback((
    page: api.TeamChatHistory,
    direction: TeamChatMergeDirection,
    replace = false,
  ) => {
    const previous = replace ? [] : messagesRef.current;
    const next = mergeChatMessages(previous, page.messages, direction);
    const evictedOldest = direction === 'newer'
      && previous.length > 0
      && next.length > 0
      && next[0].id > previous[0].id;
    messagesRef.current = next;
    maximumRef.current = next.at(-1)?.id ?? 0;
    setMessages(next);
    setSummary(current => {
      const nextSummary = {
        last_read_id: Math.max(current.last_read_id, page.last_read_id),
        unread_count: page.unread_count,
        latest_id: page.latest_id,
      };
      readCursorRef.current = nextSummary.last_read_id;
      return nextSummary;
    });
    if (direction === 'older' || direction === 'latest') {
      setHasOlder(page.has_more);
    } else if (evictedOldest) {
      // Newer polling evicted the oldest side of the bounded 400-message window.
      setHasOlder(true);
    }
    setUpdateError(null);
    setUpdatedAt(Date.now());
  }, []);

  useEffect(() => {
    generation.current += 1;
    const nextMode: TeamChatMode = target === null ? 'latest' : 'history';
    modeRef.current = nextMode;
    setMode(nextMode);
    setActiveTarget(target);
    setMessages([]);
    messagesRef.current = [];
    maximumRef.current = 0;
    setSummary(EMPTY_SUMMARY);
    readCursorRef.current = 0;
    setHasOlder(false);
    setUpdateError(null);
    setUpdatedAt(0);
    readQueued.current = 0;
    readInFlight.current = false;
  }, [identity.tenantId, identity.userId, target, unit]);

  useEffect(() => {
    if (!focused) {
      generation.current += 1;
      setPolling(false);
      if (unit !== null) {
        client.cancelQueries({
          queryKey: teamChatKeys.unit(identity, unit),
          exact: false,
        }).catch(() => undefined);
      }
    }
  }, [client, focused, identity, unit]);

  useEffect(() => {
    if (unit === null || identity.tenantId < 1 || identity.userId < 1) { return; }
    const scopedIdentity = identity;
    const scopedUnit = unit;
    return () => {
      // Unit switches, entitlement loss, and unmounts must not retain protected chat.
      purgeTeamChatUnit(client, scopedIdentity, scopedUnit).catch(() => undefined);
    };
  }, [client, identity, unit]);

  useEffect(() => {
    if (initial.data?.data !== undefined) {
      applyPage(initial.data.data, 'latest', true);
    }
  }, [applyPage, initial.data]);

  useEffect(() => {
    if (!unit || !initial.error) { return; }
    const kind = teamChatErrorKind(initial.error);
    if (kind === 'unauthorized' || kind === 'forbidden') {
      generation.current += 1;
      setMessages([]);
      messagesRef.current = [];
      maximumRef.current = 0;
      setSummary(EMPTY_SUMMARY);
      readCursorRef.current = 0;
      purgeTeamChatUnit(client, identity, unit).catch(() => undefined);
    }
  }, [client, identity, initial.error, unit]);

  const fetchPage = useCallback(async (cursor: api.TeamChatHistoryCursor) => {
    if (!unit) { throw new Error('A Team Workspace unit is required.'); }
    return client.fetchQuery({
      queryKey: teamChatKeys.history(identity, unit, cursor),
      queryFn: () => api.history(unit, cursor),
      staleTime: 0,
    });
  }, [client, identity, unit]);

  const handleProtectedFailure = useCallback(async (error: unknown) => {
    if (!unit) { return; }
    const kind = teamChatErrorKind(error);
    if (kind === 'unauthorized' || kind === 'forbidden') {
      generation.current += 1;
      setMessages([]);
      messagesRef.current = [];
      maximumRef.current = 0;
      setSummary(EMPTY_SUMMARY);
      readCursorRef.current = 0;
      await purgeTeamChatUnit(client, identity, unit);
    }
  }, [client, identity, unit]);

  const pollAfter = useCallback(async () => {
    if (!enabled || !unit || modeRef.current !== 'latest') { return; }
    const run = generation.current;
    setPolling(true);
    try {
      let cursor = maximumRef.current;
      // Drain pages in order. The loop remains bounded to avoid a runaway client.
      for (let pageNumber = 0; pageNumber < 10; pageNumber += 1) {
        const response = await fetchPage({ after: cursor });
        if (run !== generation.current || modeRef.current !== 'latest') { return; }
        applyPage(response.data, 'newer');
        const next = response.data.messages.at(-1)?.id ?? cursor;
        if (!response.data.has_more || next <= cursor) { break; }
        cursor = next;
      }
    } catch (error) {
      if (run === generation.current) {
        setUpdateError(error);
        await handleProtectedFailure(error);
      }
    } finally {
      if (run === generation.current) { setPolling(false); }
    }
  }, [applyPage, enabled, fetchPage, handleProtectedFailure, unit]);

  useEffect(() => {
    if (!focused || !enabled || mode !== 'latest' || initial.isLoading) { return; }
    const timer = setInterval(() => { pollAfter().catch(() => undefined); }, CHAT_POLL_MS);
    return () => clearInterval(timer);
  }, [enabled, focused, initial.isLoading, mode, pollAfter]);

  const loadOlder = useCallback(async () => {
    const first = messagesRef.current[0]?.id;
    if (!unit || !first || loadingOlder || !hasOlder) { return; }
    const run = generation.current;
    setLoadingOlder(true);
    // Browsing older content is intentionally historical and pauses read/poll writes.
    modeRef.current = 'history';
    setMode('history');
    setActiveTarget(current => current ?? first);
    try {
      const response = await fetchPage({ before: first });
      if (run !== generation.current) { return; }
      applyPage(response.data, 'older');
    } catch (error) {
      if (run === generation.current) {
        setUpdateError(error);
        await handleProtectedFailure(error);
      }
    } finally {
      if (run === generation.current) { setLoadingOlder(false); }
    }
  }, [applyPage, fetchPage, handleProtectedFailure, hasOlder, loadingOlder, unit]);

  const showLatest = useCallback(async () => {
    if (!unit) { return; }
    const run = ++generation.current;
    modeRef.current = 'latest';
    setMode('latest');
    setActiveTarget(null);
    setPolling(true);
    try {
      const response = await fetchPage({});
      if (run !== generation.current) { return; }
      applyPage(response.data, 'latest', true);
    } catch (error) {
      if (run === generation.current) {
        setUpdateError(error);
        await handleProtectedFailure(error);
      }
    } finally {
      if (run === generation.current) { setPolling(false); }
    }
  }, [applyPage, fetchPage, handleProtectedFailure, unit]);

  const refresh = useCallback(async () => {
    if (modeRef.current === 'history' && activeTarget !== null) {
      const run = ++generation.current;
      setPolling(true);
      try {
        const response = await fetchPage({ around: activeTarget });
        if (run === generation.current) { applyPage(response.data, 'latest', true); }
      } catch (error) {
        if (run === generation.current) {
          setUpdateError(error);
          await handleProtectedFailure(error);
        }
      } finally {
        if (run === generation.current) { setPolling(false); }
      }
      return;
    }
    await pollAfter();
  }, [activeTarget, applyPage, fetchPage, handleProtectedFailure, pollAfter]);

  const flushRead = useCallback(async () => {
    if (!unit || readInFlight.current || modeRef.current !== 'latest') { return; }
    const next = readQueued.current;
    if (next <= readCursorRef.current) { return; }
    readInFlight.current = true;
    try {
      const response = await api.markRead(unit, next);
      setSummary(current => {
        const nextSummary = {
          last_read_id: Math.max(current.last_read_id, response.data.last_read_id),
          unread_count: response.data.unread_count,
          latest_id: response.data.latest_id,
        };
        readCursorRef.current = nextSummary.last_read_id;
        return nextSummary;
      });
      setUpdateError(null);
    } catch (error) {
      setUpdateError(error);
      await handleProtectedFailure(error);
    } finally {
      readInFlight.current = false;
      if (readQueued.current > next) { flushRead().catch(() => undefined); }
    }
  }, [handleProtectedFailure, unit]);

  const acknowledge = useCallback((messageId: number) => {
    if (
      modeRef.current !== 'latest' ||
      !Number.isSafeInteger(messageId) ||
      messageId <= readCursorRef.current
    ) { return; }
    readQueued.current = Math.max(readQueued.current, messageId);
    flushRead().catch(() => undefined);
  }, [flushRead]);

  const clear = useCallback(() => {
    generation.current += 1;
    setMessages([]);
    messagesRef.current = [];
    maximumRef.current = 0;
    setSummary(EMPTY_SUMMARY);
    readCursorRef.current = 0;
    setHasOlder(false);
    setUpdateError(null);
  }, []);

  return {
    mode,
    targetMessageId: activeTarget,
    messages,
    summary,
    hasOlder,
    loadingInitial: initial.isLoading && messages.length === 0,
    loadingOlder,
    polling,
    error: initial.error,
    updateError,
    updatedAt,
    loadOlder,
    showLatest,
    refresh,
    acknowledge,
    clear,
  };
}

export function useTeamChatTaskSearch(unit: TeamUnit | null, q: string, open: boolean) {
  const identity = useTeamChatIdentity();
  return useQuery({
    queryKey: unit === null
      ? [...teamChatKeys.root(identity), 'tasks-disabled'] as const
      : teamChatKeys.tasks(identity, unit, q),
    queryFn: () => api.tasks(unit!, q),
    enabled: open && unit !== null && identity.tenantId > 0 && identity.userId > 0,
    staleTime: 15_000,
    retry: false,
  });
}

export function useTeamChatMemberSearch(unit: TeamUnit | null, q: string, open: boolean) {
  const identity = useTeamChatIdentity();
  return useQuery({
    queryKey: unit === null
      ? [...teamChatKeys.root(identity), 'members-disabled'] as const
      : teamChatKeys.members(identity, unit, q),
    queryFn: () => api.members(unit!, q),
    enabled: open && unit !== null && identity.tenantId > 0 && identity.userId > 0,
    staleTime: 30_000,
    retry: false,
  });
}

export function useSendTeamChatMessage(unit: TeamUnit | null) {
  return useMutation({
    mutationFn: (input: api.TeamChatSendInput) => api.send(requireUnit(unit), input),
  });
}
