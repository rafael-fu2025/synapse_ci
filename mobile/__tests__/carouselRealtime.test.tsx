import React from 'react';
import { QueryClient, QueryClientProvider, QueryObserver, onlineManager } from '@tanstack/react-query';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { loginCarouselRealtime } from '../src/api/endpoints/auth';
import { ApiError, NetworkError } from '../src/api/client';
import { startCarouselRealtime, validateCarouselGrant } from '../src/features/auth/realtime/carouselRealtime';
import { useCarouselRealtime } from '../src/features/auth/realtime/useCarouselRealtime';
import { config } from '../src/lib/config';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test/subpath' } }));
jest.mock('../src/api/endpoints/auth', () => ({ loginCarouselRealtime: jest.fn() }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));

const KEY = ['auth', 'carousel'];
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
    channel: 'public.tenant.7.login-carousel',
    ticket: 'single-use-secret',
    expires_at: Math.floor(Date.now() / 1000) + 60,
    ...overrides,
  };
}
function changed(ws: MockSocket, overrides: Record<string, unknown> = {}) {
  ws.message({ type: 'event', channel: 'public.tenant.7.login-carousel', event: 'login.carousel.changed', data: [], ...overrides });
}
async function flush(ms = 0) { await jest.advanceTimersByTimeAsync(ms); }
function latest() { return MockSocket.instances[MockSocket.instances.length - 1]; }

let client: QueryClient;
let queryFn: jest.Mock;
let stop: (() => void) | undefined;
let unsubscribe: () => void;
let renderer: ReactTestRenderer | undefined;

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  jest.setSystemTime(new Date('2026-01-01T00:00:00Z'));
  onlineManager.setOnline(true);
  config.apiBaseUrl = 'https://api.test/subpath';
  MockSocket.instances = [];
  globalThis.WebSocket = MockSocket as unknown as typeof WebSocket;
  jest.mocked(loginCarouselRealtime).mockReset().mockImplementation(async () => ({ data: grant() }));
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity, staleTime: Infinity } } });
  queryFn = jest.fn().mockResolvedValue(['fresh']);
  client.setQueryData(KEY, ['cached']);
  const observer = new QueryObserver(client, { queryKey: KEY, queryFn });
  unsubscribe = observer.subscribe(() => undefined);
  jest.spyOn(Math, 'random').mockReturnValue(1);
});
afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  stop?.();
  stop = undefined;
  unsubscribe();
  client.clear();
  globalThis.WebSocket = originalWebSocket;
  onlineManager.setOnline(true);
  jest.restoreAllMocks();
  jest.useRealTimers();
});

async function start() {
  stop = startCarouselRealtime(client);
  await flush();
  return latest();
}

describe('public carousel grant validation', () => {
  it('allows a separate socket host and the API origin without its path', () => {
    expect(validateCarouselGrant(grant())).toEqual(grant());
    config.apiBaseUrl = 'http://api.test/path';
    expect(validateCarouselGrant(grant({ origin: 'http://api.test', url: 'ws://socket.test/path' }))).not.toBeNull();
  });
  it('accepts the server maximum TTL of 300 seconds, but rejects longer and unsafe expiry values', () => {
    const now = Math.floor(Date.now() / 1000);
    expect(validateCarouselGrant(grant({ expires_at: now + 300 }))).not.toBeNull();
    expect(validateCarouselGrant(grant({ expires_at: now + 301 }))).toBeNull();
    expect(validateCarouselGrant(grant({ expires_at: Number.MAX_SAFE_INTEGER }))).toBeNull();
  });
  it.each([
    { enabled: false }, { origin: 'https://other.test' }, { origin: 'https://api.test/subpath' },
    { url: 'ws://socket.test' }, { url: 'https://socket.test' },
    { url: 'wss://socket.test/?ticket=secret' }, { url: 'wss://socket.test/#fragment' },
    { url: 'wss://socket.test/?' }, { url: 'wss://socket.test/#' },
    { url: 'wss://name:password@socket.test' }, { url: 'wss://socket.test/ white' },
    { url: 'wss://socket.test/\\path' },
    { channel: 'private.tenant.7.user.1' }, { channel: 'public.tenant.7.login-carousel.extra' },
    { channel: 'public.tenant.0.login-carousel' }, { channel: 'public.tenant.01.login-carousel' },
    { channel: 'public.tenant.*.login-carousel' }, { ticket: '' }, { ticket: 1 },
    { expires_at: 1 }, { expires_at: Infinity }, { expires_at: '9999999999' },
  ])('rejects an unsafe or malformed grant: %j', overrides => {
    expect(validateCarouselGrant(grant(overrides))).toBeNull();
  });
});

