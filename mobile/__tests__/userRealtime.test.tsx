import React from 'react';
import { QueryClient, QueryClientProvider, QueryObserver, focusManager, onlineManager } from '@tanstack/react-query';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { userRealtimeGrant } from '../src/api/endpoints/realtime';
import { __resetClientForTests, activateApiRuntime, bindApiClient, createApiRuntime, retireApiRuntime, setAccessToken } from '../src/api/client';
import { RealtimeIdentity, startUserRealtime, validateUserGrant } from '../src/features/realtime/userRealtime';
import { useUserRealtime } from '../src/features/realtime/useUserRealtime';
import { queryKeys } from '../src/api/queryKeys';
import { config } from '../src/lib/config';
import { isUserRealtimeReady, ownUserRealtime, subscribeUserRealtime } from '../src/lib/realtime/transportState';
import { startSocketRealtime } from '../src/lib/realtime/socketEngine';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test/subpath' } }));
jest.mock('../src/api/endpoints/realtime', () => ({ userRealtimeGrant: jest.fn() }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));

const mockConfig = config as { apiBaseUrl: string };
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
let teamTasks: jest.Mock;
let teamCalendar: jest.Mock;
let teamChat: jest.Mock;

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  jest.setSystemTime(new Date('2026-01-01T00:00:00Z'));
  onlineManager.setOnline(true);
  focusManager.setFocused(true);
  mockConfig.apiBaseUrl = 'https://api.test/subpath';
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
  teamTasks = jest.fn().mockResolvedValue(['fresh']);
  teamCalendar = jest.fn().mockResolvedValue(['fresh']);
  teamChat = jest.fn().mockResolvedValue(['fresh']);
  observe(queryKeys.notifications, notifications);
  observe(queryKeys.notificationHistory(1, 'all'), notificationHistory);
  observe(queryKeys.queue, queue);
  observe(queryKeys.appointments({ service: 'clinic', status: 'all', sort: 'date' }), appointmentList);
  observe(queryKeys.appointment(5), appointmentDetail);
  observe(queryKeys.staffQueue('clinic'), staffClinic);
  observe(queryKeys.staffQueue('guidance'), staffGuidance);
  observe(queryKeys.staffQueue('all'), staffAll);
  observe(['team', IDENTITY.tenantId, IDENTITY.userId, 'clinic', 'tasks', { scope: 'team' }], teamTasks);
  observe(['team-calendar', IDENTITY.tenantId, IDENTITY.userId, 'clinic', 'list'], teamCalendar);
  observe(['team-chat', IDENTITY.tenantId, IDENTITY.userId, 'clinic', 'history'], teamChat);
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
  focusManager.setFocused(undefined);
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
    mockConfig.apiBaseUrl = 'http://api.test/path';
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
    // Start reconciles all covered targets immediately; staleTime Infinity prevents
    // observer mount fetches, so every count here comes from reconciliation.
    expect(notifications).toHaveBeenCalledTimes(1);
    expect(notificationHistory).toHaveBeenCalledTimes(1);
    expect(queue).toHaveBeenCalledTimes(1);
    expect(appointmentList).toHaveBeenCalledTimes(1);
    expect(appointmentDetail).toHaveBeenCalledTimes(1);
    expect(staffClinic).toHaveBeenCalledTimes(1);
    expect(staffGuidance).toHaveBeenCalledTimes(1);
    expect(staffAll).toHaveBeenCalledTimes(1);
    ws.ready();
    await flush();
    expect(ws.send.mock.calls).toEqual([[JSON.stringify({ type: 'authenticate', ticket: 'single-use-secret' })]]);
    // Server readiness repairs every covered resource, including the staff summary boards.
    expect(notifications).toHaveBeenCalledTimes(2);
    expect(notificationHistory).toHaveBeenCalledTimes(2);
    expect(queue).toHaveBeenCalledTimes(2);
    expect(appointmentList).toHaveBeenCalledTimes(2);
    expect(appointmentDetail).toHaveBeenCalledTimes(2);
    expect(staffClinic).toHaveBeenCalledTimes(2);
    expect(staffGuidance).toHaveBeenCalledTimes(2);
    expect(staffAll).toHaveBeenCalledTimes(2);
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
    expect(staffClinic).toHaveBeenCalledTimes(2);

    hint(ws, 'portal.queue.changed');
    await flush(100);
    expect(queue).toHaveBeenCalledTimes(3);
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(appointmentList).toHaveBeenCalledTimes(3);
    expect(appointmentDetail).toHaveBeenCalledTimes(3);

    hint(ws, 'portal.appointments.changed');
    await flush(100);
    expect(appointmentList).toHaveBeenCalledTimes(4);
    expect(appointmentDetail).toHaveBeenCalledTimes(4);
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(queue).toHaveBeenCalledTimes(3);

    hint(ws, 'queue.invalidated', { unit: 'clinic', date: '2026-01-01' });
    await flush(100);
    expect(staffClinic).toHaveBeenCalledTimes(3);
    expect(staffGuidance).toHaveBeenCalledTimes(2);
    expect(staffAll).toHaveBeenCalledTimes(3);
    hint(ws, 'queue.invalidated', { unit: 'guidance', date: '2026-01-01' });
    await flush(100);
    expect(staffGuidance).toHaveBeenCalledTimes(3);
    // Units without a mobile surface or wrong shapes never refresh anything.
    hint(ws, 'queue.invalidated', { unit: 'pharmacy', date: '2026-01-01' });
    hint(ws, 'queue.invalidated', { unit: 7 });
    hint(ws, 'team.tasks.changed', { unit: 'clinic' });
    await flush(100);
    expect(teamTasks).toHaveBeenCalledTimes(3);
    expect(teamCalendar).toHaveBeenCalledTimes(3); // Task deadlines share the calendar projection.
    expect(teamChat).toHaveBeenCalledTimes(2);
    hint(ws, 'team.calendar.changed', { unit: 'clinic' });
    await flush(100);
    expect(teamCalendar).toHaveBeenCalledTimes(4);
    expect(teamTasks).toHaveBeenCalledTimes(3);
    hint(ws, 'team.chat.changed', { unit: 'clinic' });
    await flush(100);
    expect(teamChat).toHaveBeenCalledTimes(3);
    hint(ws, 'team.chat.changed', { unit: 'guidance' });
    hint(ws, 'team.tasks.changed', { unit: 'Clinic' });
    hint(ws, 'team.calendar.changed', []);
    hint(ws, 'ai.insights.changed', { unit: 'clinic' });
    hint(ws, 'login.carousel.changed');
    hint(ws, 'notifications.changed', {});
    hint(ws, 'notifications.changed', [1]);
    hint(ws, 'portal.queue.changed', {});
    hint(ws, 'portal.queue.changed', [1]);
    hint(ws, 'portal.queue.changed', [], 'private.tenant.7.user.43');
    hint(ws, 'notifications.changed', [], 'private.tenant.7.user.43');
    ws.onmessage?.({ data: 'not JSON' });
    await flush(200);
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(notificationHistory).toHaveBeenCalledTimes(3);
    expect(queue).toHaveBeenCalledTimes(3);
    expect(appointmentList).toHaveBeenCalledTimes(4);
    expect(appointmentDetail).toHaveBeenCalledTimes(4);
    expect(staffClinic).toHaveBeenCalledTimes(3);
    expect(staffGuidance).toHaveBeenCalledTimes(3);
    expect(staffAll).toHaveBeenCalledTimes(4);
    expect(teamTasks).toHaveBeenCalledTimes(3);
    expect(teamCalendar).toHaveBeenCalledTimes(4);
    expect(teamChat).toHaveBeenCalledTimes(3);
    expect(client.getQueryCache().getAll()).toHaveLength(11);
  });

  it.each(['clinic', 'guidance'] as const)(
    'refreshes only the hinted %s and aggregate boards after initial reconciliation', async unit => {
      const extendedKey = [...queryKeys.staffQueue('all'), 'unrelated'];
      const extendedFetch = jest.fn().mockResolvedValue(['unrelated']);
      client.setQueryData(extendedKey, ['cached']);
      const un = new QueryObserver(client, { queryKey: extendedKey, queryFn: extendedFetch })
        .subscribe(() => undefined);
      try {
        const ws = await start();
        ws.ready();
        await flush();
        expect(staffAll).toHaveBeenCalledTimes(2);
        expect(staffClinic).toHaveBeenCalledTimes(2);
        expect(staffGuidance).toHaveBeenCalledTimes(2);
        // Date is not required by the existing hint contract; only unit selects targets.
        hint(ws, 'queue.invalidated', { unit });
        await flush(100);
        expect(staffAll).toHaveBeenCalledTimes(3);
        expect(staffClinic).toHaveBeenCalledTimes(unit === 'clinic' ? 3 : 2);
        expect(staffGuidance).toHaveBeenCalledTimes(unit === 'guidance' ? 3 : 2);
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
      expect(fetchQuery).toHaveBeenCalledTimes(2);
    }
    for (const unit of ['all', 'clinic', 'guidance']) {
      expect(client.getQueryState(queryKeys.staffQueue(unit))?.isInvalidated).toBe(false);
    }
    for (const fetchQuery of [notifications, notificationHistory, queue, appointmentList, appointmentDetail]) {
      expect(fetchQuery).toHaveBeenCalledTimes(2);
    }
  });

  it('does not block queue or appointment hints behind an unrelated slow inbox read', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    let resolveInbox!: (value: string[]) => void;
    notifications.mockImplementationOnce(() => new Promise(resolve => { resolveInbox = resolve; }));
    hint(ws, 'notifications.changed');
    await flush(100);
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(notificationHistory).toHaveBeenCalledTimes(3);
    hint(ws, 'portal.queue.changed');
    await flush(100);
    expect(queue).toHaveBeenCalledTimes(3);
    expect(appointmentList).toHaveBeenCalledTimes(3);
    expect(appointmentDetail).toHaveBeenCalledTimes(3);
    expect(client.getQueryState(queryKeys.notifications)?.fetchStatus).toBe('fetching');
    hint(ws, 'portal.appointments.changed');
    await flush(100);
    expect(appointmentList).toHaveBeenCalledTimes(4);
    resolveInbox(['late inbox']);
    await flush(100);
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(queue).toHaveBeenCalledTimes(3);
  });

  it('reconciles independent queries without waiting for the first initial read', async () => {
    let resolveInbox!: (value: string[]) => void;
    notifications.mockImplementationOnce(() => new Promise(resolve => { resolveInbox = resolve; }));
    await start();
    expect(notifications).toHaveBeenCalledTimes(1);
    expect(notificationHistory).toHaveBeenCalledTimes(1);
    expect(queue).toHaveBeenCalledTimes(1);
    expect(appointmentList).toHaveBeenCalledTimes(1);
    expect(staffAll).toHaveBeenCalledTimes(1);
    resolveInbox(['late first inbox']);
    await flush();
  });

  it('isolates failed reads and still processes later hints for the failed key', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    notifications.mockRejectedValueOnce(new Error('inbox unavailable'));
    hint(ws, 'notifications.changed');
    hint(ws, 'portal.queue.changed');
    await flush(100);
    expect(queue).toHaveBeenCalledTimes(3);
    expect(appointmentList).toHaveBeenCalledTimes(3);
    expect(client.getQueryData(queryKeys.notifications)).toEqual(['fresh']);
    hint(ws, 'notifications.changed');
    await flush(100);
    expect(notifications).toHaveBeenCalledTimes(4);
    expect(client.getQueryState(queryKeys.notifications)?.status).toBe('success');
  });

  it('invalidates overlapping exact and prefix targets only once per concrete query', async () => {
    stop = startSocketRealtime(client, {
      reconcileMs: 120_000,
      fetchGrant: async () => ({ data: grant() }),
      validateGrant: value => validateUserGrant(value, IDENTITY),
      reconcileTargets: () => [],
      targetsFor: () => [
        { key: ['appointments'], exact: false },
        { key: queryKeys.appointment(5), exact: true },
        { key: ['appointments'], exact: false },
      ],
    });
    await flush();
    const ws = latest();
    ws.ready();
    await flush();
    const invalidations: string[] = [];
    const un = client.getQueryCache().subscribe(event => {
      if (event.type === 'updated' && event.action.type === 'invalidate') { invalidations.push(event.query.queryHash); }
    });
    try {
      hint(ws, 'overlapping.targets');
      await flush(100);
      expect(appointmentList).toHaveBeenCalledTimes(1);
      expect(appointmentDetail).toHaveBeenCalledTimes(1);
      expect(invalidations).toHaveLength(2);
      expect(new Set(invalidations).size).toBe(2);
    } finally { un(); }
  });

  it('delivers disabled signal invalidations without refetching the signal observer', async () => {
    const key = ['team-chat', IDENTITY.tenantId, IDENTITY.userId, 'guidance', 'channel'];
    client.setQueryData(key, ['signal']);
    const read = jest.fn().mockResolvedValue(['must not fetch']);
    const observer = new QueryObserver(client, { queryKey: key, queryFn: read, enabled: false });
    const unObserver = observer.subscribe(() => undefined);
    const signal = jest.fn();
    const unCache = client.getQueryCache().subscribe(event => {
      if (event.type === 'updated' && event.action.type === 'invalidate' && event.query.queryHash === observer.getCurrentQuery().queryHash) {
        signal();
        event.query.setState({ isInvalidated: false });
      }
    });
    try {
      const ws = await start();
      ws.ready();
      await flush();
      signal.mockClear();
      hint(ws, 'team.chat.changed', { unit: 'guidance' });
      await flush(100);
      hint(ws, 'team.chat.changed', { unit: 'guidance' });
      await flush(100);
      expect(signal).toHaveBeenCalledTimes(2);
      expect(read).not.toHaveBeenCalled();
    } finally { unCache(); unObserver(); }
  });

  it('retains a lone hint that overlaps an existing HTTP read for exactly one trailing fetch', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    let resolveOld!: (value: string[]) => void;
    notifications.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve; }));
    const external = client.refetchQueries({ queryKey: queryKeys.notifications, exact: true });
    expect(notifications).toHaveBeenCalledTimes(3);
    hint(ws, 'notifications.changed');
    await flush(100);
    expect(notifications).toHaveBeenCalledTimes(3);
    resolveOld(['snapshot before hint']);
    await external;
    await flush(100);
    expect(notifications).toHaveBeenCalledTimes(4);
    expect(client.getQueryData(queryKeys.notifications)).toEqual(['fresh']);
    await flush(5_000);
    expect(notifications).toHaveBeenCalledTimes(4);
  });

  it('does not run trailing HTTP work after stopping an overlapped lifetime', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    let resolveOld!: (value: string[]) => void;
    notifications.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve; }));
    const external = client.refetchQueries({ queryKey: queryKeys.notifications, exact: true });
    hint(ws, 'notifications.changed');
    await flush(100);
    const historyReadsBeforeStop = notificationHistory.mock.calls.length;
    stop!(); stop = undefined;
    resolveOld(['external result']);
    await external;
    await flush(5_000);
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(notificationHistory).toHaveBeenCalledTimes(historyReadsBeforeStop);
  });

  it('rejects old socket hints and trailing work immediately when the API runtime retires', async () => {
    const runtime = createApiRuntime({ apiBaseUrl: 'https://api.test/subpath' });
    activateApiRuntime(runtime);
    const binding = bindApiClient(runtime);
    stop = startUserRealtime(client, IDENTITY, binding);
    await flush();
    const ws = latest();
    ws.ready();
    await flush();
    let resolveOld!: (value: string[]) => void;
    notifications.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve; }));
    const external = client.refetchQueries({ queryKey: queryKeys.notifications, exact: true });
    hint(ws, 'notifications.changed');
    await flush(100);
    retireApiRuntime(runtime);
    const replacement = createApiRuntime({ apiBaseUrl: 'https://other.test' });
    activateApiRuntime(replacement);
    // Effect cleanup has not run yet; no old hint may even invalidate the cache.
    hint(ws, 'portal.queue.changed');
    resolveOld(['old read finished']);
    await external;
    await flush(1000);
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(queue).toHaveBeenCalledTimes(2);
    expect(appointmentList).toHaveBeenCalledTimes(2);
    expect(client.getQueryState(queryKeys.queue)?.isInvalidated).toBe(false);
    expect(userRealtimeGrant).toHaveBeenCalledTimes(1);
    retireApiRuntime(replacement);
  });

  it('does not schedule trailing reads for a disabled observer with an external fetch', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    let resolveOld!: (value: string[]) => void;
    const read = jest.fn(() => new Promise<string[]>(resolve => { resolveOld = resolve; }));
    const key = ['team-chat', IDENTITY.tenantId, IDENTITY.userId, 'guidance', 'history'];
    const observer = new QueryObserver(client, { queryKey: key, queryFn: read, enabled: false });
    const un = observer.subscribe(() => undefined);
    try {
      const external = client.fetchQuery({ queryKey: key, queryFn: read });
      hint(ws, 'team.chat.changed', { unit: 'guidance' });
      await flush(100);
      resolveOld(['external result']);
      await external;
      await flush(5_000);
      expect(read).toHaveBeenCalledTimes(1);
    } finally { un(); }
  });

  it('coalesces repeated in-flight hints per query while other keys keep refreshing', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    let resolveQueue!: (value: string[]) => void;
    queue.mockImplementationOnce(() => new Promise(resolve => { resolveQueue = resolve; }));
    hint(ws, 'portal.queue.changed');
    await flush(100);
    for (let index = 0; index < 50; index++) { hint(ws, 'portal.queue.changed'); }
    hint(ws, 'notifications.changed');
    await flush(100);
    expect(queue).toHaveBeenCalledTimes(3);
    expect(notifications).toHaveBeenCalledTimes(3);
    expect(appointmentList).toHaveBeenCalledTimes(4);
    resolveQueue(['before later hints']);
    await flush(100);
    expect(queue).toHaveBeenCalledTimes(4);
    await flush(1000);
    expect(queue).toHaveBeenCalledTimes(4);
  });

  it('keeps an already-disabled in-flight query stale after its old response without fetching again', async () => {
    const key = ['appointments', 'already-covered'];
    let resolveOld!: (value: string[]) => void;
    const read = jest.fn(() => new Promise<string[]>(resolve => { resolveOld = resolve; }));
    client.setQueryData(key, ['cached']);
    const observer = new QueryObserver(client, { queryKey: key, queryFn: read, enabled: false });
    const un = observer.subscribe(() => undefined);
    try {
      const ws = await start();
      ws.ready();
      await flush();
      const external = client.fetchQuery({ queryKey: key, queryFn: read });
      hint(ws, 'portal.appointments.changed');
      await flush(100);
      resolveOld(['before hint']);
      await external;
      await flush(100);
      expect(read).toHaveBeenCalledTimes(1);
      expect(client.getQueryState(key)?.isInvalidated).toBe(true);
    } finally { un(); }
  });

  it('marks a covered query stale after an overlapped read without fetching it while disabled', async () => {
    const key = ['appointments', 'covered'];
    const read = jest.fn().mockResolvedValue(['fresh']);
    client.setQueryData(key, ['cached']);
    const observer = new QueryObserver(client, { queryKey: key, queryFn: read });
    const un = observer.subscribe(() => undefined);
    try {
      const ws = await start();
      ws.ready();
      await flush();
      let resolveOld!: (value: string[]) => void;
      read.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve; }));
      const external = observer.refetch();
      hint(ws, 'portal.appointments.changed');
      await flush(100);
      observer.setOptions({ queryKey: key, queryFn: read, enabled: false });
      resolveOld(['before hint']);
      await external;
      await flush(100);
      expect(read).toHaveBeenCalledTimes(3);
      expect(client.getQueryState(key)?.isInvalidated).toBe(true);
      observer.setOptions({ queryKey: key, queryFn: read, enabled: true });
      await flush();
      expect(read).toHaveBeenCalledTimes(4);
      expect(client.getQueryData(key)).toEqual(['fresh']);
    } finally { un(); }
  });

  it('waits for a paused physical request without spinning trailing refreshes', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    onlineManager.setOnline(false);
    hint(ws, 'notifications.changed');
    await flush(100);
    expect(notifications).toHaveBeenCalledTimes(2);
    expect(client.getQueryState(queryKeys.notifications)?.fetchStatus).toBe('paused');
    for (let index = 0; index < 50; index++) { hint(ws, 'notifications.changed'); }
    await flush(1000);
    expect(notifications).toHaveBeenCalledTimes(2);
    stop!(); stop = undefined;
    await client.cancelQueries({ queryKey: queryKeys.notifications });
    await flush(1000);
    expect(jest.getTimerCount()).toBe(0);
  });

  it('retains invalidation across an already-paused external request on a disabled query', async () => {
    const key = ['appointments', 'parked-covered'];
    client.mount();
    const read = jest.fn().mockResolvedValue(['old parked response']);
    client.setQueryData(key, ['cached']);
    const observer = new QueryObserver(client, { queryKey: key, queryFn: read, enabled: false });
    const un = observer.subscribe(() => undefined);
    try {
      const ws = await start();
      ws.ready();
      await flush();
      onlineManager.setOnline(false);
      const external = client.fetchQuery({ queryKey: key, queryFn: read, staleTime: 0 });
      expect(client.getQueryState(key)?.fetchStatus).toBe('paused');
      hint(ws, 'portal.appointments.changed');
      await flush(100);
      expect(read).not.toHaveBeenCalled();
      onlineManager.setOnline(true);
      await external;
      await flush(100);
      expect(read).toHaveBeenCalledTimes(1);
      expect(client.getQueryState(key)?.isInvalidated).toBe(true);
    } finally { un(); client.unmount(); }
  });

  it('does not refresh a removed query or replacement instance from stale trailing work', async () => {
    const key = ['appointments', 'removed'];
    let resolveOld!: (value: string[]) => void;
    const read = jest.fn().mockResolvedValue(['fresh']);
    client.setQueryData(key, ['cached']);
    const observer = new QueryObserver(client, { queryKey: key, queryFn: read });
    const un = observer.subscribe(() => undefined);
    try {
      const ws = await start();
      ws.ready();
      await flush();
      read.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve; }));
      const external = observer.refetch();
      hint(ws, 'portal.appointments.changed');
      await flush(100);
      client.removeQueries({ queryKey: key, exact: true });
      client.setQueryData(key, ['replacement']);
      resolveOld(['obsolete']);
      await external;
      await flush(1000);
      expect(read).toHaveBeenCalledTimes(3);
      expect(client.getQueryData(key)).toEqual(['replacement']);
    } finally { un(); }
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

