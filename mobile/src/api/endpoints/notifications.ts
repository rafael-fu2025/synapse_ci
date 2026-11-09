import { bindApiClient, request, type ApiBinding } from '../client';
import type { TeamUnit } from '../types';

/** The record a native client can open. Team targets remain server-authoritative. */
export type NotificationTarget =
  | { kind: 'appointment'; id: number }
  | { kind: 'announcement'; id: number }
  | { kind: 'survey'; id: number }
  | { kind: 'team_task'; unit: TeamUnit; id: number }
  | { kind: 'team_event'; unit: TeamUnit; id: number }
  | { kind: 'team_chat_message'; unit: TeamUnit; id: number };

export interface NotificationItem {
  id: number;
  title: string;
  message: string;
  created_at: string | null;
  read_at: string | null;
  /** Null for notices that have no mobile surface (referrals and workflow alerts). */
  target?: NotificationTarget | null;
}

export interface NotificationInbox {
  items: NotificationItem[];
  unread_count: number;
}

export interface NotificationHistoryPage extends NotificationInbox {
  total: number;
  page: number;
  pages: number;
  filter: string;
  per_page: number;
}

export function inbox(binding?: ApiBinding): Promise<{ data: NotificationInbox }> {
  return (binding ? bindApiClient(binding).request : request)<{ data: NotificationInbox }>('api/v1/notifications');
}

export function history(page: number, filter: 'all' | 'unread', binding?: ApiBinding): Promise<{ data: NotificationHistoryPage }> {
  return (binding ? bindApiClient(binding).request : request)<{ data: NotificationHistoryPage }>(`api/v1/notifications/history?page=${page}&filter=${filter}`);
}

export function markRead(id: number, binding?: ApiBinding): Promise<void> {
  return (binding ? bindApiClient(binding).request : request)<void>(`api/v1/notifications/${id}/read`, { method: 'POST' });
}

export function markAllRead(binding?: ApiBinding): Promise<void> {
  return (binding ? bindApiClient(binding).request : request)<void>('api/v1/notifications/read-all', { method: 'POST' });
}