describe('socket protocol and cache repair', () => {
  it('uses only Origin in native options, authenticates in a frame, and refetches on ready', async () => {
    const ws = await start();
    expect(ws.url).toBe('wss://socket.test/realtime');
    expect(ws.protocols).toBeUndefined();
    expect(ws.options).toEqual({ headers: { Origin: 'https://api.test' } });
    expect(ws.send).not.toHaveBeenCalled();
    expect(queryFn).toHaveBeenCalledTimes(1);
    // An unsolicited ready before authentication must not establish a connection.
    ws.message({ type: 'ready' });
    await flush();
    expect(queryFn).toHaveBeenCalledTimes(1);
    ws.ready();
    await flush();
    expect(ws.send.mock.calls).toEqual([[JSON.stringify({ type: 'authenticate', ticket: 'single-use-secret' })]]);
    expect(queryFn).toHaveBeenCalledTimes(2);
    expect(client.getQueryData(KEY)).toEqual(['fresh']);
    ws.message({ type: 'ready' });
    await flush();
    expect(queryFn).toHaveBeenCalledTimes(2);
  });

  it('ignores pre-ready, private, wrong-tenant, wrong-event and malformed messages', async () => {
    const ws = await start();
    changed(ws);
    await flush(100);
    expect(queryFn).toHaveBeenCalledTimes(1);
    ws.ready();
    await flush();
    for (const channel of ['private.tenant.7.user.1', 'public.tenant.8.login-carousel', 'public.tenant.7.login-carousel.extra']) {
      changed(ws, { channel });
    }
    changed(ws, { event: 'notifications.changed' });
    changed(ws, { data: {} });
    changed(ws, { data: [1] });
    ws.onmessage?.({ data: 'not JSON' });
    ws.message(null);
    ws.message([]);
    await flush(100);
    expect(queryFn).toHaveBeenCalledTimes(2);
    expect(client.getQueryCache().getAll()).toHaveLength(1);
  });

  it('coalesces bursts and follows an in-flight fetch with just one trailing refresh', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    let resolve!: (data: string[]) => void;
    queryFn.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    for (let i = 0; i < 50; i++) { changed(ws); }
    await flush(99);
    expect(queryFn).toHaveBeenCalledTimes(2);
    await flush(1);
    expect(queryFn).toHaveBeenCalledTimes(3);
    for (let i = 0; i < 50; i++) { changed(ws); }
    await flush(1000);
    expect(queryFn).toHaveBeenCalledTimes(3);
    resolve(['intermediate']);
    await flush(100);
    expect(queryFn).toHaveBeenCalledTimes(4);
    await flush(1000);
    expect(queryFn).toHaveBeenCalledTimes(4);
  });

  it('repairs ready/events that arrive during the initial HTTP request without cancelling it', async () => {
    let resolve!: (data: string[]) => void;
    queryFn.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    const pending = client.refetchQueries({ queryKey: KEY });
    const ws = await start();
    ws.ready();
    changed(ws);
    await flush(100);
    expect(queryFn).toHaveBeenCalledTimes(1);
    resolve(['old']);
    await pending;
    await flush(100);
    expect(queryFn).toHaveBeenCalledTimes(2);
    expect(client.getQueryData(KEY)).toEqual(['fresh']);
  });
});

