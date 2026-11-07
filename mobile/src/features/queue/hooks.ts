import { useQuery } from '@tanstack/react-query';
import { poll } from '../../api/poll';
import * as queueApi from '../../api/endpoints/queue';
import { queryKeys } from '../../api/queryKeys';
import { useRealtimePoll } from '../../lib/useRealtimePoll';

/** Live personal tickets; socket hints take priority over the 15s polling fallback. */
export function useQueueSnapshot() {
  const refetchInterval = useRealtimePoll(poll.queueMs);
  return useQuery({
    queryKey: queryKeys.queue,
    queryFn: () => queueApi.snapshot(),
    refetchInterval,
  });
}
