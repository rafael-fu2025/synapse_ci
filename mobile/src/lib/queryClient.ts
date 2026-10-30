import { QueryClient } from '@tanstack/react-query';
import { ApiError, SessionChangedError } from '../api/client';

/**
 * Single app-wide cache. It lives outside React so sign-out and session expiry can
 * wipe it: health, queue and appointment data must never outlive the session that fetched it.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Show cached data immediately on return visits; live queue/notification queries
      // have their own shorter, focus-aware polling cadence.
      staleTime: 30_000,
      gcTime: 10 * 60_000,
      retry: (count, error) => {
        if (
          error instanceof SessionChangedError ||
          (error instanceof ApiError && error.status < 500)
        ) {
          return false;
        }
        return count < 1;
      },
      refetchOnWindowFocus: true,
    },
    mutations: { retry: false },
  },
});
