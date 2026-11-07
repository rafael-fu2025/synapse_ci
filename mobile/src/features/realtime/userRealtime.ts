import { QueryClient } from '@tanstack/react-query';
import { userRealtimeGrant } from '../../api/endpoints/realtime';
import { queryKeys } from '../../api/queryKeys';
import { teamTaskResourceKeys } from '../workspace/invalidateTeamResources';
import { ownUserRealtime } from '../../lib/realtime/transportState';
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

// Hints carry no record content: the socket says "stale", authorized HTTP says "what".
const EMPTY_HINT = (data: unknown): boolean => Array.isArray(data) && data.length === 0;
const TEAM_UNITS = ['clinic', 'guidance', 'facilities'] as const;

function targetsFor(identity: RealtimeIdentity, event: string, data: unknown): RealtimeTarget[] {
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
    case 'team.tasks.changed':
    case 'team.calendar.changed':
    case 'team.chat.changed': {
      const unit = data !== null && typeof data === 'object' ? (data as { unit?: unknown }).unit : undefined;
      if (typeof unit !== 'string' || !TEAM_UNITS.includes(unit as (typeof TEAM_UNITS)[number])) {
        return [];
      }
      if (event === 'team.tasks.changed') {
        return teamTaskResourceKeys(identity, unit as (typeof TEAM_UNITS)[number]).map(key => ({ key, exact: false }));
      }
      const resource = event === 'team.calendar.changed' ? 'team-calendar' : 'team-chat';
      return [{ key: [resource, identity.tenantId, identity.userId, unit], exact: false }];
    }
    default:
      return [];
  }
}

function reconcileTargets(identity: RealtimeIdentity): RealtimeTarget[] {
  return [
    { key: queryKeys.notifications, exact: false },
    { key: queryKeys.queue, exact: true },
    { key: ['appointments'], exact: false },
    { key: ['team', identity.tenantId, identity.userId], exact: false },
    { key: ['team-calendar', identity.tenantId, identity.userId], exact: false },
    { key: ['team-chat', identity.tenantId, identity.userId], exact: false },
    // Staff fanout is bounded too; a healthy socket cannot guarantee every hint arrives.
    { key: queryKeys.staffQueue('clinic'), exact: true },
    { key: queryKeys.staffQueue('guidance'), exact: true },
    { key: queryKeys.staffQueue('all'), exact: true },
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
      targetsFor: (event, data) => targetsFor(identity, event, data),
      reconcileTargets: () => reconcileTargets(identity),
    };
    engines.set(engineKey, options);
  }
  const transport = ownUserRealtime(client, identity);
  const stop = startSocketRealtime(client, options, { onReadyChange: transport.setReady });
  return () => {
    transport.release();
    stop();
  };
}

