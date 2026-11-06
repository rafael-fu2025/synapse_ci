import React from 'react';
import { QueryClient, QueryClientProvider, QueryObserver, onlineManager } from '@tanstack/react-query';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { userRealtimeGrant } from '../src/api/endpoints/realtime';
import { __resetClientForTests, setAccessToken } from '../src/api/client';
import { RealtimeIdentity, startUserRealtime, validateUserGrant } from '../src/features/realtime/userRealtime';
import { useUserRealtime } from '../src/features/realtime/useUserRealtime';
import { queryKeys } from '../src/api/queryKeys';
import { config } from '../src/lib/config';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test/subpath' } }));
jest.mock('../src/api/endpoints/realtime', () => ({ userRealtimeGrant: jest.fn() }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));

const IDENTITY: RealtimeIdentity = { tenantId: 7, userId: 42 };
const CHANNEL = 'private.tenant.7.user.42';
const originalWebSocket = globalThis.WebSocket;
class MockSocket {
  static instances: MockSocket[] = [];
  onopen: (() => void) | null = null;
  onmessage: ((event: { data: string }) => void) | null = null;
  onclose: (() => void) | null = null;
  onerror: (() => void) | null = null;
  send = jest.fn();
  close = jest.fn();
  constructor(readonly url: string, readonly protocols?: string[], readonly options?: unknown) {
    MockSocket.instances.push(this);
  }
  open() { this.onopen?.(); }
  message(value: unknown) { this.onmessage?.({ data: JSON.stringify(value) }); }
  ready() { this.open(); this.message({ type: 'ready' }); }
}

function grant(overrides: Record<string, unknown> = {}) {
  return {
    enabled: true as const,
    url: 'wss://socket.test/realtime',
    origin: 'https://api.test',
    channel: CHANNEL,
    ticket: 'single-use-secret',
    expires_at: Math.floor(Date.now() / 1000) + 60,
    ...overrides,
  };
}
function hint(ws: MockSocket, event: string, data: unknown = [], channel: string = CHANNEL) {
  ws.message({ type: 'event', channel, event, data });
}
async function flush(ms = 0) { await jest.advanceTimersByTimeAsync(ms); }
function latest() { return MockSocket.instances[MockSocket.instances.length - 1]; }

let client: QueryClient;
let stop: (() => void) | undefined;
let unsubscribe: (() => void) | undefined;
let renderer: ReactTestRenderer | undefined;
let notifications: jest.Mock;
let notificationHistory: jest.Mock;
let queue: jest.Mock;
let appointmentList: jest.Mock;
let appointmentDetail: jest.Mock;
let staffClinic: jest.Mock;
let staffGuidance: jest.Mock;
let staffAll: jest.Mock;

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  jest.setSystemTime(new Date('2026-01-01T00:00:00Z'));
  onlineManager.setOnline(true);
  config.apiBaseUrl = 'https://api.test/subpath';
  MockSocket.instances = [];
  globalThis.WebSocket = MockSocket as unknown as typeof WebSocket;
  jest.mocked(userRealtimeGrant).mockReset().mockImplementation(async () => ({ data: grant() }));
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity, staleTime: Infinity } } });
  const observe = (key: readonly unknown[], queryFn: jest.Mock) => {
    client.setQueryData(key, ['cached']);
    const un = new QueryObserver(client, { queryKey: key, queryFn }).subscribe(() => undefined);
    const previous = unsubscribe;
    unsubscribe = previous ? () => { previous(); un(); } : un;
  };
  notifications = jest.fn().mockResolvedValue(['fresh']);
  notificationHistory = jest.fn().mockResolvedValue(['fresh']);
  queue = jest.fn().mockResolvedValue(['fresh']);
  appointmentList = jest.fn().mockResolvedValue(['fresh']);
  appointmentDetail = jest.fn().mockResolvedValue(['fresh']);
  staffClinic = jest.fn().mockResolvedValue(['fresh']);
  staffGuidance = jest.fn().mockResolvedValue(['fresh']);
  staffAll = jest.fn().mockResolvedValue(['fresh']);
  observe(queryKeys.notifications, notifications);
  observe(queryKeys.notificationHistory(1, 'all'), notificationHistory);
  observe(queryKeys.queue, queue);
  observe(queryKeys.appointments({ service: 'clinic', status: 'all', sort: 'date' }), appointmentList);
  observe(queryKeys.appointment(5), appointmentDetail);
  observe(queryKeys.staffQueue('clinic'), staffClinic);
  observe(queryKeys.staffQueue('guidance'), staffGuidance);
  observe(queryKeys.staffQueue('all'), staffAll);
  jest.spyOn(Math, 'random').mockReturnValue(1);
});
afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  stop?.();
  stop = undefined;
  unsubscribe?.();
  unsubscribe = undefined;
  client.clear();
  __resetClientForTests();
  globalThis.WebSocket = originalWebSocket;
  onlineManager.setOnline(true);
  jest.restoreAllMocks();
  jest.useRealTimers();
});

