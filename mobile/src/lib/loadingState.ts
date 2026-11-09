export type LoadingState = 'content' | 'loading' | 'error' | 'waiting';

/** A successful empty response is content; a disabled/paused query is not an empty result. */
export function loadingState(query: {
  data?: unknown;
  isLoading?: boolean;
  isError?: boolean;
  fetchStatus?: 'fetching' | 'paused' | 'idle';
}): LoadingState {
  if (query.data !== undefined) { return 'content'; }
  if (query.fetchStatus === 'fetching' || query.isLoading) { return 'loading'; }
  if (query.isError) { return 'error'; }
  return 'waiting';
}
