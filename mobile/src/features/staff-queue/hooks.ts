import { useQuery } from '@tanstack/react-query';
import { poll } from '../../api/poll';
import * as staffQueueApi from '../../api/endpoints/staffQueue';
import { queryKeys } from '../../api/queryKeys';
import { useFocusPoll } from '../../lib/useFocusPoll';

/** Live read-only board; same 15s cadence as the web dashboard counts. */
export function useStaffQueueSummary() {
  const refetchInterval = useFocusPoll(poll.queueMs);
  return useQuery({
    queryKey: queryKeys.staffQueue('all'),
    queryFn: () => staffQueueApi.summary(),
    refetchInterval,
  });
}