describe('authenticated transport readiness', () => {
  it('stays in fallback during grant fetch and socket open until one authenticated server ready', async () => {
    let resolveGrant!: (value: { data: ReturnType<typeof grant> }) => void;
    jest.mocked(userRealtimeGrant).mockImplementationOnce(() => new Promise(resolve => { resolveGrant = resolve; }));
    const changed = jest.fn(() => isUserRealtimeReady(client, IDENTITY));
    const un = subscribeUserRealtime(client, changed);
    try {
      stop = startUserRealtime(client, IDENTITY);
      await flush();
      expect(MockSocket.instances).toHaveLength(0);
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
      resolveGrant({ data: grant() });
      await flush();
      const ws = latest();
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
      ws.message({ type: 'ready' }); // An unsolicited frame before authenticate is not readiness.
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
      ws.open();
      expect(ws.send).toHaveBeenCalledWith(JSON.stringify({ type: 'authenticate', ticket: 'single-use-secret' }));
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
      ws.message({ type: 'ready' });
      await flush();
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
      expect(changed.mock.results.map(result => result.value)).toEqual([false, true]);
      ws.message({ type: 'ready' });
      await flush();
      expect(changed.mock.results.map(result => result.value)).toEqual([false, true]);
      expect(teamTasks).toHaveBeenCalledTimes(2);
    } finally {
      un();
    }
  });

  it('keeps fallback after an open socket fails the handshake and rejects its saved late ready callback', async () => {
    const ws = await start();
    ws.open();
    const lateMessage = ws.onmessage!;
    await flush(9_999);
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
    expect(ws.close).not.toHaveBeenCalled();
    await flush(1);
    expect(ws.close).toHaveBeenCalledTimes(1);
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
    await flush(1_000);
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(2);
    expect(latest()).not.toBe(ws);
    lateMessage({ data: JSON.stringify({ type: 'ready' }) });
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
    latest().ready();
    await flush();
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
  });

  it.each(['close', 'heartbeat timeout', 'ticket renewal'] as const)(
    'restores fallback before %s and requires a new authenticated connection', async cause => {
      const ws = await start();
      ws.ready();
      await flush();
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
      const lateMessage = ws.onmessage!;
      const lateClose = ws.onclose!;
      ws.close.mockImplementation(() => { expect(isUserRealtimeReady(client, IDENTITY)).toBe(false); });
      if (cause === 'close') {
        ws.onclose!();
      } else if (cause === 'heartbeat timeout') {
        await flush(20_000);
        expect(ws.send).toHaveBeenLastCalledWith(JSON.stringify({ type: 'ping' }));
        expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
        await flush(10_000);
      } else {
        for (let i = 0; i < 2; i++) {
          await flush(20_000);
          ws.message({ type: 'pong' });
        }
        await flush(9_999);
        expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
        await flush(1);
      }
      expect(ws.close).toHaveBeenCalledTimes(1);
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
      if (cause !== 'ticket renewal') { await flush(1_000); }
      const replacement = latest();
      expect(replacement).not.toBe(ws);
      replacement.open();
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
      lateMessage({ data: JSON.stringify({ type: 'ready' }) });
      lateClose();
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
      replacement.message({ type: 'ready' });
      await flush();
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
      lateClose();
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
      expect(replacement.close).not.toHaveBeenCalled();
    },
  );

  it('isolates leases by client and identity, ignoring replaced and released callbacks', () => {
    const otherClient = new QueryClient();
    const otherIdentity = { tenantId: 8, userId: 43 };
    const old = ownUserRealtime(client, IDENTITY);
    const other = ownUserRealtime(otherClient, IDENTITY);
    const changed = jest.fn();
    const un = subscribeUserRealtime(client, changed);
    try {
      expect(isUserRealtimeReady(client, null)).toBe(false);
      expect(isUserRealtimeReady(client, { tenantId: 0, userId: 42 })).toBe(false);
      old.setReady(true);
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
      expect(isUserRealtimeReady(otherClient, IDENTITY)).toBe(false);
      other.setReady(true);
      const replacement = ownUserRealtime(client, otherIdentity);
      expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
      expect(isUserRealtimeReady(client, otherIdentity)).toBe(false);
      old.setReady(true); old.release();
      expect(isUserRealtimeReady(client, otherIdentity)).toBe(false);
      replacement.setReady(true);
      old.setReady(false); old.release();
      expect(isUserRealtimeReady(client, otherIdentity)).toBe(true);
      replacement.release();
      replacement.setReady(true);
      expect(isUserRealtimeReady(client, otherIdentity)).toBe(false);
      expect(isUserRealtimeReady(otherClient, IDENTITY)).toBe(true);
      expect(changed).toHaveBeenCalledTimes(4);
      un();
      other.release();
      const next = ownUserRealtime(client, IDENTITY);
      next.setReady(true); next.release();
      expect(changed).toHaveBeenCalledTimes(4);
    } finally {
      un();
      other.release();
      otherClient.clear();
    }
  });
});

