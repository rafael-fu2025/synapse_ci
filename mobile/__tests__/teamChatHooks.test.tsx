import React from 'react';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { QueryClient, QueryClientProvider, focusManager, onlineManager } from '@tanstack/react-query';
import { ApiError, NetworkError } from '../src/api/client';
import * as api from '../src/api/endpoints/teamChat';
import type { TeamUnit } from '../src/api/types';
import { ownUserRealtime } from '../src/lib/realtime/transportState';
import { teamChatKeys, useSendTeamChatMessage, useTeamChatChannel, useTeamChatMemberSearch, useTeamChatTaskSearch, type TeamChatChannelState } from '../src/features/team-chat/hooks';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn(), clearRefreshToken: jest.fn() }));
jest.mock('../src/api/endpoints/teamChat', () => ({ history: jest.fn(), markRead: jest.fn(), tasks: jest.fn(), members: jest.fn(), send: jest.fn() }));
let mockFocused = true;
let mockForegroundOnline = true;
let mockOverlay = false;
let mockIdentity = { id: 12, tenant_id: 9 };
jest.mock('@react-navigation/native', () => ({ useIsFocused: () => mockFocused }));
jest.mock('../src/store/auth', () => ({ useAuth: () => ({ user: mockIdentity }) }));
jest.mock('../src/lib/useActiveScreen', () => ({
  useActiveScreen: (enabled = true) => enabled && mockFocused && mockForegroundOnline && !mockOverlay,
  useForegroundOnline: () => mockForegroundOnline,
}));

function message(id: number, changes: Partial<api.TeamChatMessage> = {}): api.TeamChatMessage {
  return { id, body: `Message ${id}`, sender: { id: 2, name: 'Member' }, created_at: '2026-11-07T00:00:00+00:00', client_nonce: null, tasks: [], mentions: [], reply_to: null, ...changes };
}
function page(ids: number[], changes: Partial<api.TeamChatHistory> = {}) {
  return { data: { messages: ids.map(id => message(id)), has_more: false, next_before: null, last_read_id: 0, unread_count: ids.length, latest_id: ids.at(-1) ?? 0, ...changes } };
}
function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
let renderer: ReactTestRenderer | undefined;
let client: QueryClient;
let channel!: TeamChatChannelState;
let send!: ReturnType<typeof useSendTeamChatMessage>;
let taskSearch!: ReturnType<typeof useTeamChatTaskSearch>;
let memberSearch!: ReturnType<typeof useTeamChatMemberSearch>;
let props: { unit: TeamUnit | null; targetMessageId?: number; paused?: boolean };
function Probe() { channel = useTeamChatChannel(props); return null; }
function SearchProbe() {
  taskSearch = useTeamChatTaskSearch('clinic', 'work', true);
  memberSearch = useTeamChatMemberSearch('clinic', 'member', true);
  send = useSendTeamChatMessage('clinic');
  return null;
}
async function mount(element = <Probe />) {
  await act(async () => { renderer = create(<QueryClientProvider client={client}>{element}</QueryClientProvider>); });
}
async function rerender() {
  await act(async () => { renderer!.update(<QueryClientProvider client={client}><Probe /></QueryClientProvider>); });
}
async function tick(ms: number) {
  await act(async () => { jest.advanceTimersByTime(ms); });
}

beforeEach(() => {
  jest.useFakeTimers(); jest.setSystemTime(new Date('2026-11-07T01:00:00Z')); jest.clearAllMocks();
  mockFocused = true; mockForegroundOnline = true; mockOverlay = false; mockIdentity = { id: 12, tenant_id: 9 };
  onlineManager.setOnline(true); focusManager.setFocused(true);
  props = { unit: 'clinic' };
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } } });
  jest.mocked(api.history).mockReset().mockResolvedValue(page([10, 11], { has_more: true }));
  jest.mocked(api.markRead).mockReset().mockImplementation(async (_unit, id) => ({ data: { last_read_id: id, unread_count: 0, latest_id: id } }));
  jest.mocked(api.tasks).mockReset().mockResolvedValue({ data: [], meta: { unit: 'clinic', total: 0, limit: 40 } });
  jest.mocked(api.members).mockReset().mockResolvedValue({ data: [], meta: { unit: 'clinic', total: 0, limit: 20, q: '', page: 1, pages: 1 } });
  jest.mocked(api.send).mockReset().mockResolvedValue({ data: message(12) });
});
afterEach(async () => {
  await act(async () => { renderer?.unmount(); }); renderer = undefined;
  client.clear(); onlineManager.setOnline(true); focusManager.setFocused(undefined); jest.useRealTimers();
});

