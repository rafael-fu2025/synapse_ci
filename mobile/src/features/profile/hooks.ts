import { useQuery } from '@tanstack/react-query';
import { bindApiQueryResult, useApiClient } from '../../api/runtime';
import { healthSummary } from '../../api/endpoints/health';
import { queryKeys } from '../../api/queryKeys';

/** Identity and health profile that feed the profile card on Profile and Health profile. */
export function useHealthSummary() {
  const apiClient = useApiClient();
  return bindApiQueryResult(apiClient, useQuery({
    queryKey: queryKeys.healthSummary,
    queryFn: () => healthSummary(apiClient),
  }));
}
