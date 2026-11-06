import { ApiError, NetworkError } from '../../api/client';

export type TeamTaskErrorKind = 'offline' | 'forbidden' | 'stale' | 'idempotency' | 'missing' | 'validation' | 'other';

export function teamTaskErrorKind(error: unknown): TeamTaskErrorKind {
  if (error instanceof NetworkError) { return 'offline'; }
  if (error instanceof ApiError) {
    if (error.status === 403) { return 'forbidden'; }
    if (error.status === 409 && error.code === 'idempotency_conflict') { return 'idempotency'; }
    if (error.status === 409) { return 'stale'; }
    if (error.status === 404) { return 'missing'; }
    if (error.status === 422) { return 'validation'; }
  }
  return 'other';
}

export function teamTaskErrorMessage(error: unknown, fallback: string): string {
  const kind = teamTaskErrorKind(error);
  if (kind === 'offline') { return 'You appear to be offline. Reconnect and try again.'; }
  if (kind === 'forbidden') { return 'Your Team Workspace access changed. Return to the workspace and refresh.'; }
  if (kind === 'stale') { return 'This task changed on another device. Review the latest version before trying again.'; }
  if (kind === 'idempotency') { return 'This retry no longer matches the original request. Review the form before sending again.'; }
  if (kind === 'missing') { return 'This task is no longer available.'; }
  if (error instanceof ApiError && error.message) { return error.message; }
  return fallback;
}