describe('heartbeat, renewal and bounded reconnect', () => {
  it('pings every 20 seconds, accepts pong, and reconnects after a missing pong', async () => {
    const ws = await start();
    ws.ready();
    await flush(19_999);
    expect(ws.send).toHaveBeenCalledTimes(1);
    await flush(1);
    expect(ws.send).toHaveBeenLastCalledWith('{"type":"ping"}');
    ws.message({ type: 'pong' });
    await flush(10_000);
    expect(ws.close).not.toHaveBeenCalled();
    await flush(10_000);
    expect(ws.send).toHaveBeenCalledTimes(3);
    await flush(10_000);
    expect(ws.close).toHaveBeenCalledTimes(1);
    await flush(1000);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
  });

  it('renews a 60-second ticket by closing and obtaining a new grant at 50 seconds', async () => {
    const ws = await start();
    ws.ready();
    await flush(20_000); ws.message({ type: 'pong' });
    await flush(20_000); ws.message({ type: 'pong' });
    await flush(9999);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await flush(1);
    expect(ws.close).toHaveBeenCalledTimes(1);
    expect(ws.onmessage).toBeNull();
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
    expect(latest()).not.toBe(ws);
    latest().ready();
    await flush();
    expect(queryFn).toHaveBeenCalledTimes(3);
  });

  it.each(['connect', 'authenticate'] as const)('bounds the %s timeout to ten seconds', async phase => {
    const ws = await start();
    if (phase === 'authenticate') { await flush(5000); ws.open(); }
    await flush(9999);
    expect(ws.close).not.toHaveBeenCalled();
    await flush(1);
    expect(ws.close).toHaveBeenCalledTimes(1);
    await flush(1000);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
  });

  it('caps exponential backoff at 30 seconds, including rapid ready/close loops', async () => {
    await start();
    for (const delay of [1000, 2000, 4000, 8000, 16_000, 30_000, 30_000]) {
      const ws = latest();
      ws.ready();
      ws.onclose?.();
      const count = MockSocket.instances.length;
      await flush(delay - 1);
      expect(MockSocket.instances).toHaveLength(count);
      await flush(1);
      expect(MockSocket.instances).toHaveLength(count + 1);
    }
  });

  it('uses lower-bound jitter instead of a fixed retry interval', async () => {
    jest.mocked(Math.random).mockReturnValue(0);
    const ws = await start();
    ws.onerror?.();
    await flush(499);
    expect(MockSocket.instances).toHaveLength(1);
    await flush(1);
    expect(MockSocket.instances).toHaveLength(2);
  });
});

