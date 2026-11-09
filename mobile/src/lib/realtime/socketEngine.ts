import { QueryClient, matchQuery, type Query } from '@tanstack/react-query';
import { ApiError, type ApiRuntime } from '../../api/client';
import { config } from '../config';

const TIMEOUT_MS = 10_000;
const MAX_RETRY_MS = 30_000;
const MAX_TIMER_MS = 2_147_483_647;
export const MAX_TICKET_TTL_MS = 300_000;

export type RealtimeGrant = {
  enabled: true;
  url: string;
  origin: string;
  channel: string;
  ticket: string;
  expires_at: number;
};

/** Query keys one hint should refresh; exact:true matches the key exactly, false as a prefix. */
export type RealtimeTarget = { key: readonly unknown[]; exact: boolean };

export type SocketEngineOptions = {
  /** Reject retired runtime/account work before React finishes effect cleanup. */
  isCurrent?: () => boolean;
  reconcileMs: number;
  fetchGrant: () => Promise<unknown>;
  validateGrant: (value: unknown) => RealtimeGrant | null;
  /** Empty return ignores the hint entirely (unknown events, wrong payload shapes). */
  targetsFor: (event: string, data: unknown) => RealtimeTarget[];
  /** Reconciled on lifetime start and every reconcileMs so missed hints self-repair. */
  reconcileTargets: () => RealtimeTarget[];
};

export type SocketRealtimeObserver = {
  /** True only after server authentication; false before any close, renewal, or stop. */
  onReadyChange: (ready: boolean) => void;
};

// Keep server throttling across keyboard/foreground/offline lifetime changes without
// keeping any transport timer alive while paused. Keyed per client, then per engine
// description (which must stay stable across lifetimes) so deadlines neither leak
// between engines sharing one client nor between different clients.
const ticketRetryAfter = new WeakMap<QueryClient, WeakMap<SocketEngineOptions, number>>();

function retryAfterFor(client: QueryClient, options: SocketEngineOptions): number {
  return ticketRetryAfter.get(client)?.get(options) ?? 0;
}

function setRetryAfter(client: QueryClient, options: SocketEngineOptions, deadline: number): void {
  let perClient = ticketRetryAfter.get(client);
  if (!perClient) {
    perClient = new WeakMap();
    ticketRetryAfter.set(client, perClient);
  }
  perClient.set(options, deadline);
}

function clearRetryAfter(client: QueryClient, options: SocketEngineOptions): void {
  ticketRetryAfter.get(client)?.delete(options);
}

/** The API may use a separate socket host, but credentials must only travel in a frame. */
export function validateGrantEnvelope(value: unknown, channel: RegExp, runtime?: ApiRuntime): RealtimeGrant | null {
  if (!value || typeof value !== 'object') { return null; }
  const grant = value as RealtimeGrant;
  try {
    if (runtime && !runtime.active) { return null; }
    const api = new URL(runtime?.apiBaseUrl ?? config.apiBaseUrl);
    if (grant.enabled !== true || grant.origin !== api.origin ||
        typeof grant.url !== 'string' || !/^wss?:\/\//.test(grant.url) || /[\s\\?#]/.test(grant.url) ||
        typeof grant.channel !== 'string' || !channel.test(grant.channel) ||
        typeof grant.ticket !== 'string' || !grant.ticket || grant.ticket.length > 8192 ||
        !Number.isSafeInteger(grant.expires_at) || grant.expires_at * 1000 <= Date.now() + 1000 ||
        grant.expires_at * 1000 > Date.now() + MAX_TICKET_TTL_MS) {
      return null;
    }
    const url = new URL(grant.url);
    if (!url.hostname || url.username || url.password || url.search || url.hash ||
        !['ws:', 'wss:'].includes(url.protocol) || (api.protocol === 'https:' && url.protocol !== 'wss:')) {
      return null;
    }
    if (runtime?.lanTest && (url.protocol !== 'ws:' || url.hostname !== api.hostname || url.port !== '8081' || url.pathname !== '/')) {
      return null;
    }
    return grant;
  } catch {
    return null;
  }
}

function targetId(target: RealtimeTarget): string {
  return JSON.stringify(target.key) + (target.exact ? '' : '+prefix');
}

