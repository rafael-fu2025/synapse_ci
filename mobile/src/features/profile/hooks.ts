import { useQuery } from '@tanstack/react-query';
import { healthSummary } from '../../api/endpoints/health';
import { queryKeys } from '../../api/queryKeys';

/** Identity and health profile that feed the profile card on Profile and Health profile. */
export function useHealthSummary() {
  return useQuery({
    queryKey: queryKeys.healthSummary,
    queryFn: healthSummary,
  });
}