describe('HTTP ticket retry policy', () => {
  it.each([401, 403])('waits for 120-second reconciliation after HTTP %i instead of fast retries', async status => {
    jest.mocked(loginCarouselRealtime).mockRejectedValue(new ApiError(status, { code: 'denied', message: 'Denied' }));
    await start();
    await flush(119_999);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    expect(MockSocket.instances).toHaveLength(0);
    await flush(1);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
    expect(queryFn).toHaveBeenCalledTimes(2);
    await flush(119_999);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
    await flush(1);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(3);
  });

  it.each([401, 403])('allows a foreground cycle to recheck HTTP %i immediately', async status => {
    jest.mocked(loginCarouselRealtime).mockRejectedValueOnce(new ApiError(status, { code: 'denied', message: 'Denied' }));
    await act(async () => { renderer = create(tree()); await flush(); });
    await act(async () => { renderer!.update(tree(true, false)); await flush(); });
    await act(async () => { renderer!.update(tree()); await flush(); });
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
    expect(MockSocket.instances).toHaveLength(1);
  });

  it('honors Retry-After beyond reconciliation ticks while still refreshing HTTP metadata', async () => {
    jest.mocked(loginCarouselRealtime).mockRejectedValueOnce(new ApiError(429, { code: 'rate_limited', message: 'Wait' }, 287));
    await start();
    await flush(120_000);
    expect(queryFn).toHaveBeenCalledTimes(2);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await flush(120_000);
    expect(queryFn).toHaveBeenCalledTimes(3);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await flush(46_999);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await flush(1);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
    expect(MockSocket.instances).toHaveLength(1);
  });

  it.each([null, -1, NaN, Infinity, 1.5, Number.MAX_SAFE_INTEGER])('uses a safe 60-second fallback for invalid Retry-After %s', async seconds => {
    jest.mocked(loginCarouselRealtime).mockRejectedValueOnce(new ApiError(429, { code: 'rate_limited', message: 'Wait' }, seconds));
    await start();
    await flush(59_999);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await flush(1);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
  });

  it('bounds timers for long valid Retry-After without retrying before its deadline', async () => {
    const seconds = 2_147_484;
    jest.mocked(loginCarouselRealtime).mockRejectedValueOnce(new ApiError(429, { code: 'rate_limited', message: 'Wait' }, seconds));
    const timer = jest.spyOn(globalThis, 'setTimeout');
    await start();
    expect(timer).toHaveBeenCalledWith(expect.any(Function), 2_147_483_647);
    // Repeated reconciliation and timer chunks must never bypass the absolute deadline.
    jest.advanceTimersByTime(2_147_483_647);
    await flush();
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await flush(352);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await flush(1);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
  });

  it('does not tight-loop on Retry-After zero', async () => {
    jest.mocked(loginCarouselRealtime).mockRejectedValue(new ApiError(429, { code: 'rate_limited', message: 'Wait' }, 0));
    await start();
    await flush(999);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await flush(1);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
  });

  it('keeps the 429 deadline across foreground/offline cycles and cancels paused timers', async () => {
    jest.mocked(loginCarouselRealtime).mockRejectedValueOnce(new ApiError(429, { code: 'rate_limited', message: 'Wait' }, 180));
    await act(async () => { renderer = create(tree()); await flush(); });
    await act(async () => { renderer!.update(tree(true, false)); await flush(); });
    expect(jest.getTimerCount()).toBe(0);
    await flush(60_000);
    await act(async () => { renderer!.update(tree()); await flush(); });
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await act(async () => { onlineManager.setOnline(false); await flush(); });
    expect(jest.getTimerCount()).toBe(0);
    await flush(60_000);
    await act(async () => { onlineManager.setOnline(true); await flush(); });
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await act(async () => { await flush(59_999); });
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await act(async () => { await flush(1); });
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
  });

  it.each([new NetworkError(), new ApiError(503, { code: 'unavailable', message: 'Unavailable' })])('keeps jittered backoff for transient %s', async error => {
    jest.mocked(Math.random).mockReturnValue(0);
    jest.mocked(loginCarouselRealtime).mockRejectedValue(error);
    await start();
    await flush(499);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await flush(1);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
    await flush(999);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
    await flush(1);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(3);
  });
});

describe('fallback and cleanup', () => {
  it('rechecks disabled grants at 120 seconds and connects when reconfigured', async () => {
    jest.mocked(loginCarouselRealtime).mockResolvedValueOnce({ data: { enabled: false } });
    await start();
    await flush(119_999);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    expect(MockSocket.instances).toHaveLength(0);
    await flush(1);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
    expect(MockSocket.instances).toHaveLength(1);
    expect(queryFn).toHaveBeenCalledTimes(2);
  });
  it.each(['disabled', 'unavailable', 'missing WebSocket'] as const)('reconciles via HTTP every 120 seconds when %s', async reason => {
    if (reason === 'disabled') { jest.mocked(loginCarouselRealtime).mockResolvedValue({ data: { enabled: false } }); }
    if (reason === 'unavailable') { jest.mocked(loginCarouselRealtime).mockRejectedValue(new Error('Unavailable')); }
    if (reason === 'missing WebSocket') { globalThis.WebSocket = undefined as unknown as typeof WebSocket; }
    await start();
    expect(queryFn).toHaveBeenCalledTimes(1);
    await flush(119_999);
    expect(queryFn).toHaveBeenCalledTimes(1);
    await flush(1);
    expect(queryFn).toHaveBeenCalledTimes(2);
  });

  it('also reconciles a healthy connection every 120 seconds', async () => {
    jest.mocked(loginCarouselRealtime).mockImplementation(async () => ({ data: grant({ expires_at: Math.floor(Date.now() / 1000) + 300 }) }));
    const ws = await start();
    ws.ready();
    await flush();
    for (let i = 0; i < 5; i++) { await flush(20_000); ws.message({ type: 'pong' }); }
    await flush(19_999);
    expect(queryFn).toHaveBeenCalledTimes(2);
    await flush(1);
    ws.message({ type: 'pong' });
    expect(queryFn).toHaveBeenCalledTimes(3);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
  });

  it('detaches handlers and cancels all timers, including pending event refresh/reconnect', async () => {
    const ws = await start();
    ws.ready();
    await flush();
    const staleMessage = ws.onmessage!;
    changed(ws);
    ws.onerror?.();
    stop!(); stop = undefined;
    await flush();
    expect([ws.onopen, ws.onmessage, ws.onerror, ws.onclose]).toEqual([null, null, null, null]);
    expect(jest.getTimerCount()).toBe(0);
    staleMessage({ data: JSON.stringify({ type: 'ready' }) });
    await flush(300_000);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    expect(queryFn).toHaveBeenCalledTimes(2);
  });

  it('ignores a grant and fetch completion arriving after stop', async () => {
    let resolveGrant!: (data: { data: ReturnType<typeof grant> }) => void;
    let resolveFetch!: (data: string[]) => void;
    jest.mocked(loginCarouselRealtime).mockImplementationOnce(() => new Promise(resolve => { resolveGrant = resolve; }));
    queryFn.mockImplementationOnce(() => new Promise(resolve => { resolveFetch = resolve; }));
    await start();
    stop!(); stop = undefined;
    resolveGrant({ data: grant() });
    resolveFetch(['old']);
    await flush(300_000);
    expect(MockSocket.instances).toHaveLength(0);
    expect(queryFn).toHaveBeenCalledTimes(1);
    expect(jest.getTimerCount()).toBe(0);
  });
});

