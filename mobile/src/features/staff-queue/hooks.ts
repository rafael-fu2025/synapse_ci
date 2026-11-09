import { useQuery } from '@tanstack/react-query';
import { bindApiQueryResult, useApiClient } from '../../api/runtime';
import { poll } from '../../api/poll';
import * as staffQueueApi from '../../api/endpoints/staffQueue';
import { queryKeys } from '../../api/queryKeys';
import { useRealtimePoll } from '../../lib/useRealtimePoll';

/** Live read-only board; socket hints take priority over the 15s fallback. */
export function useStaffQueueSummary() {
  const apiClient = useApiClient();
  const refetchInterval = useRealtimePoll(poll.queueMs);
  return bindApiQueryResult(apiClient, useQuery({
    queryKey: queryKeys.staffQueue('all'),
    queryFn: () => staffQueueApi.summary(undefined, apiClient),
    refetchInterval,
  }));
}