describe('reconciliation and cleanup', () => {
  it('rechecks a disabled grant every 120 seconds and connects when reconfigured', async () => {
    jest.mocked(userRealtimeGrant).mockImplementation(async () => ({ data: { enabled: false } }));
    stop = startUserRealtime(client, IDENTITY);
    await flush();
    expect(MockSocket.instances).toHaveLength(0);
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
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

  it('repairs all covered queries including staff boards every 120 seconds on a healthy connection', async () => {
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
    expect(staffClinic).toHaveBeenCalledTimes(3);
    expect(staffGuidance).toHaveBeenCalledTimes(3);
    expect(staffAll).toHaveBeenCalledTimes(3);
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(1);
    expect(ws.close).toHaveBeenCalledTimes(0);
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
    expect(teamTasks).toHaveBeenCalledTimes(3);
    expect(teamCalendar).toHaveBeenCalledTimes(3);
    expect(teamChat).toHaveBeenCalledTimes(3);
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
    await act(async () => { ws.ready(); await flush(); });
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
    const lateMessage = ws.onmessage!;
    const lateClose = ws.onclose!;
    ws.close.mockImplementation(() => { expect(isUserRealtimeReady(client, IDENTITY)).toBe(false); });
    await act(async () => { onlineManager.setOnline(false); await flush(); });
    expect(ws.close).toHaveBeenCalledTimes(1);
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
    await act(async () => { await flush(300_000); });
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(1);
    await act(async () => { onlineManager.setOnline(true); await flush(); });
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(2);
    // Reconnect and the new lifetime share each already-running authorized read.
    expect(notifications).toHaveBeenCalledTimes(3);
    // Every scoped resource is repaired without waiting for unrelated HTTP reads.
    expect(teamTasks).toHaveBeenCalledTimes(3);
    expect(teamCalendar).toHaveBeenCalledTimes(3);
    expect(teamChat).toHaveBeenCalledTimes(3);
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
    lateMessage({ data: JSON.stringify({ type: 'ready' }) });
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
    const replacement = latest();
    await act(async () => { replacement.ready(); await flush(); });
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
    lateClose();
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(true);
    expect(replacement.close).not.toHaveBeenCalled();
    expect(jest.getTimerCount()).toBeGreaterThan(0);
    act(() => renderer!.unmount()); renderer = undefined;
    expect(isUserRealtimeReady(client, IDENTITY)).toBe(false);
    expect(latest().close).toHaveBeenCalledTimes(1);
    onlineManager.setOnline(false); onlineManager.setOnline(true);
    await flush(300_000);
    expect(jest.mocked(userRealtimeGrant)).toHaveBeenCalledTimes(2);
    expect(jest.getTimerCount()).toBe(0);
  });
});



