import type { QueryClient } from '@tanstack/react-query';

export type RealtimeOwner = { tenantId: number; userId: number };

type TransportState = {
  identity: string | null;
  lease: object | null;
  ready: boolean;
  listeners: Set<() => void>;
};

// Transport readiness belongs to a QueryClient/session, never to a persisted preference.
const states = new WeakMap<QueryClient, TransportState>();
const keyFor = (identity: RealtimeOwner) => `${identity.tenantId}:${identity.userId}`;

function stateFor(client: QueryClient): TransportState {
  let state = states.get(client);
  if (!state) {
    state = { identity: null, lease: null, ready: false, listeners: new Set() };
    states.set(client, state);
  }
  return state;
}

function publish(state: TransportState) {
  for (const listener of state.listeners) { listener(); }
}

export function isUserRealtimeReady(client: QueryClient, identity: RealtimeOwner | null): boolean {
  if (!identity || identity.tenantId < 1 || identity.userId < 1) { return false; }
  const state = states.get(client);
  return state?.identity === keyFor(identity) && state.ready;
}

export function subscribeUserRealtime(client: QueryClient, listener: () => void): () => void {
  const state = stateFor(client);
  state.listeners.add(listener);
  return () => { state.listeners.delete(listener); };
}

/** A replaced lifetime's late callbacks cannot change the current connection state. */
export function ownUserRealtime(client: QueryClient, identity: RealtimeOwner) {
  const state = stateFor(client);
  const lease = {};
  const changed = state.identity !== keyFor(identity) || state.ready;
  state.identity = keyFor(identity);
  state.lease = lease;
  state.ready = false;
  if (changed) { publish(state); }
  return {
    setReady(ready: boolean) {
      if (state.lease !== lease || state.ready === ready) { return; }
      state.ready = ready;
      publish(state);
    },
    release() {
      if (state.lease !== lease) { return; }
      const wasReady = state.ready;
      state.identity = null;
      state.lease = null;
      state.ready = false;
      if (wasReady) { publish(state); }
    },
  };
}
