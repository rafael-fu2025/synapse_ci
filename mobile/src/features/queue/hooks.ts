import { useQuery } from '@tanstack/react-query';
import { bindApiQueryResult, useApiClient } from '../../api/runtime';
import { poll } from '../../api/poll';
import * as queueApi from '../../api/endpoints/queue';
import { queryKeys } from '../../api/queryKeys';
import { useActiveScreen } from '../../lib/useActiveScreen';
import { useRealtimeReady } from '../../lib/useRealtimeReady';

/** Personal hints replace polling except while waiting on peers' queue progress. */
export function useQueueSnapshot() {
  const apiClient = useApiClient();
  const active = useActiveScreen();
  const realtime = useRealtimeReady();
  return bindApiQueryResult(apiClient, useQuery({
    queryKey: queryKeys.queue,
    queryFn: () => queueApi.snapshot(apiClient),
    enabled: active,
    // Peers' transitions do not hint this user's channel, but change position/ETA.
    refetchInterval: query => active && (!realtime || query.state.data?.data.tickets.some(ticket => ticket.status === 'waiting'))
      ? poll.queueMs
      : false,
  }));
}
