import { ApiError, NetworkError } from '../../api/client';

export function appointmentErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    return [error.message, ...Object.values(error.fields).flat()].filter(Boolean).join('\n');
  }
  return error instanceof NetworkError ? error.message : fallback;
}
