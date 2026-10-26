import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as surveysApi from '../../api/endpoints/surveys';
import { queryKeys } from '../../api/queryKeys';

export function useSurveys(filters: { status: string }) {
  return useQuery({
    queryKey: queryKeys.surveys({ ...filters, q: '' }),
    queryFn: () => surveysApi.listing(filters),
  });
}

export function useSurveyForm(id: number, enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.survey(id),
    queryFn: () => surveysApi.form(id),
    enabled,
  });
}

export function useSubmitSurvey() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, answers }: { id: number; answers: unknown[] }) => surveysApi.submit(id, answers),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['surveys'] }).catch(() => undefined);
    },
  });
}
