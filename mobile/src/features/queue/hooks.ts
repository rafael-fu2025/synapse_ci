import { useQuery } from '@tanstack/react-query';
import { poll } from '../../api/poll';
import * as queueApi from '../../api/endpoints/queue';
import { queryKeys } from '../../api/queryKeys';
import { useFocusPoll } from '../../lib/useFocusPoll';

/** Live personal tickets; 15s polling is the same cadence the web cards use. */
export function useQueueSnapshot() {
  const refetchInterval = useFocusPoll(poll.queueMs);
  return useQuery({
    queryKey: queryKeys.queue,
    queryFn: () => queueApi.snapshot(),
    refetchInterval,
  });
}
