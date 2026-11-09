import { useQuery, useQueryClient } from '@tanstack/react-query';
import { bindApiQueryResult, useApiClient, useApiMutation } from '../../api/runtime';
import * as surveysApi from '../../api/endpoints/surveys';
import { queryKeys } from '../../api/queryKeys';

export function useSurveys(filters: { status: string }) {
  const apiClient = useApiClient();
  return bindApiQueryResult(apiClient, useQuery({
    queryKey: queryKeys.surveys({ ...filters, q: '' }),
    queryFn: () => surveysApi.listing(filters, apiClient),
  }));
}

export function useSurveyForm(id: number, enabled: boolean) {
  const apiClient = useApiClient();
  return bindApiQueryResult(apiClient, useQuery({
    queryKey: queryKeys.survey(id),
    queryFn: () => surveysApi.form(id, apiClient),
    enabled,
  }));
}

export function useSubmitSurvey() {
  const client = useQueryClient();
  return useApiMutation({
    mutationFn: ({ id, answers }: { id: number; answers: unknown[] }, apiClient) => surveysApi.submit(id, answers, apiClient),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['surveys'] }).catch(() => undefined);
    },
  });
}
