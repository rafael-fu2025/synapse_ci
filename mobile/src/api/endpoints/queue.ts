import { bindApiClient, request, type ApiBinding } from '../client';

export interface QueueTicket {
  unit: 'clinic' | 'guidance';
  ticket_code: string;
  status: 'waiting' | 'called' | 'in_consultation' | 'skipped';
  priority: string;
  /** 1-based place in line; null when not waiting or unknown. */
  position: number | null;
  people_ahead: number | null;
  estimated_wait_minutes: number | null;
}

export interface QueueSnapshot {
  date: string;
  tickets: QueueTicket[];
}

export function snapshot(binding?: ApiBinding): Promise<{ data: QueueSnapshot }> {
  return (binding ? bindApiClient(binding).request : request)<{ data: QueueSnapshot }>('api/v1/queue');
}
