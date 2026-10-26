import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as appointmentsApi from '../../api/endpoints/appointments';
import { queryKeys } from '../../api/queryKeys';

export function useAppointments(filters: appointmentsApi.AppointmentFilters) {
  return useQuery({
    queryKey: queryKeys.appointments(filters),
    queryFn: () => appointmentsApi.list(filters),
  });
}

export function useAppointment(id: number) {
  return useQuery({
    queryKey: queryKeys.appointment(id),
    queryFn: () => appointmentsApi.detail(id),
  });
}

export function useCreateAppointment() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: appointmentsApi.BookingInput) => appointmentsApi.create(input),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['appointments'] }).catch(() => undefined);
    },
  });
}

export function useCancelAppointment() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => appointmentsApi.cancel(id, reason),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['appointments'] }).catch(() => undefined);
    },
  });
}
