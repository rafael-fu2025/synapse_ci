import React from 'react';
import { AppState } from 'react-native';
import { useIsFocused } from '@react-navigation/native';
import { QueryClient, QueryClientProvider, focusManager, onlineManager, useQuery } from '@tanstack/react-query';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import * as notifications from '../src/api/endpoints/notifications';
import * as staffQueue from '../src/api/endpoints/staffQueue';
import * as teamChat from '../src/api/endpoints/teamChat';
import { NetworkError } from '../src/api/client';
import { useTeamChatChannel } from '../src/features/team-chat/hooks';
import { userRealtimeGrant } from '../src/api/endpoints/realtime';
import { queryKeys } from '../src/api/queryKeys';
import { startUserRealtime } from '../src/features/realtime/userRealtime';
import { useNotificationHistory, useNotificationInbox } from '../src/features/notifications/hooks';
import { useStaffQueueSummary } from '../src/features/staff-queue/hooks';
import { ownUserRealtime } from '../src/lib/realtime/transportState';
import { useRealtimePoll } from '../src/lib/useRealtimePoll';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/api/endpoints/realtime', () => ({ userRealtimeGrant: jest.fn() }));
jest.mock('../src/api/endpoints/notifications', () => ({ inbox: jest.fn(), history: jest.fn(), markRead: jest.fn(), markAllRead: jest.fn() }));
jest.mock('../src/api/endpoints/staffQueue', () => ({ summary: jest.fn() }));
jest.mock('../src/api/endpoints/teamChat', () => ({ history: jest.fn(), markRead: jest.fn(), tasks: jest.fn(), members: jest.fn(), send: jest.fn() }));
jest.mock('@react-navigation/native', () => ({ useIsFocused: () => mockFocused }));
jest.mock('../src/store/auth', () => ({ useAuth: () => ({ user: mockUser }) }));

const IDENTITY = { tenantId: 7, userId: 42 };
const originalWebSocket = globalThis.WebSocket;
const originalAppState = AppState.currentState;
let mockFocused: boolean;
let mockUser: { id: number; tenant_id: number } | null;
let client: QueryClient;
let renderer: ReactTestRenderer | undefined;
let stop: (() => void) | undefined;
let read: jest.Mock;
let inboxResult: ReturnType<typeof useNotificationInbox>;
let historyResult: ReturnType<typeof useNotificationHistory>;
let chatResult: ReturnType<typeof useTeamChatChannel>;

class MockSocket {
  static instances: MockSocket[] = [];
  onopen: (() => void) | null = null;
  onmessage: ((event: { data: string }) => void) | null = null;
  onclose: (() => void) | null = null;
  onerror: (() => void) | null = null;
  send = jest.fn();
  close = jest.fn();
  constructor() { MockSocket.instances.push(this); }
  open() { this.onopen?.(); }
  message(value: unknown) { this.onmessage?.({ data: JSON.stringify(value) }); }
}

function grant() {
  return { enabled: true as const, url: 'wss://socket.test/realtime', origin: 'https://api.test', channel: 'private.tenant.7.user.42', ticket: 'test-ticket', expires_at: Math.floor(Date.now() / 1000) + 300 };
}
function PollProbe({ fetch = read }: { fetch?: jest.Mock }) {
  const interval = useRealtimePoll(1_000);
  const focused = useIsFocused();
  useQuery({ queryKey: queryKeys.notifications, queryFn: fetch, enabled: focused, refetchInterval: interval });
  return null;
}
function NotificationsProbe() {
  inboxResult = useNotificationInbox();
  historyResult = useNotificationHistory(1, 'all');
  return null;
}
function StaffProbe() { useStaffQueueSummary(); return null; }
function ChatProbe() { chatResult = useTeamChatChannel({ unit: 'clinic' }); return null; }
function chatPage(ids: number[]) {
  return { data: { messages: ids.map(id => ({ id, body: `Message ${id}`, sender: { id: 42, name: 'Member' }, created_at: '2026-11-07T08:00:00Z', client_nonce: null, tasks: [], mentions: [], reply_to: null })), has_more: false, next_before: null, last_read_id: 0, unread_count: ids.length, latest_id: ids.at(-1) ?? 0 } };
}
function tree(element: React.ReactNode) { return <QueryClientProvider client={client}>{element}</QueryClientProvider>; }
async function mount(element: React.ReactNode) { await act(async () => { renderer = create(tree(element)); }); await flush(); }
async function flush(ms = 0) {
  await act(async () => { await jest.advanceTimersByTimeAsync(ms); });
  await act(async () => { await jest.advanceTimersByTimeAsync(0); });
}

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  jest.setSystemTime(new Date('2026-11-07T08:00:00Z'));
  mockFocused = true; mockUser = { id: 42, tenant_id: 7 };
  AppState.currentState = 'active';
  onlineManager.setOnline(true); focusManager.setFocused(true);
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
  read = jest.fn().mockResolvedValue(['fresh']);
  MockSocket.instances = [];
  globalThis.WebSocket = MockSocket as unknown as typeof WebSocket;
  jest.spyOn(Math, 'random').mockReturnValue(1);
  jest.mocked(userRealtimeGrant).mockReset().mockImplementation(async () => ({ data: grant() }));
  jest.mocked(notifications.inbox).mockReset().mockResolvedValue({ data: { items: [], unread_count: 0 } });
  jest.mocked(notifications.history).mockReset().mockResolvedValue({ data: { items: [], unread_count: 0, total: 0, page: 1, pages: 1, filter: 'all', per_page: 20 } });
  jest.mocked(staffQueue.summary).mockReset().mockResolvedValue({ data: [], meta: { date: '2026-11-07', generated_at: '2026-11-07T08:00:00Z' } });
  jest.mocked(teamChat.history).mockReset().mockResolvedValue(chatPage([10, 11]));
  jest.mocked(teamChat.markRead).mockReset();
});
afterEach(() => {
  act(() => renderer?.unmount()); renderer = undefined;
  stop?.(); stop = undefined;
  client.clear();
  globalThis.WebSocket = originalWebSocket;
  AppState.currentState = originalAppState;
  onlineManager.setOnline(true); focusManager.setFocused(undefined);
  jest.restoreAllMocks(); jest.useRealTimers();
});

