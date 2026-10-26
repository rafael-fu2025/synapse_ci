import { request } from '../client';

export interface QueueTicket {
  unit: 'clinic' | 'guidance';
  ticket_code: string;
  status: 'waiting' | 'called' | 'in_consultation' | 'skipped';
  priority: string;
}

export interface QueueSnapshot {
  date: string;
  tickets: QueueTicket[];
}

export function snapshot(): Promise<{ data: QueueSnapshot }> {
  return request<{ data: QueueSnapshot }>('api/v1/queue');
}
