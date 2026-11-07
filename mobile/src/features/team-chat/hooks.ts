import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useIsFocused } from '@react-navigation/native';
import {
  matchQuery,
  onlineManager,
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query';
import * as api from '../../api/endpoints/teamChat';
import { NetworkError } from '../../api/client';
import type { TeamUnit } from '../../api/types';
import { useActiveScreen, useForegroundOnline } from '../../lib/useActiveScreen';
import { useRealtimeReady } from '../../lib/useRealtimeReady';
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

export const teamChatKeys = {
  root: (identity: TeamChatIdentity) =>
    ['team-chat', identity.tenantId, identity.userId] as const,
  unit: (identity: TeamChatIdentity, unit: TeamUnit) =>
    ['team-chat', identity.tenantId, identity.userId, unit] as const,
  history: (identity: TeamChatIdentity, unit: TeamUnit, cursor: api.TeamChatHistoryCursor) =>
    ['team-chat', identity.tenantId, identity.userId, unit, 'history', cursor] as const,
  /** Task changes invalidate this prefix, never the chat's current history window. */
  taskPicker: (identity: TeamChatIdentity, unit: TeamUnit) =>
    ['team-chat', identity.tenantId, identity.userId, unit, 'tasks'] as const,
  tasks: (identity: TeamChatIdentity, unit: TeamUnit, q: string) =>
    [...teamChatKeys.taskPicker(identity, unit), q.trim()] as const,
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

export async function purgeTeamChatUnit(client: QueryClient, identity: TeamChatIdentity, unit: TeamUnit): Promise<void> {
  const key = teamChatKeys.unit(identity, unit);
  const cancelled = client.cancelQueries({ queryKey: key, exact: false });
  // Remove synchronously: a delayed cleanup must not remove a newly mounted scope.
  client.removeQueries({ queryKey: key, exact: false });
  await cancelled;
}

interface UseTeamChatChannelOptions {
  unit: TeamUnit | null;
  targetMessageId?: number;
  /** Also pause immediately while our own sheet is being presented/queued. */
  paused?: boolean;
}

type RequestKind = 'initial' | 'poll' | 'refresh' | 'older' | 'latest' | 'around' | 'read';
type WindowRequest = Exclude<RequestKind, 'read'>;

interface ChannelSnapshot {
  mode: TeamChatMode;
  targetMessageId: number | null;
  messages: api.TeamChatMessage[];
  summary: api.TeamChatReadSummary;
  hasOlder: boolean;
  loaded: boolean;
  request: RequestKind | null;
  error: unknown;
  updateError: unknown;
  updatedAt: number;
}

export interface TeamChatChannelState extends Omit<ChannelSnapshot, 'request'> {
  active: boolean;
  busy: boolean;
  loadingInitial: boolean;
  loadingOlder: boolean;
  refreshing: boolean;
  polling: boolean;
  loadOlder: () => Promise<boolean>;
  showLatest: () => Promise<boolean>;
  openMessage: (messageId: number) => Promise<boolean>;
  refresh: () => Promise<boolean>;
  acknowledge: (messageId: number) => void;
  clear: () => void;
}

const EMPTY_SUMMARY: api.TeamChatReadSummary = { last_read_id: 0, unread_count: 0, latest_id: 0 };

function emptyChannel(target: number | null): ChannelSnapshot {
  return {
    mode: target === null ? 'latest' : 'history', targetMessageId: target,
    messages: [], summary: EMPTY_SUMMARY, hasOlder: false, loaded: false,
    request: null, error: null, updateError: null, updatedAt: 0,
  };
}

interface ChannelJob {
  kind: RequestKind;
  target?: number;
  unit: TeamUnit;
  identity: TeamChatIdentity;
  scope: string;
  generation: number;
  invalidation: number;
  promise: Promise<boolean>;
  resolve: (succeeded: boolean) => void;
}

/**
 * One physical request at a time, including reads. The API has no AbortSignal
 * contract, so an invalidated request keeps its lock until it actually settles.
 * Only its captured scope/activity generation can publish a result. A queued
 * manual navigation supersedes background work without racing another fetch.
 */
export function useTeamChatChannel({ unit, targetMessageId, paused = false }: UseTeamChatChannelOptions): TeamChatChannelState {
  const identity = useTeamChatIdentity();
  const client = useQueryClient();
  const realtime = useRealtimeReady();
  const enabled = unit !== null && identity.tenantId > 0 && identity.userId > 0;
  const active = useActiveScreen(enabled && !paused);
  const target = Number.isSafeInteger(targetMessageId) && (targetMessageId ?? 0) > 0 ? targetMessageId! : null;
  const scope = `${identity.tenantId}:${identity.userId}:${unit ?? 'none'}`;
  const [snapshot, setSnapshot] = useState<ChannelSnapshot>(() => emptyChannel(target));
  const state = useRef(snapshot);
  const context = useRef({ scope, unit, identity, active });
  context.current = { scope, unit, identity, active };
  const mounted = useRef(false);
  const generation = useRef(0);
  const blocked = useRef(false);
  const flight = useRef<ChannelJob | null>(null);
  const queued = useRef<ChannelJob | null>(null);
  const readQueued = useRef(0);
  const invalidation = useRef(0);
  const reconciled = useRef(0);
  const routeTarget = useRef(target);
  const resumeWindow = useRef<{ kind: 'latest' | 'around'; target?: number } | null>(null);
  const requestRef = useRef<(kind: WindowRequest, id?: number) => Promise<boolean>>(() => Promise.resolve(false));
  const executeRef = useRef<(job: ChannelJob) => Promise<void>>(async () => undefined);
  const drainRef = useRef<() => void>(() => undefined);

  const signalKey = useMemo(() => unit === null
    ? [...teamChatKeys.root(identity), 'disabled'] as const
    : teamChatKeys.history(identity, unit, {}), [identity, unit]);
  // This disabled observer receives invalidation hints, not pages. In particular,
  // a realtime refetch must never reapply the original around/latest response to
  // a window the user has since paged through. All HTTP uses the runner below.
  useQuery({
    queryKey: signalKey, queryFn: async () => null, initialData: null,
    enabled: false, gcTime: 0, notifyOnChangeProps: [],
  });

  const publish = useCallback((patch: Partial<ChannelSnapshot>) => {
    const next = { ...state.current, ...patch };
    state.current = next;
    if (mounted.current) { setSnapshot(next); }
  }, []);

  const current = useCallback((job: ChannelJob) => mounted.current &&
    context.current.active && context.current.scope === job.scope &&
    generation.current === job.generation && !blocked.current, []);

  const cancelQueued = useCallback(() => {
    queued.current?.resolve(false);
    queued.current = null;
    readQueued.current = 0;
  }, []);

  const applySummary = useCallback((incoming: api.TeamChatReadSummary) => {
    const previous = state.current.summary;
    const next = {
      last_read_id: Math.max(previous.last_read_id, incoming.last_read_id),
      latest_id: Math.max(previous.latest_id, incoming.latest_id),
      unread_count: incoming.last_read_id >= previous.last_read_id && incoming.latest_id >= previous.latest_id
        ? incoming.unread_count : previous.unread_count,
    };
    return next.last_read_id === previous.last_read_id && next.latest_id === previous.latest_id &&
      next.unread_count === previous.unread_count ? previous : next;
  }, []);

  const applyPage = useCallback((page: api.TeamChatHistory, direction: TeamChatMergeDirection, replace = false) => {
    const previous = state.current.messages;
    const messages = mergeChatMessages(previous, page.messages, direction, undefined, replace);
    const evictedOldest = !replace && direction === 'newer' && previous.length > 0 &&
      messages.length > 0 && messages[0].id > previous[0].id;
    publish({
      messages, summary: applySummary(page), loaded: true,
      hasOlder: direction === 'older' || direction === 'latest' ? page.has_more :
        evictedOldest || state.current.hasOlder,
      error: null, updateError: null, updatedAt: Date.now(),
    });
  }, [applySummary, publish]);

  const makeJob = useCallback((kind: RequestKind, id?: number): ChannelJob | null => {
    const owner = context.current;
    if (!owner.unit) { return null; }
    let resolve!: (succeeded: boolean) => void;
    const promise = new Promise<boolean>(done => { resolve = done; });
    return {
      kind, target: id, unit: owner.unit, identity: owner.identity, scope: owner.scope,
      generation: generation.current, invalidation: invalidation.current, promise, resolve,
    };
  }, []);

  drainRef.current = () => {
    if (flight.current || !mounted.current || !context.current.active || blocked.current) { return; }
    let job = queued.current;
    queued.current = null;
    if (job && !current(job)) { job.resolve(false); job = null; }
    if (!job && state.current.mode === 'latest' && state.current.loaded &&
        readQueued.current > state.current.summary.last_read_id) {
      job = makeJob('read', readQueued.current);
    }
    if (!job) { return; }
    flight.current = job; // Synchronous, before the first await or React render.
    if (job.kind !== 'read') { publish({ request: job.kind }); }
    executeRef.current(job);
  };

  executeRef.current = async job => {
    let succeeded = false;
    try {
      if (!current(job)) { return; }
      if (job.kind === 'read') {
        const response = await api.markRead(job.unit, job.target!);
        if (!current(job) || state.current.mode !== 'latest') { return; }
        publish({ summary: applySummary(response.data) });
        // Do not turn a successful read into a false claim that a failed refresh recovered.
        if (readQueued.current <= job.target!) { readQueued.current = 0; }
      } else if ((job.kind === 'poll' || job.kind === 'refresh') &&
                 state.current.mode === 'latest' && state.current.loaded) {
        let cursor = state.current.messages.at(-1)?.id ?? 0;
        for (let pageNumber = 0; pageNumber < 10; pageNumber += 1) {
          const response = await api.history(job.unit, { after: cursor });
          if (!current(job) || state.current.mode !== 'latest') { return; }
          applyPage(response.data, 'newer');
          const next = Math.max(cursor, ...response.data.messages.map(message => message.id));
          if (!response.data.has_more || next <= cursor) { break; }
          cursor = next;
        }
      } else {
        const before = state.current.messages[0]?.id;
        const last = state.current.messages.at(-1)?.id;
        const around = job.kind === 'around' ? job.target : job.kind === 'initial'
          ? state.current.targetMessageId : job.kind === 'refresh' && state.current.mode === 'history'
            ? last ?? state.current.targetMessageId : null;
        if (job.kind === 'older' && (!before || !state.current.hasOlder)) { return; }
        const cursor: api.TeamChatHistoryCursor = job.kind === 'older' ? { before: before! }
          : around ? { around } : {};
        const response = await api.history(job.unit, cursor);
        if (!current(job)) { return; }
        if (around && !response.data.messages.some(message => message.id === around)) {
          throw new Error('The original message is no longer available in this Team Workspace.');
        }
        if (job.kind === 'older') {
          applyPage(response.data, 'older');
          publish({ mode: 'history' });
        } else if (job.kind === 'refresh' && state.current.loaded && state.current.mode === 'history') {
          // Refresh the visible window's newest page without throwing away loaded history.
          const hasOlder = state.current.hasOlder;
          applyPage(response.data, 'older');
          publish({ hasOlder });
        } else {
          applyPage(response.data, 'latest', true);
          if (job.kind === 'around') { publish({ mode: 'history', targetMessageId: job.target! }); }
          if (job.kind === 'latest') { publish({ mode: 'latest', targetMessageId: null }); }
        }
      }
      if (!current(job)) { return; }
      succeeded = true;
      if (job.kind !== 'read') {
        reconciled.current = job.invalidation;
        client.setQueryData(teamChatKeys.history(job.identity, job.unit, {}), { updatedAt: state.current.updatedAt });
        if (job.kind === 'around' || job.kind === 'latest') { resumeWindow.current = null; }
      }
    } catch (error) {
      if (!current(job)) { return; }
      const kind = teamChatErrorKind(error);
      if (kind === 'unauthorized' || kind === 'forbidden') {
        blocked.current = true;
        generation.current += 1;
        cancelQueued();
        resumeWindow.current = null;
        publish({ ...emptyChannel(null), error });
        purgeTeamChatUnit(client, job.identity, job.unit).catch(() => undefined);
      } else {
        publish(state.current.loaded ? { updateError: error } : { error });
        if (job.kind === 'read') { readQueued.current = 0; }
        if (job.kind === 'around' || job.kind === 'latest') { resumeWindow.current = null; }
      }
    } finally {
      if (flight.current === job) { flight.current = null; }
      const valid = current(job);
      if (valid && job.kind !== 'read') { publish({ request: null }); }
      job.resolve(succeeded && valid);
      if (mounted.current && context.current.active && !blocked.current) {
        if (queued.current) { drainRef.current(); }
        else if (succeeded && state.current.mode === 'latest' && invalidation.current > reconciled.current) {
          requestRef.current('poll');
        } else { drainRef.current(); }
      }
    }
  };

  requestRef.current = (kind, id) => {
    if (!mounted.current || !context.current.unit || blocked.current) { return Promise.resolve(false); }
    if (kind === 'around' && (!Number.isSafeInteger(id) || id! <= 0)) { return Promise.resolve(false); }
    if (kind === 'latest' || kind === 'around') { resumeWindow.current = { kind, target: id }; }
    if (!context.current.active) { return Promise.resolve(false); }
    if (kind === 'poll' && (!state.current.loaded || state.current.mode !== 'latest')) { return Promise.resolve(false); }
    if (kind === 'older' && (!state.current.hasOlder || state.current.messages.length === 0)) { return Promise.resolve(false); }
    const duplicate = [queued.current, flight.current].find(job => job && current(job) && job.kind === kind && job.target === id);
    if (duplicate) { return duplicate.promise; }
    if (kind === 'poll' && (queued.current || flight.current)) { return Promise.resolve(false); }
    // Manual intent wins over an old fetch/read result. The physical lock is kept.
    generation.current += 1;
    cancelQueued();
    const job = makeJob(kind, id);
    if (!job) { return Promise.resolve(false); }
    queued.current = job;
    publish({ request: kind, ...(state.current.loaded ? {} : { error: null }) });
    drainRef.current();
    return job.promise;
  };

  useLayoutEffect(() => {
    mounted.current = true;
    generation.current += 1;
    blocked.current = false;
    cancelQueued();
    invalidation.current = 0;
    reconciled.current = 0;
    resumeWindow.current = null;
    routeTarget.current = target;
    publish(emptyChannel(target));
    return () => {
      mounted.current = false;
      generation.current += 1;
      cancelQueued();
      resumeWindow.current = null;
      if (unit !== null) { purgeTeamChatUnit(client, identity, unit).catch(() => undefined); }
    };
    // A new route target does not own the channel lifetime or dispose its window.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [client, scope, cancelQueued, publish]);

  useLayoutEffect(() => {
    generation.current += 1;
    cancelQueued();
    publish({ request: null });
    if (!active || blocked.current) { return; }
    const resume = resumeWindow.current;
    if (resume) { requestRef.current(resume.kind, resume.target); }
    else if (!state.current.loaded && !state.current.error) { requestRef.current('initial'); }
    else if (state.current.loaded && state.current.mode === 'latest') { requestRef.current('poll'); }
  }, [active, scope, cancelQueued, publish]);

  useEffect(() => {
    if (routeTarget.current === target) { return; }
    routeTarget.current = target;
    // Clearing a consumed navigation parameter must not start another latest fetch.
    if (target !== null) { requestRef.current('around', target); }
  }, [scope, target]);

  useEffect(() => client.getQueryCache().subscribe(event => {
    if (event.type !== 'updated' || event.action.type !== 'invalidate' ||
        !matchQuery({ queryKey: signalKey, exact: true }, event.query)) { return; }
    invalidation.current += 1;
    // Re-arm this signal only; a failed HTTP read must not swallow later hints.
    // Actual freshness and the retained window advance only after a successful read.
    event.query.setState({ isInvalidated: false });
    if (context.current.active && state.current.mode === 'latest') { requestRef.current('poll'); }
  }), [client, signalKey]);

  useEffect(() => {
    if (!active || realtime || !snapshot.loaded || snapshot.mode !== 'latest' || blocked.current) { return; }
    const timer = setInterval(() => { requestRef.current('poll'); }, CHAT_POLL_MS);
    return () => clearInterval(timer);
  }, [active, realtime, snapshot.loaded, snapshot.mode]);

  const acknowledge = useCallback((messageId: number) => {
    const value = state.current;
    if (!mounted.current || !context.current.active || blocked.current || !value.loaded ||
        value.mode !== 'latest' || (value.request !== null && value.request !== 'poll') ||
        !Number.isSafeInteger(messageId) || messageId <= value.summary.last_read_id ||
        !value.messages.some(message => message.id === messageId)) { return; }
    readQueued.current = Math.max(readQueued.current, messageId);
    drainRef.current();
  }, []);

  const clear = useCallback(() => {
    generation.current += 1;
    blocked.current = true;
    cancelQueued();
    resumeWindow.current = null;
    publish({ ...emptyChannel(null), error: state.current.error });
  }, [cancelQueued, publish]);
  const loadOlder = useCallback(() => requestRef.current('older'), []);
  const showLatest = useCallback(() => requestRef.current('latest'), []);
  const openMessage = useCallback((id: number) => requestRef.current('around', id), []);
  const refresh = useCallback(() => requestRef.current('refresh'), []);

  return {
    ...snapshot, active, busy: snapshot.request !== null,
    loadingInitial: !snapshot.loaded && snapshot.request !== null,
    loadingOlder: snapshot.request === 'older', refreshing: snapshot.request === 'refresh',
    polling: snapshot.request === 'poll', loadOlder, showLatest, openMessage, refresh, acknowledge, clear,
  };
}

export function useTeamChatTaskSearch(unit: TeamUnit | null, q: string, open: boolean) {
  const identity = useTeamChatIdentity();
  // This search lives INSIDE an overlay, so excluding all overlays would disable it.
  const focused = useIsFocused();
  const foregroundOnline = useForegroundOnline();
  return useQuery({
    queryKey: unit === null ? [...teamChatKeys.root(identity), 'tasks-disabled'] as const : teamChatKeys.tasks(identity, unit, q),
    queryFn: () => api.tasks(unit!, q),
    enabled: open && focused && foregroundOnline && unit !== null && identity.tenantId > 0 && identity.userId > 0,
    staleTime: 15_000, retry: false,
  });
}

export function useTeamChatMemberSearch(unit: TeamUnit | null, q: string, open: boolean) {
  const identity = useTeamChatIdentity();
  const active = useActiveScreen(open && unit !== null && identity.tenantId > 0 && identity.userId > 0);
  return useQuery({
    queryKey: unit === null ? [...teamChatKeys.root(identity), 'members-disabled'] as const : teamChatKeys.members(identity, unit, q),
    queryFn: () => api.members(unit!, q), enabled: active,
    staleTime: 30_000, retry: false,
  });
}

export function useSendTeamChatMessage(unit: TeamUnit | null) {
  return useMutation({
    mutationFn: (input: api.TeamChatSendInput) => {
      if (!onlineManager.isOnline()) { throw new NetworkError(); }
      return api.send(requireUnit(unit), input);
    },
    retry: false,
    networkMode: 'always', // Never park a send for automatic reconnect replay.
  });
}
