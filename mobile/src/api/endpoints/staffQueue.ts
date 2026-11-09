import { bindApiClient, request, type ApiBinding } from '../client';

export type QueueUnit = 'clinic' | 'guidance';
export type QueueStage = 'waiting' | 'called' | 'in_consultation' | 'skipped';
export type QueuePriority = 'routine' | 'urgent' | 'emergency';

/** Operational identifiers only. Never add patient information to this projection. */
export interface StaffQueueTicket {
  id: number;
  ticket_code: string;
  status: QueueStage;
  priority: QueuePriority;
  created_at: string | null;
  called_at: string | null;
  started_at: string | null;
  skip_until: string | null;
}

export interface StaffQueueMetrics {
  active: number;
  priority_mix: Record<QueuePriority, number>;
  waiting_age: {
    sample_count: number;
    total_seconds: number;
    oldest_seconds: number | null;
    average_seconds: number | null;
  };
}

export interface StaffQueueSummary {
  unit: QueueUnit;
  counts: {
    total: number;
    waiting: number;
    called: number;
    in_consultation: number;
    done: number;
    skipped: number;
    no_show: number;
  };
  tickets: Record<QueueStage, StaffQueueTicket[]>;
  metrics: StaffQueueMetrics;
}

export interface StaffQueueResponse {
  data: StaffQueueSummary[];
  meta: { date: string; generated_at: string };
}

/** Fetch every authorized unit; mobile has no queue mutation endpoints. */
export function summary(unit?: QueueUnit, binding?: ApiBinding): Promise<StaffQueueResponse> {
  return (binding ? bindApiClient(binding).request : request)<StaffQueueResponse>(`api/v1/staff/queue-summary${unit ? `?unit=${unit}` : ''}`);
}