/** One active/foreground/online lifetime. Stale grants and callbacks cannot restart it. */
export function startSocketRealtime(client: QueryClient, options: SocketEngineOptions, observer?: SocketRealtimeObserver): () => void {
  let stopped = false;
  const isCurrent = () => !stopped && (options.isCurrent?.() ?? true);
  let reportedReady = false;
  const reportReady = (ready: boolean) => {
    if (reportedReady === ready) { return; }
    reportedReady = ready;
    observer?.onReadyChange(ready);
  };
  let generation = 0;
  let socket: WebSocket | null = null;
  let connecting = false;
  let failures = 0;
  let readyAt: number | null = null;
  let deadline: ReturnType<typeof setTimeout> | undefined;
  let retry: ReturnType<typeof setTimeout> | undefined;
  let renewal: ReturnType<typeof setTimeout> | undefined;
  let heartbeat: ReturnType<typeof setInterval> | undefined;
  let pong: ReturnType<typeof setTimeout> | undefined;
  let refreshTimer: ReturnType<typeof setTimeout> | undefined;
  // Hint bursts deduplicate targets; overlapping prefixes deduplicate actual queries.
  const pending = new Map<string, RealtimeTarget>();
  const trailing = new Set<Query>();
  const refreshing = new Map<Query, { again: boolean }>();

  async function refreshQuery(query: Query) {
    if (!isCurrent() || client.getQueryCache().get(query.queryHash) !== query) { return; }
    const running = refreshing.get(query);
    if (running) { running.again = true; return; }
    const active = query.isActive();
    const inFlight = query.state.fetchStatus !== 'idle';
    if (!active && !inFlight) { return; }
    // An initial/manual read may predate this hint. Reuse it without cancellation,
    // then make one trailing read. Other queries never wait for this one.
    const work = { again: inFlight };
    refreshing.set(query, work);
    try {
      if (active) {
        await client.refetchQueries({ predicate: candidate => candidate === query, type: 'active' }, { cancelRefetch: false });
      }
      // Disabled in-flight queries must still become stale after an old response.
      // RefetchQueries also returns early for a paused request: wait for physical
      // settlement without issuing hidden reads or spinning refresh timers.
      if (!active || query.state.fetchStatus === 'paused') { await query.promise; }
    } catch {
      // Keep the last good data; later hints and reconciliation can retry.
    } finally {
      refreshing.delete(query);
      if (isCurrent() && work.again && client.getQueryCache().get(query.queryHash) === query) {
        trailing.add(query);
        refresh();
      }
    }
  }

  function drain() {
    refreshTimer = undefined;
    if (!isCurrent()) { return; }
    const targets = [...pending.values()];
    pending.clear();
    const queries = new Set(trailing);
    trailing.clear();
    for (const query of client.getQueryCache().getAll()) {
      if (targets.some(target => matchQuery({ queryKey: target.key, exact: target.exact }, query))) {
        queries.add(query);
      }
    }
    // Invalidate once per concrete query, including disabled signal observers
    // such as Team Chat. Inactive queries become stale but are not fetched.
    client.invalidateQueries({ predicate: query => queries.has(query), refetchType: 'none' }).catch(() => undefined);
    for (const query of queries) {
      if (!isCurrent()) { return; }
      refreshQuery(query);
    }
  }

  function refresh(immediate = false) {
    if (!isCurrent()) { return; }
    if (immediate) {
      clearTimeout(refreshTimer);
      drain();
    } else if (refreshTimer === undefined) {
      refreshTimer = setTimeout(drain, 100);
    }
  }

  function note(event: string, data: unknown) {
    const targets = options.targetsFor(event, data);
    for (const target of targets) {
      pending.set(targetId(target), target);
    }
    if (targets.length > 0) { refresh(); }
  }

  function queueReconcileTargets() {
    for (const target of options.reconcileTargets()) {
      pending.set(targetId(target), target);
    }
  }

  function clearConnection() {
    reportReady(false);
    generation += 1;
    connecting = false;
    clearTimeout(deadline);
    clearTimeout(retry);
    clearTimeout(renewal);
    clearTimeout(pong);
    clearInterval(heartbeat);
    deadline = retry = renewal = pong = heartbeat = undefined;
    const old = socket;
    socket = null;
    if (old) {
      old.onopen = old.onmessage = old.onerror = old.onclose = null;
      try { old.close(); } catch { /* Native transport may already be gone. */ }
    }
  }

  function scheduleRetry(delay: number) {
    retry = setTimeout(() => {
      retry = undefined;
      connect();
    }, Math.min(MAX_TIMER_MS, delay));
  }

  function failed() {
    if (!isCurrent()) { return; }
    // A rapid ready/close loop must still back off.
    if (readyAt !== null && Date.now() - readyAt >= MAX_RETRY_MS) { failures = 0; }
    readyAt = null;
    clearConnection();
    const ceiling = Math.min(MAX_RETRY_MS, 1000 * 2 ** Math.min(failures++, 5));
    scheduleRetry(Math.round(ceiling * (0.5 + Math.random() * 0.5)));
  }

  async function connect() {
    if (!isCurrent() || connecting || socket || retry !== undefined || typeof WebSocket === 'undefined') { return; }
    const wait = retryAfterFor(client, options) - Date.now();
    if (wait > 0) { scheduleRetry(wait); return; }
    clearRetryAfter(client, options);
    connecting = true;
    const version = ++generation;
    const current = () => isCurrent() && version === generation;
    try {
      const payload = await options.fetchGrant() as { data?: { enabled?: unknown } } | null | undefined;
      if (!current()) { return; }
      connecting = false;
      if (payload?.data?.enabled === false) { return; }
      const grant = options.validateGrant(payload?.data);
      if (!grant) { failed(); return; }
      const ws = new WebSocket(grant.url, undefined, { headers: { Origin: grant.origin } });
      socket = ws;
      let ticket: string | null = grant.ticket;
      let ready = false;
      deadline = setTimeout(() => { if (current()) { failed(); } }, TIMEOUT_MS);
      const ttl = grant.expires_at * 1000 - Date.now();
      renewal = setTimeout(() => {
        if (!current()) { return; }
        clearConnection();
        readyAt = null;
        failures = 0;
        connect();
      }, ttl - Math.min(10_000, ttl / 5));
      ws.onopen = () => {
        if (!current() || socket !== ws) { return; }
        clearTimeout(deadline);
        deadline = setTimeout(() => { if (current()) { failed(); } }, TIMEOUT_MS);
        try {
          ws.send(JSON.stringify({ type: 'authenticate', ticket }));
          ticket = null;
        } catch { failed(); }
      };
      ws.onmessage = event => {
        if (!current() || socket !== ws || typeof event.data !== 'string' || event.data.length > 65_536) { return; }
        let message;
        try { message = JSON.parse(event.data); } catch { return; }
        if (!message || typeof message !== 'object' || Array.isArray(message)) { return; }
        if (message.type === 'ready' && !ready && ticket === null) {
          ready = true;
          readyAt = Date.now();
          reportReady(true);
          clearTimeout(deadline);
          queueReconcileTargets();
          refresh(true);
          heartbeat = setInterval(() => {
            if (!current()) { return; }
            try {
              ws.send(JSON.stringify({ type: 'ping' }));
              pong = setTimeout(() => { if (current()) { failed(); } }, TIMEOUT_MS);
            } catch { failed(); }
          }, 20_000);
        } else if (ready && message.type === 'pong') {
          clearTimeout(pong);
          pong = undefined;
        } else if (ready && message.type === 'event' && message.channel === grant.channel) {
          note(message.event, message.data);
        }
      };
      ws.onerror = ws.onclose = () => { if (current() && socket === ws) { failed(); } };
    } catch (error) {
      if (!current()) { return; }
      if (error instanceof ApiError && [401, 403, 429].includes(error.status)) {
        clearConnection();
        readyAt = null;
        if (error.status === 429) {
          const seconds = error.retryAfterSeconds;
          const now = Date.now();
          const delay = seconds !== null && Number.isSafeInteger(seconds) && seconds >= 0 &&
            seconds <= Math.floor((Number.MAX_SAFE_INTEGER - now) / 1000)
            ? Math.max(1000, seconds * 1000) : 60_000;
          setRetryAfter(client, options, now + delay);
          scheduleRetry(delay);
        }
        // Unauthorized/forbidden grants wait for reconciliation or a new active lifetime.
        return;
      }
      failed();
    }
  }

  // Runs in connected and fallback modes: repairs missed events without 30-second polling.
  const reconciliation = setInterval(() => {
    queueReconcileTargets();
    refresh(true);
    connect();
  }, options.reconcileMs);
  queueReconcileTargets();
  refresh(true);
  connect();
  return () => {
    stopped = true;
    clearConnection();
    clearInterval(reconciliation);
    clearTimeout(refreshTimer);
    pending.clear();
    trailing.clear();
    refreshing.clear();
  };
}