async function start() {
  stop = startUserRealtime(client, IDENTITY);
  await flush();
  return latest();
}

describe('private user grant validation', () => {
  it('accepts the identity channel on a separate socket host and http origin', () => {
    expect(validateUserGrant(grant(), IDENTITY)).toEqual(grant());
    config.apiBaseUrl = 'http://api.test/path';
    expect(validateUserGrant(grant({ origin: 'http://api.test', url: 'ws://socket.test/path' }), IDENTITY)).not.toBeNull();
  });
  it('rejects channels that do not name exactly this identity', () => {
    for (const channel of [
      'private.tenant.7.user.43', 'private.tenant.8.user.42', 'private.user.42',
      'public.tenant.7.login-carousel', 'private.tenant.07.user.42', CHANNEL + '.extra',
    ]) {
      expect(validateUserGrant(grant({ channel }), IDENTITY)).toBeNull();
    }
    expect(validateUserGrant(grant(), { tenantId: 7, userId: 43 })).toBeNull();
  });
  it.each([
    { enabled: false }, { origin: 'https://other.test' }, { url: 'https://socket.test' },
    { url: 'wss://socket.test/?ticket=secret' }, { ticket: '' },
    { expires_at: Math.floor(Date.now() / 1000) + 301 },
  ])('rejects an unsafe or malformed grant: %j', overrides => {
    expect(validateUserGrant(grant(overrides), IDENTITY)).toBeNull();
  });
});

const realtimeEndpoint = jest.requireActual('../src/api/endpoints/realtime') as typeof import('../src/api/endpoints/realtime');

describe('authenticated grant fetch', () => {
  it('sends the API origin and the bearer token together', async () => {
    __resetClientForTests();
    setAccessToken('access-token-1');
    const fetchMock = jest.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { enabled: false } }), { status: 200 }));
    await expect(realtimeEndpoint.userRealtimeGrant()).resolves.toEqual({ data: { enabled: false } });
    expect(fetchMock).toHaveBeenCalledWith('https://api.test/subpath/api/v1/realtime/grant', expect.objectContaining({
      method: 'GET',
      headers: expect.objectContaining({ Origin: 'https://api.test', Authorization: 'Bearer access-token-1' }),
    }));
  });
  it('does not loop a rejected grant: a 403 surfaces without refresh attempts', async () => {
    __resetClientForTests();
    setAccessToken('access-token-1');
    const fetchMock = jest.spyOn(globalThis, 'fetch')
      .mockResolvedValue(new Response(JSON.stringify({ error: { code: 'forbidden', message: 'Realtime origin not allowed.' } }), { status: 403 }));
    await expect(realtimeEndpoint.userRealtimeGrant()).rejects.toMatchObject({ status: 403 });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
});

