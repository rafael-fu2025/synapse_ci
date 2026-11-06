import { request } from '../client';
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

export function inbox(): Promise<{ data: NotificationInbox }> {
  return request<{ data: NotificationInbox }>('api/v1/notifications');
}

export function history(page: number, filter: 'all' | 'unread'): Promise<{ data: NotificationHistoryPage }> {
  return request<{ data: NotificationHistoryPage }>(`api/v1/notifications/history?page=${page}&filter=${filter}`);
}

export function markRead(id: number): Promise<void> {
  return request<void>(`api/v1/notifications/${id}/read`, { method: 'POST' });
}

export function markAllRead(): Promise<void> {
  return request<void>('api/v1/notifications/read-all', { method: 'POST' });
}