function Harness({ active, foreground }: { active: boolean; foreground: boolean }) {
  useCarouselRealtime(active, foreground);
  return null;
}
function tree(active = true, foreground = true) {
  return <QueryClientProvider client={client}><Harness active={active} foreground={foreground} /></QueryClientProvider>;
}

describe('hook lifecycle gating', () => {
  it('stops synchronously on offline, resumes/refetches online, and unsubscribes on unmount/sign-in', async () => {
    await act(async () => { renderer = create(tree()); await flush(); });
    const ws = latest();
    await act(async () => { onlineManager.setOnline(false); await flush(); });
    expect(ws.close).toHaveBeenCalledTimes(1);
    await act(async () => { await flush(300_000); });
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    await act(async () => { onlineManager.setOnline(true); await flush(); });
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
    expect(queryFn).toHaveBeenCalledTimes(2);
    const second = latest();
    act(() => renderer!.unmount()); renderer = undefined;
    expect(second.close).toHaveBeenCalledTimes(1);
    onlineManager.setOnline(false); onlineManager.setOnline(true);
    await flush(300_000);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
    expect(jest.getTimerCount()).toBe(0);
  });

  it.each(['keyboard', 'background'] as const)('does no socket work while paused by %s and refetches on resume', async reason => {
    const paused = () => tree(reason !== 'keyboard', reason !== 'background');
    await act(async () => { renderer = create(paused()); await flush(); });
    await flush(120_000);
    expect(loginCarouselRealtime).not.toHaveBeenCalled();
    expect(queryFn).not.toHaveBeenCalled();
    await act(async () => { renderer!.update(tree()); await flush(); });
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    const ws = latest();
    await act(async () => { renderer!.update(paused()); await flush(); });
    expect(ws.close).toHaveBeenCalledTimes(1);
    onlineManager.setOnline(false); onlineManager.setOnline(true);
    await flush(120_000);
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
    expect(jest.getTimerCount()).toBe(0);
    await act(async () => { renderer!.update(tree()); await flush(); });
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(2);
    expect(queryFn).toHaveBeenCalledTimes(2);
  });

  it('does not fetch grants or create sockets when mounted offline', async () => {
    onlineManager.setOnline(false);
    await act(async () => { renderer = create(tree()); await flush(120_000); });
    expect(loginCarouselRealtime).not.toHaveBeenCalled();
    expect(MockSocket.instances).toHaveLength(0);
    await act(async () => { onlineManager.setOnline(true); await flush(); });
    expect(loginCarouselRealtime).toHaveBeenCalledTimes(1);
  });
});