describe('mounted realtime-first polling', () => {
  it('keeps actual fallback HTTP reads before grant/ready and after handshake failure, then accepts healthy hints', async () => {
    await mount(<PollProbe />);
    expect(read).toHaveBeenCalledTimes(1);
    await flush(1_000);
    expect(read).toHaveBeenCalledTimes(2); // No realtime owner is the normal polling default.
    let resolveGrant!: (value: { data: ReturnType<typeof grant> }) => void;
    jest.mocked(userRealtimeGrant).mockImplementationOnce(() => new Promise(resolve => { resolveGrant = resolve; }));
    await act(async () => { stop = startUserRealtime(client, IDENTITY); }); await flush();
    expect(read).toHaveBeenCalledTimes(3); // Lifetime reconciliation is independent of the fallback.
    await flush(1_000);
    expect(read).toHaveBeenCalledTimes(4);
    expect(MockSocket.instances).toHaveLength(0);
    await act(async () => resolveGrant({ data: grant() })); await flush();
    const ws = MockSocket.instances[0];
    await flush(1_000);
    expect(read).toHaveBeenCalledTimes(5); // A constructed socket is not an authenticated transport.
    act(() => ws.open());
    expect(ws.send).toHaveBeenCalledWith(JSON.stringify({ type: 'authenticate', ticket: 'test-ticket' }));
    await flush(9_000);
    expect(read).toHaveBeenCalledTimes(14);
    expect(ws.close).not.toHaveBeenCalled();
    await flush(1_000);
    expect(ws.close).toHaveBeenCalledTimes(1);
    expect(read).toHaveBeenCalledTimes(15); // Failed handshake did not disable the interval.
    await flush(1_000);
    expect(read).toHaveBeenCalledTimes(16);
    const replacement = MockSocket.instances[1];
    act(() => replacement.open());
    const beforeReady = read.mock.calls.length;
    await act(async () => replacement.message({ type: 'ready' })); await flush();
    expect(read).toHaveBeenCalledTimes(beforeReady + 1);
    await flush(5_000);
    expect(read).toHaveBeenCalledTimes(beforeReady + 1);
    act(() => replacement.message({ type: 'event', channel: 'private.tenant.7.user.42', event: 'notifications.changed', data: [] }));
    await flush(100);
    expect(read).toHaveBeenCalledTimes(beforeReady + 2);
    act(() => replacement.onclose!()); await flush();
    await flush(1_000);
    expect(read).toHaveBeenCalledTimes(beforeReady + 3);
  });

  it('keeps real notification observers enabled while healthy so inbox/history hints still refetch', async () => {
    const transport = ownUserRealtime(client, IDENTITY);
    transport.setReady(true);
    try {
      await mount(<NotificationsProbe />);
      expect(notifications.inbox).toHaveBeenCalledTimes(1);
      expect(notifications.history).toHaveBeenCalledTimes(1);
      expect(inboxResult.isSuccess).toBe(true); expect(historyResult.isSuccess).toBe(true);
      await flush(60_000);
      expect(notifications.inbox).toHaveBeenCalledTimes(1); expect(notifications.history).toHaveBeenCalledTimes(1);
      await act(async () => { await client.invalidateQueries({ queryKey: queryKeys.notifications }); }); await flush();
      expect(notifications.inbox).toHaveBeenCalledTimes(2); expect(notifications.history).toHaveBeenCalledTimes(2);
      act(() => transport.release()); await flush();
      await flush(30_000);
      expect(notifications.inbox).toHaveBeenCalledTimes(3); expect(notifications.history).toHaveBeenCalledTimes(3);
      mockFocused = false;
      act(() => renderer!.update(tree(<NotificationsProbe />))); await flush(60_000);
      expect(notifications.inbox).toHaveBeenCalledTimes(3); expect(notifications.history).toHaveBeenCalledTimes(3);
      mockFocused = true;
      act(() => renderer!.update(tree(<NotificationsProbe />))); await flush();
      expect(notifications.inbox).toHaveBeenCalledTimes(4); expect(notifications.history).toHaveBeenCalledTimes(4);
    } finally {
      act(() => transport.release());
    }
  });

  it('keeps the real staff summary on hints when healthy and restores its 15-second fallback', async () => {
    const transport = ownUserRealtime(client, IDENTITY);
    transport.setReady(true);
    try {
      await mount(<StaffProbe />);
      expect(staffQueue.summary).toHaveBeenCalledTimes(1);
      await flush(45_000);
      expect(staffQueue.summary).toHaveBeenCalledTimes(1);
      await act(async () => { await client.invalidateQueries({ queryKey: queryKeys.staffQueue('all'), exact: true }); }); await flush();
      expect(staffQueue.summary).toHaveBeenCalledTimes(2);
      act(() => transport.release()); await flush();
      await flush(14_999);
      expect(staffQueue.summary).toHaveBeenCalledTimes(2);
      await flush(1);
      expect(staffQueue.summary).toHaveBeenCalledTimes(3);
      mockFocused = false;
      act(() => renderer!.update(tree(<StaffProbe />))); await flush(30_000);
      expect(staffQueue.summary).toHaveBeenCalledTimes(3);
    } finally {
      act(() => transport.release());
    }
  });

  it('repairs failed Chat hint reads through the real 120-second reconcile without fallback polling', async () => {
    await mount(<ChatProbe />);
    await act(async () => { stop = startUserRealtime(client, IDENTITY); });
    await flush();
    const ws = MockSocket.instances[0];
    act(() => ws.open());
    await act(async () => ws.message({ type: 'ready' }));
    await flush();
    const baseline = jest.mocked(teamChat.history).mock.calls.length;
    const previous = chatResult.messages;
    const updatedAt = chatResult.updatedAt;
    jest.mocked(teamChat.history).mockRejectedValueOnce(new NetworkError());
    act(() => ws.message({ type: 'event', channel: 'private.tenant.7.user.42', event: 'team.chat.changed', data: { unit: 'clinic' } }));
    await flush(100);
    expect(teamChat.history).toHaveBeenCalledTimes(baseline + 1);
    expect(chatResult.messages).toBe(previous);
    expect(chatResult.updatedAt).toBe(updatedAt);
    expect(chatResult.updateError).toBeInstanceOf(NetworkError);
    // Keep the transport healthy: reconnect/fallback must not mask reconciliation.
    await flush(19_900);
    act(() => ws.message({ type: 'pong' }));
    for (let i = 0; i < 4; i++) {
      await flush(20_000);
      act(() => ws.message({ type: 'pong' }));
    }
    expect(teamChat.history).toHaveBeenCalledTimes(baseline + 1);
    jest.mocked(teamChat.history).mockResolvedValueOnce(chatPage([12]));
    await flush(20_000);
    act(() => ws.message({ type: 'pong' }));
    await flush();
    expect(teamChat.history).toHaveBeenCalledTimes(baseline + 2);
    expect(chatResult.messages.map(item => item.id)).toEqual([10, 11, 12]);
    expect(chatResult.updateError).toBeNull();
    expect(ws.close).not.toHaveBeenCalled();
    expect(MockSocket.instances).toHaveLength(1);
    expect(teamChat.markRead).not.toHaveBeenCalled();
  });

  it('isolates mounted client/session readiness and never restores polling on an unfocused route', async () => {
    const otherClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
    const otherRead = jest.fn().mockResolvedValue(['other']);
    const transport = ownUserRealtime(client, IDENTITY);
    transport.setReady(true);
    let otherTransport: ReturnType<typeof ownUserRealtime> | undefined;
    const pair = () => tree(<><PollProbe /><QueryClientProvider client={otherClient}><PollProbe fetch={otherRead} /></QueryClientProvider></>);
    try {
      await act(async () => { renderer = create(pair()); }); await flush();
      expect(read).toHaveBeenCalledTimes(1); expect(otherRead).toHaveBeenCalledTimes(1);
      await flush(1_000);
      expect(read).toHaveBeenCalledTimes(1); expect(otherRead).toHaveBeenCalledTimes(2);
      mockUser = { tenant_id: 7, id: 43 };
      act(() => renderer!.update(pair())); await flush();
      await flush(1_000);
      expect(read).toHaveBeenCalledTimes(2); expect(otherRead).toHaveBeenCalledTimes(3);
      act(() => { otherTransport = ownUserRealtime(otherClient, { tenantId: 7, userId: 43 }); otherTransport.setReady(true); });
      await flush(1_000);
      expect(read).toHaveBeenCalledTimes(3); expect(otherRead).toHaveBeenCalledTimes(3);
      mockFocused = false;
      act(() => renderer!.update(pair()));
      act(() => otherTransport!.release()); await flush(5_000);
      expect(read).toHaveBeenCalledTimes(3); expect(otherRead).toHaveBeenCalledTimes(3);
    } finally {
      act(() => { renderer?.unmount(); transport.release(); otherTransport?.release(); }); renderer = undefined;
      otherClient.clear();
    }
  });
});
