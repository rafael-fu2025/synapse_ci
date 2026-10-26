import { request } from '../client';

export interface StaffQueueTicket {
  id: number;
  ticket_code: string;
  status: string;
  priority: string;
  patient_name: string;
  patient_kind: string | null;
  called_at: string | null;
  completed_at: string | null;
  created_at: string | null;
}

export interface StaffQueueSummary {
  unit: 'clinic' | 'guidance';
  counts: {
    total: number;
    waiting: number;
    called: number;
    in_consultation: number;
    done: number;
    skipped: number;
    no_show: number;
  };
  tickets: {
    waiting: StaffQueueTicket[];
    called: StaffQueueTicket[];
    in_consultation: StaffQueueTicket[];
  };
}

/** Read-only staff monitoring; no action endpoint exists on the mobile API. */
export function summary(unit?: 'clinic' | 'guidance'): Promise<{ data: StaffQueueSummary[] }> {
  const query = unit !== undefined ? `?unit=${unit}` : '';
  return request<{ data: StaffQueueSummary[] }>(`api/v1/staff/queue-summary${query}`);
}