describe('socket protocol and hint dispatch', () => {
  it('authenticates in a frame and reconciles the personal targets on ready', async () => {
    const ws = await start();
    expect(ws.options).toEqual({ headers: { Origin: 'https://api.test' } });
    // Start reconciles the personal targets immediately; with staleTime Infinity the
    // observers do not fetch on mount, so staff boards stay at zero until a hint.
    expect(notifications).toHaveBeenCalledTimes(1);
    expect(notificationHistory).toHaveBeenCalledTimes(1);
    expect(queue).toHaveBeenCalledTimes(1);
    expect(appointmentList).toHaveBeenCalledTimes(1);
    expect(appointmentDetail).toHaveBeenCalledTimes(1);
    expect(staffClinic).toHaveBeenCalledTimes(0);
    expect(staffGuidance).toHaveBeenCalledTimes(0);
    expect(staffAll).toHaveBeenCalledTimes(0);
    ws.ready();
    await flush();
    expect(ws.send.mock.calls).toEqual([[JSON.stringify({ type: 'authenticate', ticket: 'single-use-secret' })]]);
    // Ready refreshes notifications, queue, and both appointment keys; staff boards wait for hints.
    expect(notifications).toHaveBeenCalledTimes(2);
    expect(notificationHistory).toHaveBeenCalledTimes(2);
    expect(queue).toHaveBeenCalledTimes(2);
    expect(appointmentList).toHaveBeenCalledTimes(2);
    expect(appointmentDetail).toHaveBeenCalledTimes(2);
    expect(staffClinic).toHaveBeenCalledTimes(0);
    expect(staffGuidance).toHaveBeenCalledTimes(0);
    expect(staffAll).toHaveBeenCalledTimes(0);
  });

  it('maps each personal hint to its own queries and ignores everything else', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    hint(ws, 'notifications.changed');
    await flush(100);
    expect(notifications).toHaveBeenCalledTimes(3);
    // The bell and every paged history query share the 'notifications' prefix.
    expect(notificationHistory).toHaveBeenCalledTimes(3);
    expect(queue).toHaveBeenCalledTimes(2);
    expect(appointmentList).toHaveBeenCalledTimes(2);
    expect(staffClinic).toHaveBeenCalledTimes(0);

    hint(ws, 'portal.queue.changed');
    await flush(100);
    expect(queue).toHaveBeenCalledTimes(3);
    expect(notifications).toHaveBeenCalledTimes(3);

    hint(ws, 'portal.appointments.changed');
    await flush(100);
    expect(appointmentList).toHaveBeenCalledTimes(3);
    expect(appointmentDetail).toHaveBeenCalledTimes(3);
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(queue).toHaveBeenCalledTimes(3);

    hint(ws, 'queue.invalidated', { unit: 'clinic', date: '2026-01-01' });
    await flush(100);
    expect(staffClinic).toHaveBeenCalledTimes(1);
    expect(staffGuidance).toHaveBeenCalledTimes(0);
    expect(staffAll).toHaveBeenCalledTimes(1);
    hint(ws, 'queue.invalidated', { unit: 'guidance', date: '2026-01-01' });
    await flush(100);
    expect(staffGuidance).toHaveBeenCalledTimes(1);
    // Units without a mobile surface or wrong shapes never refresh anything.
    hint(ws, 'queue.invalidated', { unit: 'pharmacy', date: '2026-01-01' });
    hint(ws, 'queue.invalidated', { unit: 7 });
    hint(ws, 'team.chat.changed', { unit: 'clinic' });
    hint(ws, 'ai.insights.changed', { unit: 'clinic' });
    hint(ws, 'login.carousel.changed');
    hint(ws, 'notifications.changed', {});
    hint(ws, 'notifications.changed', [1]);
    hint(ws, 'notifications.changed', [], 'private.tenant.7.user.43');
    ws.onmessage?.({ data: 'not JSON' });
    await flush(200);
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(notificationHistory).toHaveBeenCalledTimes(3);
    expect(queue).toHaveBeenCalledTimes(3);
    expect(appointmentList).toHaveBeenCalledTimes(3);
    expect(appointmentDetail).toHaveBeenCalledTimes(3);
    expect(staffClinic).toHaveBeenCalledTimes(1);
    expect(staffGuidance).toHaveBeenCalledTimes(1);
    expect(staffAll).toHaveBeenCalledTimes(2);
    expect(client.getQueryCache().getAll()).toHaveLength(8);
  });

  it.each(['clinic', 'guidance'] as const)(
    'refreshes the active %s and all boards through HTTP, but no other queries', async unit => {
      const extendedKey = [...queryKeys.staffQueue('all'), 'unrelated'];
      const extendedFetch = jest.fn().mockResolvedValue(['unrelated']);
      client.setQueryData(extendedKey, ['cached']);
      const un = new QueryObserver(client, { queryKey: extendedKey, queryFn: extendedFetch })
        .subscribe(() => undefined);
      try {
        const ws = await start();
        ws.ready();
        await flush();
        expect(staffAll).not.toHaveBeenCalled();
        // Date is not required by the existing hint contract; only unit selects targets.
        hint(ws, 'queue.invalidated', { unit });
        await flush(100);
        expect(staffAll).toHaveBeenCalledTimes(1);
        expect(staffClinic).toHaveBeenCalledTimes(unit === 'clinic' ? 1 : 0);
        expect(staffGuidance).toHaveBeenCalledTimes(unit === 'guidance' ? 1 : 0);
        expect(client.getQueryData(queryKeys.staffQueue('all'))).toEqual(['fresh']);
        expect(client.getQueryData(queryKeys.staffQueue(unit))).toEqual(['fresh']);
        expect(extendedFetch).not.toHaveBeenCalled();
        expect(client.getQueryState(extendedKey)?.isInvalidated).toBe(false);
        for (const fetchQuery of [notifications, notificationHistory, queue, appointmentList, appointmentDetail]) {
          expect(fetchQuery).toHaveBeenCalledTimes(2);
        }
      } finally {
        un();
      }
    },
  );

  it.each([
    null, [], {}, 'clinic', 7, { unit: null }, { unit: 7 },
    { unit: 'all' }, { unit: 'pharmacy' }, { unit: 'Clinic' }, { unit: 'clinic ' },
  ])('ignores malformed or unsupported staff hint %j without fetching', async data => {
    const ws = await start();
    ws.ready();
    await flush();
    hint(ws, 'queue.invalidated', data);
    hint(ws, 'queue.invalidated', { unit: 'clinic' }, 'private.tenant.7.user.43');
    await flush(100);
    for (const fetchQuery of [staffAll, staffClinic, staffGuidance]) {
      expect(fetchQuery).not.toHaveBeenCalled();
    }
    for (const unit of ['all', 'clinic', 'guidance']) {
      expect(client.getQueryState(queryKeys.staffQueue(unit))?.isInvalidated).toBe(false);
    }
    for (const fetchQuery of [notifications, notificationHistory, queue, appointmentList, appointmentDetail]) {
      expect(fetchQuery).toHaveBeenCalledTimes(2);
    }
  });

  it('coalesces a multi-hint burst into one refresh per query', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    hint(ws, 'notifications.changed');
    hint(ws, 'portal.queue.changed');
    hint(ws, 'portal.appointments.changed');
    hint(ws, 'notifications.changed');
    await flush(100);
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(notificationHistory).toHaveBeenCalledTimes(3);
    expect(queue).toHaveBeenCalledTimes(3);
    expect(appointmentList).toHaveBeenCalledTimes(3);
    expect(appointmentDetail).toHaveBeenCalledTimes(3);
  });
});

