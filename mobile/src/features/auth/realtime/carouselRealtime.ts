import { QueryClient } from '@tanstack/react-query';
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
export function validateCarouselGrant(value: unknown): EnabledGrant | null {
  return validateGrantEnvelope(value, /^public\.tenant\.[1-9]\d*\.login-carousel$/);
}

// One stable engine description: the 429 Retry-After deadline must persist across
// keyboard/foreground/offline lifetime changes, so it is created once at module scope.
const CAROUSEL_ENGINE: SocketEngineOptions = {
  reconcileMs: RECONCILE_MS,
  fetchGrant: () => loginCarouselRealtime(),
  validateGrant: value => validateCarouselGrant(value),
  targetsFor: (event, data): RealtimeTarget[] =>
    event === 'login.carousel.changed' && Array.isArray(data) && data.length === 0 ? [QUERY] : [],
  reconcileTargets: () => [QUERY],
};

/** One active/foreground/online lifetime. Stale grants and callbacks cannot restart it. */
export function startCarouselRealtime(client: QueryClient): () => void {
  return startSocketRealtime(client, CAROUSEL_ENGINE);
}

