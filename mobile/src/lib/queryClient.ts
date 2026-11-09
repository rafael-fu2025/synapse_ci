import { QueryClient } from '@tanstack/react-query';
import { ApiError, SessionChangedError } from '../api/client';

/** Each server activation owns its cache, including callbacks still settling after teardown. */
export function createQueryClient(): QueryClient {
  return new QueryClient({
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
}

// Standalone fixtures/legacy consumers remain supported; App always owns a fresh cache.
export const queryClient = createQueryClient();