describe('reconciliation and cleanup', () => {
  it('rechecks a disabled grant every 120 seconds and connects when reconfigured', async () => {
    jest.mocked(userRealtimeGrant).mockImplementation(async () => ({ data: { enabled: false } }));
    stop = startUserRealtime(client, IDENTITY);
    await flush();
    expect(MockSocket.instances).toHaveLength(0);
    expect(notifications).toHaveBeenCalledTimes(1);
    expect(notificationHistory).toHaveBeenCalledTimes(1);
    await flush(120_000);
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(2);
    expect(notifications).toHaveBeenCalledTimes(2);
    expect(notificationHistory).toHaveBeenCalledTimes(2);
    jest.mocked(userRealtimeGrant).mockImplementation(async () => ({ data: grant() }));
    await flush(120_000);
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(3);
    latest().ready();
    await flush();
    expect(notifications).toHaveBeenCalledTimes(4);
    expect(notificationHistory).toHaveBeenCalledTimes(4);
  });

  it('also reconciles a healthy connection every 120 seconds without touching staff boards', async () => {
    jest.mocked(userRealtimeGrant).mockImplementation(
      async () => ({ data: grant({ expires_at: Math.floor(Date.now() / 1000) + 300 }) }));
    const ws = await start();
    ws.ready();
    await flush();
    expect(notifications).toHaveBeenCalledTimes(2);
    for (let i = 0; i < 5; i++) { await flush(20_000); ws.message({ type: 'pong' }); }
    await flush(19_999);
    expect(notifications).toHaveBeenCalledTimes(2);
    await flush(1);
    ws.message({ type: 'pong' });
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(queue).toHaveBeenCalledTimes(3);
    expect(appointmentList).toHaveBeenCalledTimes(3);
    expect(appointmentDetail).toHaveBeenCalledTimes(3);
    expect(staffClinic).toHaveBeenCalledTimes(0);
    expect(staffGuidance).toHaveBeenCalledTimes(0);
    expect(staffAll).toHaveBeenCalledTimes(0);
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(1);
    expect(ws.close).toHaveBeenCalledTimes(0);
  });

  it('detaches handlers and cancels all timers, ignoring completions after stop', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    stop!(); stop = undefined;
    expect(ws.close).toHaveBeenCalledTimes(1);
    hint(ws, 'notifications.changed');
    await flush(300_000);
    expect(notifications).toHaveBeenCalledTimes(2);
    expect(jest.getTimerCount()).toBe(0);
  });
});

