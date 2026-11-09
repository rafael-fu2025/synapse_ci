import { QueryClient } from '@tanstack/react-query';
import { bindApiClient, type ApiClient, type ApiRuntime } from '../../../api/client';
import { loginCarouselRealtime, LoginCarouselRealtimeGrant } from '../../../api/endpoints/auth';
import {
  RealtimeTarget,
  SocketEngineOptions,
  startSocketRealtime,
  validateGrantEnvelope,
} from '../../../lib/realtime/socketEngine';

const QUERY: RealtimeTarget = { key: ['auth', 'carousel'], exact: true };
const RECONCILE_MS = 120_000;

type EnabledGrant = Extract<LoginCarouselRealtimeGrant, { enabled: true }>;

/** The API may use a separate socket host, but credentials must only travel in a frame. */
export function validateCarouselGrant(value: unknown, runtime?: ApiRuntime): EnabledGrant | null {
  return validateGrantEnvelope(value, /^public\.tenant\.[1-9]\d*\.login-carousel$/, runtime);
}

const engines = new WeakMap<QueryClient, Map<string, SocketEngineOptions>>();

/** Retry-After survives pauses within one server/account, never a replacement runtime. */
export function startCarouselRealtime(client: QueryClient, binding: ApiClient = bindApiClient()): () => void {
  if (!binding.isCurrent()) { return () => undefined; }
  let perClient = engines.get(client);
  if (!perClient) { perClient = new Map(); engines.set(client, perClient); }
  const key = `${binding.runtime.id}:${binding.sessionVersion}`;
  let options = perClient.get(key);
  if (!options) {
    options = {
      isCurrent: binding.isCurrent,
      reconcileMs: RECONCILE_MS,
      fetchGrant: () => loginCarouselRealtime(binding),
      validateGrant: value => binding.isCurrent() ? validateCarouselGrant(value, binding.runtime) : null,
      targetsFor: (event, data) => event === 'login.carousel.changed' && Array.isArray(data) && data.length === 0 ? [QUERY] : [],
      reconcileTargets: () => [QUERY],
    };
    perClient.set(key, options);
  }
  return startSocketRealtime(client, options);
}
