import { useQuery } from '@tanstack/react-query';
import { poll } from '../../api/poll';
import * as queueApi from '../../api/endpoints/queue';
import { queryKeys } from '../../api/queryKeys';

/** Live personal tickets; 15s polling is the same cadence the web cards use. */
export function useQueueSnapshot() {
  return useQuery({
    queryKey: queryKeys.queue,
    queryFn: () => queueApi.snapshot(),
    refetchInterval: poll.queueMs,
  });
}
