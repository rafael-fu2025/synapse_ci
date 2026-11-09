import { useLayoutEffect, useMemo, useRef } from 'react';
import { AppState } from 'react-native';
import { focusManager, onlineManager, useQuery, useQueryClient, type RefetchOptions, type UseQueryResult } from '@tanstack/react-query';
import { useApiClient, useApiMutation } from '../../api/runtime';
import { poll } from '../../api/poll';
import { useActiveScreen } from '../../lib/useActiveScreen';
import { useRealtimeReady } from '../../lib/useRealtimeReady';
import * as appointmentsApi from '../../api/endpoints/appointments';
import { queryKeys } from '../../api/queryKeys';

export function useAppointments(filters: appointmentsApi.AppointmentFilters) {
  const apiClient = useApiClient();
  const active = useActiveScreen();
  const realtime = useRealtimeReady();
  const query = useQuery({
    queryKey: queryKeys.appointments(filters),
    queryFn: () => appointmentsApi.list(filters, apiClient),
    enabled: active,
    refetchInterval: active && !realtime ? poll.appointmentsMs : false,
  });
  return useEligibleAppointmentQuery(query, active, JSON.stringify([filters.service, filters.status, filters.sort]));
}

export function useAppointment(id: number) {
  const apiClient = useApiClient();
  const active = useActiveScreen(Number.isSafeInteger(id) && id > 0);
  const realtime = useRealtimeReady();
  const query = useQuery({
    queryKey: queryKeys.appointment(id),
    queryFn: () => appointmentsApi.detail(id, apiClient),
    enabled: active,
    refetchInterval: active && !realtime ? poll.appointmentsMs : false,
  });
  return useEligibleAppointmentQuery(query, active, id);
}

function useEligibleAppointmentQuery<T>(query: UseQueryResult<T>, active: boolean, key: string | number) {
  const apiClient = useApiClient();
  const client = useQueryClient();
  const lifetime = useMemo(() => ({ alive: false, client, key, apiClient }), [client, key, apiClient]);
  const latest = useRef({ query, active, lifetime });
  latest.current = { query, active, lifetime };
  useLayoutEffect(() => {
    lifetime.alive = true;
    return () => { lifetime.alive = false; };
  }, [lifetime]);
  const refetch = useMemo(() => (options?: RefetchOptions) => {
    const current = latest.current;
    // Observer refetch ignores enabled; retired handlers must not revive a read.
    if (!apiClient.isCurrent() || !lifetime.alive || current.lifetime !== lifetime || !current.active ||
        AppState.currentState !== 'active' || !focusManager.isFocused() || !onlineManager.isOnline()) {
      return Promise.resolve(current.query);
    }
    return current.query.refetch(options);
  }, [lifetime, apiClient]);
  return { ...query, eligible: active, refetch };
}

export function useCreateAppointment() {
  const client = useQueryClient();
  return useApiMutation({
    mutationFn: (input: appointmentsApi.BookingInput, apiClient) => appointmentsApi.create(input, apiClient),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['appointments'] }).catch(() => undefined);
    },
  });
}

export function useCancelAppointment() {
  const client = useQueryClient();
  return useApiMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }, apiClient) => appointmentsApi.cancel(id, reason, apiClient),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['appointments'] }).catch(() => undefined);
    },
  });
}
