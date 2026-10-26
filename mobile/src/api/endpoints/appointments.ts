import { request } from '../client';

export interface Appointment {
  id: number;
  unit: 'clinic' | 'guidance';
  status: 'requested' | 'scheduled' | 'confirmed' | 'checked_in' | 'completed' | 'cancelled' | 'no_show';
  type: string;
  date: string;
  starts_at: string;
  ends_at: string;
  reason: string | null;
  cancellation_reason: string | null;
  provider_name: string | null;
  version: number;
}

export interface AppointmentFilters {
  service: 'all' | 'clinic' | 'guidance';
  status: string;
  sort: 'upcoming' | 'date_asc' | 'date_desc';
}

export interface BookingInput {
  unit: 'clinic' | 'guidance';
  date: string;
  preferred_time: string;
  type: string;
  reason?: string;
}

export function list(filters: AppointmentFilters): Promise<{ data: Appointment[] }> {
  const params = new URLSearchParams({ service: filters.service, status: filters.status, sort: filters.sort });
  return request<{ data: Appointment[] }>(`api/v1/appointments?${params.toString()}`);
}

export function detail(id: number): Promise<{ data: Appointment }> {
  return request<{ data: Appointment }>(`api/v1/appointments/${id}`);
}

export function create(input: BookingInput): Promise<{ data: Appointment }> {
  return request<{ data: Appointment }>('api/v1/appointments', { method: 'POST', body: input });
}

export function cancel(id: number, reason: string): Promise<void> {
  return request<void>(`api/v1/appointments/${id}/cancel`, { method: 'POST', body: { reason } });
}

export const CLINIC_TYPES = ['routine', 'follow_up', 'referral_based'] as const;
export const GUIDANCE_TYPES = ['initial', 'follow_up', 'referral_based'] as const;