function Harness({ active, identity }: { active: boolean; identity: RealtimeIdentity | null }) {
  useUserRealtime(active, identity);
  return null;
}
function tree(active = true, identity: RealtimeIdentity | null = IDENTITY) {
  return <QueryClientProvider client={client}><Harness active={active} identity={identity} /></QueryClientProvider>;
}

describe('hook gating', () => {
  it('does no socket work without an identity, while inactive, or offline', async () => {
    await act(async () => { renderer = create(tree(true, null)); await flush(120_000); });
    expect(jest.mocked(userRealtimeGrant)).not.toHaveBeenCalled();
    expect(MockSocket.instances).toHaveLength(0);
    await act(async () => { renderer!.update(tree(false)); await flush(120_000); });
    expect(jest.mocked(userRealtimeGrant)).not.toHaveBeenCalled();
    await act(async () => { renderer!.update(tree()); await flush(); });
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(1);
    expect(latest().options).toEqual({ headers: { Origin: 'https://api.test' } });
  });

  it('stops synchronously on offline and resumes a fresh lifetime online', async () => {
    await act(async () => { renderer = create(tree()); await flush(); });
    const ws = latest();
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(1);
    await act(async () => { onlineManager.setOnline(false); await flush(); });
    expect(ws.close).toHaveBeenCalledTimes(1);
    await act(async () => { await flush(300_000); });
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(1);
    await act(async () => { onlineManager.setOnline(true); await flush(); });
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(2);
    expect(notifications).toHaveBeenCalledTimes(2);
    expect(jest.getTimerCount()).toBeGreaterThan(0);
    act(() => renderer!.unmount()); renderer = undefined;
    expect(latest().close).toHaveBeenCalledTimes(1);
    onlineManager.setOnline(false); onlineManager.setOnline(true);
    await flush(300_000);
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(2);
    expect(jest.getTimerCount()).toBe(0);
  });
});



