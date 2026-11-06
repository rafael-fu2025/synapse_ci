import { ApiError, NetworkError, SessionChangedError } from '../../api/client';

export type TeamCalendarErrorKind =
  | 'offline'
  | 'unauthorized'
  | 'forbidden'
  | 'stale'
  | 'idempotency'
  | 'conflict'
  | 'missing'
  | 'validation'
  | 'unavailable'
  | 'other';

export function teamCalendarErrorKind(error: unknown): TeamCalendarErrorKind {
  if (error instanceof NetworkError) { return 'offline'; }
  if (error instanceof SessionChangedError) { return 'unauthorized'; }
  if (error instanceof ApiError) {
    if (error.status === 401) { return 'unauthorized'; }
    if (error.status === 403) { return 'forbidden'; }
    if (error.status === 409 && error.code === 'idempotency_conflict') { return 'idempotency'; }
    if (error.status === 409 && error.code === 'stale_version') { return 'stale'; }
    if (error.status === 409) { return 'conflict'; }
    if (error.status === 404) { return 'missing'; }
    if (error.status === 422) { return 'validation'; }
    if (error.status === 503) { return 'unavailable'; }
  }
  return 'other';
}

export function teamCalendarErrorMessage(error: unknown, fallback: string): string {
  const kind = teamCalendarErrorKind(error);
  if (kind === 'offline') { return 'You appear to be offline. Reconnect and try again.'; }
  if (kind === 'unauthorized') { return 'Your session ended. Sign in again to continue.'; }
  if (kind === 'forbidden') { return 'Your Team Workspace access changed. Return to the workspace and refresh.'; }
  if (kind === 'stale') { return 'This event changed on another device. Review the latest version before trying again.'; }
  if (kind === 'idempotency') { return 'This retry no longer matches the original create request. Review the event before sending again.'; }
  if (kind === 'conflict') { return 'This event can no longer be changed. Reload the latest event details.'; }
  if (kind === 'missing') { return 'This event is no longer available.'; }
  if (kind === 'unavailable') { return 'Team Calendar is temporarily unavailable. Try again later.'; }
  if (error instanceof ApiError && error.message) { return error.message; }
  return fallback;
}

export function isAmbiguousCreateError(error: unknown): boolean {
  return error instanceof NetworkError;
}