describe('mounted Team Chat channel coordination', () => {
  it('loads once, polls every five seconds only when active, and keeps background/manual feedback distinct', async () => {
    await mount();
    expect(api.history).toHaveBeenCalledTimes(1);
    expect(api.history).toHaveBeenLastCalledWith('clinic', {});
    await tick(4_999); expect(api.history).toHaveBeenCalledTimes(1);
    const waiting = deferred<ReturnType<typeof page>>();
    jest.mocked(api.history).mockReturnValueOnce(waiting.promise);
    await tick(1);
    expect(api.history).toHaveBeenLastCalledWith('clinic', { after: 11 });
    expect(channel.polling).toBe(true); expect(channel.refreshing).toBe(false);
    await tick(15_000); expect(api.history).toHaveBeenCalledTimes(2);
    await act(async () => waiting.resolve(page([])));
    expect(channel.polling).toBe(false);
    mockFocused = false; await rerender();
    await tick(15_000); expect(api.history).toHaveBeenCalledTimes(2);
    mockFocused = true; await rerender(); expect(api.history).toHaveBeenCalledTimes(3);
    mockForegroundOnline = false; await rerender(); await tick(15_000); expect(api.history).toHaveBeenCalledTimes(3);
    mockForegroundOnline = true; await rerender(); expect(api.history).toHaveBeenCalledTimes(4);
    mockOverlay = true; await rerender(); await tick(15_000); expect(api.history).toHaveBeenCalledTimes(4);
    mockOverlay = false; await rerender(); expect(api.history).toHaveBeenCalledTimes(5);
    props.paused = true; await rerender(); await tick(15_000); expect(api.history).toHaveBeenCalledTimes(5);
  });

  it('keeps hints active while healthy and resumes only one fallback read after reconnect', async () => {
    const transport = ownUserRealtime(client, { tenantId: 9, userId: 12 });
    transport.setReady(true);
    try {
      await mount();
      expect(api.history).toHaveBeenCalledTimes(1);
      await tick(15_000);
      expect(api.history).toHaveBeenCalledTimes(1);
      jest.mocked(api.history).mockResolvedValueOnce(page([12]));
      await act(async () => { await client.invalidateQueries({ queryKey: teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic') }); });
      expect(api.history).toHaveBeenCalledTimes(2);
      expect(api.history).toHaveBeenLastCalledWith('clinic', { after: 11 });
      expect(channel.messages.map(item => item.id)).toEqual([10, 11, 12]);
      const read = deferred<{ data: api.TeamChatReadSummary }>();
      jest.mocked(api.markRead).mockReturnValueOnce(read.promise);
      act(() => { channel.acknowledge(12); channel.acknowledge(12); });
      expect(api.markRead).toHaveBeenCalledTimes(1);
      await act(async () => transport.setReady(false));
      await tick(5_000);
      expect(api.history).toHaveBeenCalledTimes(2); // The read still owns the physical HTTP lock.
      await act(async () => transport.setReady(true));
      await act(async () => read.resolve({ data: { last_read_id: 12, unread_count: 0, latest_id: 12 } }));
      expect(channel.summary.last_read_id).toBe(12);
      act(() => channel.acknowledge(12));
      expect(api.markRead).toHaveBeenCalledTimes(1);
      expect(api.history).toHaveBeenCalledTimes(2);
      await act(async () => transport.setReady(false));
      await tick(4_999);
      expect(api.history).toHaveBeenCalledTimes(2);
      jest.mocked(api.history).mockResolvedValueOnce(page([13]));
      await tick(1);
      expect(api.history).toHaveBeenCalledTimes(3);
      expect(api.history).toHaveBeenLastCalledWith('clinic', { after: 12 });
      expect(channel.messages.map(item => item.id)).toEqual([10, 11, 12, 13]);
      expect(api.markRead).toHaveBeenCalledTimes(1);
    } finally {
      await act(async () => transport.release());
    }
  });

  it('re-arms healthy hint signaling after a failed read without marking the retained snapshot fresh', async () => {
    const identity = { tenantId: 9, userId: 12 };
    const transport = ownUserRealtime(client, identity);
    transport.setReady(true);
    try {
      await mount();
      const messages = channel.messages;
      const updatedAt = channel.updatedAt;
      const signalKey = teamChatKeys.history(identity, 'clinic', {});
      const dataUpdatedAt = client.getQueryState(signalKey)!.dataUpdatedAt;
      await tick(1_000);
      jest.mocked(api.history).mockRejectedValueOnce(new NetworkError());
      await act(async () => { await client.invalidateQueries({ queryKey: teamChatKeys.unit(identity, 'clinic') }); });
      expect(api.history).toHaveBeenCalledTimes(2);
      expect(channel.messages).toBe(messages);
      expect(channel.updatedAt).toBe(updatedAt);
      expect(channel.updateError).toBeInstanceOf(NetworkError);
      expect(client.getQueryState(signalKey)!.isInvalidated).toBe(false);
      expect(client.getQueryState(signalKey)!.dataUpdatedAt).toBe(dataUpdatedAt);
      await tick(15_000);
      expect(api.history).toHaveBeenCalledTimes(2);
      jest.mocked(api.history).mockResolvedValueOnce(page([12]));
      await act(async () => { await client.invalidateQueries({ queryKey: teamChatKeys.unit(identity, 'clinic') }); });
      expect(api.history).toHaveBeenCalledTimes(3);
      expect(channel.messages.map(item => item.id)).toEqual([10, 11, 12]);
      expect(channel.updatedAt).toBeGreaterThan(updatedAt);
      expect(channel.updateError).toBeNull();
      await tick(15_000);
      expect(api.history).toHaveBeenCalledTimes(3);
      expect(api.markRead).not.toHaveBeenCalled();
    } finally { await act(async () => transport.release()); }
  });

  it('coalesces repeated healthy hints behind the physical read lock into one trailing history read', async () => {
    const identity = { tenantId: 9, userId: 12 };
    const transport = ownUserRealtime(client, identity);
    transport.setReady(true);
    try {
      await mount();
      const read = deferred<{ data: api.TeamChatReadSummary }>();
      jest.mocked(api.markRead).mockReturnValueOnce(read.promise);
      act(() => channel.acknowledge(11));
      for (let i = 0; i < 3; i++) {
        await act(async () => { await client.invalidateQueries({ queryKey: teamChatKeys.unit(identity, 'clinic') }); });
      }
      expect(api.history).toHaveBeenCalledTimes(1);
      expect(api.markRead).toHaveBeenCalledTimes(1);
      jest.mocked(api.history).mockResolvedValueOnce(page([12]));
      await act(async () => read.resolve({ data: { last_read_id: 11, unread_count: 0, latest_id: 11 } }));
      expect(api.history).toHaveBeenCalledTimes(2);
      expect(channel.messages.map(item => item.id)).toEqual([10, 11, 12]);
      await tick(15_000);
      expect(api.history).toHaveBeenCalledTimes(2);
      expect(api.markRead).toHaveBeenCalledTimes(1);
    } finally { await act(async () => transport.release()); }
  });

  it('preserves a historical window across readiness loss, replacement, hints, and a unit switch', async () => {
    props.targetMessageId = 11;
    const oldTransport = ownUserRealtime(client, { tenantId: 9, userId: 12 });
    oldTransport.setReady(true);
    let replacement: ReturnType<typeof ownUserRealtime> | undefined;
    try {
      await mount();
      jest.mocked(api.history).mockResolvedValueOnce(page([8, 9], { has_more: true }));
      await act(async () => { await channel.loadOlder(); });
      const history = channel.messages;
      expect(history.map(item => item.id)).toEqual([8, 9, 10, 11]);
      await act(async () => { replacement = ownUserRealtime(client, { tenantId: 9, userId: 12 }); });
      await tick(15_000);
      await act(async () => replacement!.setReady(true));
      await act(async () => { oldTransport.setReady(false); oldTransport.release(); });
      await act(async () => { await client.invalidateQueries({ queryKey: teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic') }); });
      expect(api.history).toHaveBeenCalledTimes(2);
      expect(channel.messages).toBe(history);
      expect(channel.mode).toBe('history'); expect(channel.targetMessageId).toBe(11);
      expect(channel.hasOlder).toBe(true); expect(api.markRead).not.toHaveBeenCalled();
      props = { unit: 'guidance' };
      jest.mocked(api.history).mockResolvedValueOnce(page([1]));
      await rerender();
      expect(api.history).toHaveBeenCalledTimes(3);
      expect(api.history).toHaveBeenLastCalledWith('guidance', {});
      expect(channel.messages.map(item => item.id)).toEqual([1]);
      await tick(15_000);
      expect(api.history).toHaveBeenCalledTimes(3);
      jest.mocked(api.history).mockResolvedValueOnce(page([2]));
      await act(async () => replacement!.release());
      await tick(5_000);
      expect(api.history).toHaveBeenCalledTimes(4);
      expect(api.history).toHaveBeenLastCalledWith('guidance', { after: 1 });
    } finally {
      await act(async () => { replacement?.release(); oldTransport.release(); });
    }
  });

  it('queues manual history behind an in-flight poll, deduplicates same-tick taps, and ignores stale poll content', async () => {
    await mount();
    const poll = deferred<ReturnType<typeof page>>(); const older = deferred<ReturnType<typeof page>>();
    jest.mocked(api.history).mockReturnValueOnce(poll.promise).mockReturnValueOnce(older.promise);
    await tick(5_000);
    let first!: Promise<boolean>; let second!: Promise<boolean>;
    act(() => { first = channel.loadOlder(); second = channel.loadOlder(); });
    expect(first).toBe(second); expect(api.history).toHaveBeenCalledTimes(2);
    await act(async () => poll.resolve(page([99])));
    expect(channel.messages.map(item => item.id)).toEqual([10, 11]);
    expect(api.history).toHaveBeenCalledTimes(3);
    expect(api.history).toHaveBeenLastCalledWith('clinic', { before: 10 });
    await act(async () => older.resolve(page([8, 9], { has_more: true })));
    expect(await first).toBe(true); expect(channel.mode).toBe('history');
    expect(channel.messages.map(item => item.id)).toEqual([8, 9, 10, 11]);
    await tick(15_000); expect(api.history).toHaveBeenCalledTimes(3);
  });

  it('keeps historical windows on realtime invalidation and after Show Latest with an old route target', async () => {
    props.targetMessageId = 11;
    await mount();
    jest.mocked(api.history).mockResolvedValueOnce(page([8, 9], { has_more: true }));
    await act(async () => { await channel.loadOlder(); });
    const history = channel.messages;
    await act(async () => { await client.invalidateQueries({ queryKey: teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic') }); });
    expect(api.history).toHaveBeenCalledTimes(2); expect(channel.messages).toBe(history);
    jest.mocked(api.history).mockResolvedValueOnce(page([20, 21]));
    await act(async () => { await channel.showLatest(); });
    expect(channel.mode).toBe('latest'); expect(channel.targetMessageId).toBeNull();
    jest.mocked(api.history).mockResolvedValueOnce(page([22]));
    await act(async () => { await client.invalidateQueries({ queryKey: teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic') }); });
    expect(api.history).toHaveBeenLastCalledWith('clinic', { after: 21 });
    expect(channel.messages.map(item => item.id)).toEqual([20, 21, 22]);
    await rerender(); expect(channel.mode).toBe('latest'); expect(channel.targetMessageId).toBeNull();
  });

  it('does not refresh channel history for task-picker invalidation', async () => {
    await mount();
    await act(async () => { await client.invalidateQueries({ queryKey: teamChatKeys.taskPicker({ tenantId: 9, userId: 12 }, 'clinic') }); });
    expect(api.history).toHaveBeenCalledTimes(1);
  });

  it('opens actual source history and preserves the previous target/window when source lookup fails', async () => {
    await mount();
    jest.mocked(api.history).mockResolvedValueOnce(page([4, 5]));
    await act(async () => { expect(await channel.openMessage(5)).toBe(true); });
    expect(api.history).toHaveBeenLastCalledWith('clinic', { around: 5 });
    expect(channel.mode).toBe('history'); expect(channel.targetMessageId).toBe(5);
    const saved = channel.messages;
    jest.mocked(api.history).mockRejectedValueOnce(new Error('Unavailable'));
    await act(async () => { expect(await channel.openMessage(3)).toBe(false); });
    expect(channel.messages).toBe(saved); expect(channel.targetMessageId).toBe(5); expect(channel.updateError).toBeTruthy();
    jest.mocked(api.history).mockResolvedValueOnce(page([1, 2]));
    await act(async () => { expect(await channel.openMessage(7)).toBe(false); });
    expect(channel.messages).toBe(saved); expect(channel.targetMessageId).toBe(5);
  });

  it('preserves loaded history on manual refresh instead of truncating its oldest side', async () => {
    await mount();
    jest.mocked(api.history).mockResolvedValueOnce(page([8, 9], { has_more: true }));
    await act(async () => { await channel.loadOlder(); });
    jest.mocked(api.history).mockResolvedValueOnce(page([10, 11]));
    await act(async () => { await channel.refresh(); });
    expect(api.history).toHaveBeenLastCalledWith('clinic', { around: 11 });
    expect(channel.messages.map(item => item.id)).toEqual([8, 9, 10, 11]); expect(channel.hasOlder).toBe(true);
  });

  it('ignores a late read response across a unit switch and never overlaps the new scope fetch', async () => {
    await mount();
    const read = deferred<{ data: api.TeamChatReadSummary }>();
    jest.mocked(api.markRead).mockReturnValueOnce(read.promise);
    act(() => { channel.acknowledge(11); channel.acknowledge(11); });
    expect(api.markRead).toHaveBeenCalledTimes(1);
    props.unit = 'guidance'; await rerender();
    expect(channel.messages).toEqual([]); expect(api.history).toHaveBeenCalledTimes(1);
    jest.mocked(api.history).mockResolvedValueOnce(page([1], { latest_id: 1 }));
    await act(async () => read.resolve({ data: { last_read_id: 999, unread_count: 0, latest_id: 999 } }));
    expect(api.history).toHaveBeenLastCalledWith('guidance', {});
    expect(channel.messages.map(item => item.id)).toEqual([1]);
    expect(channel.summary.last_read_id).toBe(0); expect(channel.summary.latest_id).toBe(1);
    expect(client.getQueryCache().findAll({ queryKey: teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic') })).toHaveLength(0);
  });

  it('drops queued reads in the background/history and accepts only loaded, actual latest ids', async () => {
    await mount();
    act(() => { channel.acknowledge(999); channel.acknowledge(-1); }); expect(api.markRead).not.toHaveBeenCalled();
    const read = deferred<{ data: api.TeamChatReadSummary }>();
    jest.mocked(api.markRead).mockReturnValueOnce(read.promise);
    act(() => { channel.acknowledge(10); channel.acknowledge(11); });
    mockForegroundOnline = false; await rerender();
    await act(async () => read.resolve({ data: { last_read_id: 999, unread_count: 0, latest_id: 999 } }));
    expect(channel.summary.last_read_id).toBe(0); expect(api.markRead).toHaveBeenCalledTimes(1);
    act(() => channel.acknowledge(11)); expect(api.markRead).toHaveBeenCalledTimes(1);
    mockForegroundOnline = true; await rerender();
    jest.mocked(api.history).mockResolvedValueOnce(page([8, 9]));
    await act(async () => { await channel.loadOlder(); });
    act(() => channel.acknowledge(11)); expect(api.markRead).toHaveBeenCalledTimes(1);
  });

  it('invalidates in-flight initial/history/read completions on unmount and session change', async () => {
    const initial = deferred<ReturnType<typeof page>>();
    jest.mocked(api.history).mockReturnValueOnce(initial.promise);
    await mount();
    mockIdentity = { id: 13, tenant_id: 10 }; await rerender();
    jest.mocked(api.history).mockResolvedValueOnce(page([2]));
    await act(async () => initial.resolve(page([999])));
    expect(channel.messages.map(item => item.id)).toEqual([2]);
    const read = deferred<{ data: api.TeamChatReadSummary }>();
    jest.mocked(api.markRead).mockReturnValueOnce(read.promise);
    act(() => channel.acknowledge(2));
    await act(async () => renderer!.unmount()); renderer = undefined;
    await act(async () => read.resolve({ data: { last_read_id: 99, unread_count: 0, latest_id: 99 } }));
    expect(client.getQueryCache().findAll({ queryKey: ['team-chat'] })).toHaveLength(0);
  });

  it('separates initial failure/empty success from background failure and manual retry', async () => {
    jest.mocked(api.history).mockRejectedValueOnce(new NetworkError());
    await mount(); expect(channel.error).toBeInstanceOf(NetworkError); expect(channel.loaded).toBe(false);
    await tick(15_000); expect(api.history).toHaveBeenCalledTimes(1);
    const retry = deferred<ReturnType<typeof page>>(); jest.mocked(api.history).mockReturnValueOnce(retry.promise);
    act(() => { channel.refresh(); });
    expect(channel.refreshing).toBe(true); expect(channel.loadingInitial).toBe(true); expect(channel.error).toBeNull();
    await act(async () => retry.resolve(page([])));
    expect(channel.loaded).toBe(true); expect(channel.messages).toEqual([]); expect(channel.error).toBeNull();
    jest.mocked(api.history).mockRejectedValueOnce(new NetworkError());
    await tick(5_000);
    expect(channel.loaded).toBe(true); expect(channel.error).toBeNull(); expect(channel.updateError).toBeInstanceOf(NetworkError);
  });

  it('keeps unchanged messages/arrays stable and clears protected content on a current denial only', async () => {
    await mount(); const original = channel.messages;
    await tick(5_000); expect(channel.messages).toBe(original);
    jest.mocked(api.history).mockRejectedValueOnce(new ApiError(403, { code: 'forbidden', message: 'Removed' }));
    await tick(5_000); expect(channel.messages).toEqual([]); expect(channel.summary.last_read_id).toBe(0);
    await tick(10_000); expect(api.history).toHaveBeenCalledTimes(3);
  });
});

describe('mounted Team Chat searches and sends', () => {
  it('allows search inside the task overlay but pauses member search and both while backgrounded', async () => {
    mockOverlay = true;
    await mount(<SearchProbe />);
    await tick(0);
    expect(api.tasks).toHaveBeenCalledTimes(1); expect(api.members).not.toHaveBeenCalled();
    expect(taskSearch.data?.data).toEqual([]);
    mockForegroundOnline = false;
    await act(async () => renderer!.update(<QueryClientProvider client={client}><SearchProbe /></QueryClientProvider>));
    await act(async () => { await client.invalidateQueries({ queryKey: ['team-chat'] }); });
    expect(api.tasks).toHaveBeenCalledTimes(1); expect(memberSearch.data).toBeUndefined();
  });

  it('rejects an offline mutation without a paused send or automatic reconnect replay', async () => {
    await mount(<SearchProbe />);
    onlineManager.setOnline(false);
    const input: api.TeamChatSendInput = { body: 'hi', client_nonce: 'nonce', task_ids: [], mentions: [], reply_to_id: null };
    await act(async () => { await expect(send.mutateAsync(input)).rejects.toBeInstanceOf(NetworkError); });
    expect(api.send).not.toHaveBeenCalled(); expect(send.isPaused).toBe(false);
    onlineManager.setOnline(true); await tick(15_000); expect(api.send).not.toHaveBeenCalled();
  });
});
