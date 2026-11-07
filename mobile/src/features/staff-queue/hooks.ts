import { useQuery } from '@tanstack/react-query';
import { poll } from '../../api/poll';
import * as staffQueueApi from '../../api/endpoints/staffQueue';
import { queryKeys } from '../../api/queryKeys';
import { useRealtimePoll } from '../../lib/useRealtimePoll';

/** Live read-only board; socket hints take priority over the 15s fallback. */
export function useStaffQueueSummary() {
  const refetchInterval = useRealtimePoll(poll.queueMs);
  return useQuery({
    queryKey: queryKeys.staffQueue('all'),
    queryFn: () => staffQueueApi.summary(),
    refetchInterval,
  });
}
