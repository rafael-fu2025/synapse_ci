import { QueryClient } from '@tanstack/react-query';
import { userRealtimeGrant } from '../../api/endpoints/realtime';
import { queryKeys } from '../../api/queryKeys';
import {
  RealtimeGrant,
  RealtimeTarget,
  SocketEngineOptions,
  startSocketRealtime,
  validateGrantEnvelope,
} from '../../lib/realtime/socketEngine';

const RECONCILE_MS = 120_000;

export type RealtimeIdentity = { tenantId: number; userId: number };

const USER_CHANNEL = /^private\.tenant\.[1-9]\d*\.user\.[1-9]\d*$/;

/** The grant must name exactly this signed-in identity's channel; anything else fails closed. */
export function validateUserGrant(value: unknown, identity: RealtimeIdentity): RealtimeGrant | null {
  const grant = validateGrantEnvelope(value, USER_CHANNEL);
  if (!grant || grant.channel !== `private.tenant.${identity.tenantId}.user.${identity.userId}`) {
    return null;
  }
  return grant;
}

// Hints are payload-free: the socket says "stale", the HTTP refetch says "what".
// Events without a mobile surface (team.chat.changed, ai.insights.*) are ignored.
const EMPTY_HINT = (data: unknown): boolean => Array.isArray(data) && data.length === 0;

function targetsFor(event: string, data: unknown): RealtimeTarget[] {
  switch (event) {
    case 'notifications.changed':
      // The bell inbox and every paged history query share the 'notifications' prefix,
      // so the hint must invalidate the prefix rather than one exact key.
      return EMPTY_HINT(data) ? [{ key: queryKeys.notifications, exact: false }] : [];
    case 'portal.queue.changed':
      return EMPTY_HINT(data) ? [{ key: queryKeys.queue, exact: true }] : [];
    case 'portal.appointments.changed':
      // Filters vary the list key; the detail keys share the same prefix.
      return EMPTY_HINT(data) ? [{ key: ['appointments'], exact: false }] : [];
    case 'queue.invalidated': {
      // Staff-board hint {unit, date}: refresh the named unit and aggregate read-only summaries.
      const unit = data !== null && typeof data === 'object' ? (data as { unit?: unknown }).unit : undefined;
      return typeof unit === 'string' && (unit === 'clinic' || unit === 'guidance')
        ? [
          { key: queryKeys.staffQueue(unit), exact: true },
          { key: queryKeys.staffQueue('all'), exact: true },
        ]
        : [];
    }
    default:
      return [];
  }
}

function reconcileTargets(): RealtimeTarget[] {
  return [
    { key: queryKeys.notifications, exact: false },
    { key: queryKeys.queue, exact: true },
    { key: ['appointments'], exact: false },
  ];
}

// One engine description per signed-in identity at module scope: the 429 Retry-After
// deadline must persist across offline/foreground lifetime changes within a session.
const engines = new Map<string, SocketEngineOptions>();

/** One online/foreground lifetime for the signed-in user's private channel. */
export function startUserRealtime(client: QueryClient, identity: RealtimeIdentity): () => void {
  const engineKey = `${identity.tenantId}:${identity.userId}`;
  let options = engines.get(engineKey);
  if (!options) {
    options = {
      reconcileMs: RECONCILE_MS,
      fetchGrant: () => userRealtimeGrant(),
      validateGrant: value => validateUserGrant(value, identity),
      targetsFor,
      reconcileTargets,
    };
    engines.set(engineKey, options);
  }
  return startSocketRealtime(client, options);
}

