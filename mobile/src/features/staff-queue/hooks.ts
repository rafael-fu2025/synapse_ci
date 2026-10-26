import { useQuery } from '@tanstack/react-query';
import { poll } from '../../api/poll';
import * as staffQueueApi from '../../api/endpoints/staffQueue';
import { queryKeys } from '../../api/queryKeys';

/** Live read-only board; same 15s cadence as the web dashboard counts. */
export function useStaffQueueSummary(unit: string) {
  return useQuery({
    queryKey: queryKeys.staffQueue(unit),
    queryFn: () => staffQueueApi.summary(unit === 'all' ? undefined : (unit as 'clinic' | 'guidance')),
    refetchInterval: poll.queueMs,
  });
}
