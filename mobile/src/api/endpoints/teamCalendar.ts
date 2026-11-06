import { request } from '../client';
import type { TeamUnit } from '../types';
import type { TeamMember, TeamTaskPriority, TeamTaskStatus } from './teamTasks';

export type TeamCalendarView = 'agenda' | 'week' | 'month';
export type TeamCalendarScope = 'team' | 'mine';
export type TeamCalendarEventStatus = 'scheduled' | 'cancelled';
export type TeamCalendarStatusFilter = TeamCalendarEventStatus | 'all';

export interface TeamCalendarEventSummary {
  type: 'event';
  id: number;
  title: string;
  status: TeamCalendarEventStatus;
  /** RFC3339 instant with an explicit offset. */
  starts_at: string;
  /** RFC3339 instant with an explicit offset. */
  ends_at: string;
  location: string;
  participants: TeamMember[];
  version: number;
  can_edit: boolean;
  created_at: string;
  updated_at: string;
}

export interface TeamCalendarEventDetail extends TeamCalendarEventSummary {
  description: string;
}

export interface TeamCalendarTaskDeadline {
  type: 'task_deadline';
  id: number;
  title: string;
  status: TeamTaskStatus;
  priority: TeamTaskPriority;
  /** Date-only YYYY-MM-DD in Asia/Manila business time. */
  due_date: string;
  assignee_user_id: number | null;
  version: number;
}

export type TeamCalendarItem = TeamCalendarEventSummary | TeamCalendarTaskDeadline;

export interface TeamCalendarListMeta {
  date: string;
  view: TeamCalendarView;
  scope: TeamCalendarScope;
  start_date: string;
  /** Exclusive Manila date. */
  end_date: string;
  total_events: number;
  total_tasks: number;
  truncated: boolean;
}

export interface TeamCalendarFilters {
  date: string;
  view: TeamCalendarView;
  scope: TeamCalendarScope;
  status: TeamCalendarStatusFilter;
}

export interface TeamCalendarEventEditableInput {
  title: string;
  description: string;
  location: string;
  /** Wall time in Asia/Manila, YYYY-MM-DDTHH:mm. */
  starts_at: string;
  /** Wall time in Asia/Manila, YYYY-MM-DDTHH:mm. */
  ends_at: string;
  participant_ids: number[];
}

export interface TeamCalendarEventCreateInput extends TeamCalendarEventEditableInput {
  /** UUID v4, generated once and reused for an identical retry. */
  client_nonce: string;
}

export interface TeamCalendarEventUpdateInput extends TeamCalendarEventEditableInput {
  version: number;
}

function base(unit: TeamUnit): string {
  return `api/v1/team/${unit}/calendar`;
}

function params(values: Record<string, string | undefined>): string {
  const query = Object.entries(values)
    .filter(([, value]) => value !== undefined && value !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join('&');
  return query === '' ? '' : `?${query}`;
}

export function list(
  unit: TeamUnit,
  filters: TeamCalendarFilters,
): Promise<{ data: TeamCalendarItem[]; meta: TeamCalendarListMeta }> {
  return request(`${base(unit)}${params({
    date: filters.date,
    view: filters.view,
    mine: filters.scope === 'mine' ? '1' : undefined,
    status: filters.status === 'scheduled' ? undefined : filters.status,
  })}`);
}

export function detail(
  unit: TeamUnit,
  id: number,
): Promise<{ data: TeamCalendarEventDetail }> {
  return request(`${base(unit)}/events/${id}`);
}

export function create(
  unit: TeamUnit,
  input: TeamCalendarEventCreateInput,
): Promise<{ data: TeamCalendarEventDetail }> {
  return request(`${base(unit)}/events`, { method: 'POST', body: input });
}

export function update(
  unit: TeamUnit,
  id: number,
  input: TeamCalendarEventUpdateInput,
): Promise<{ data: TeamCalendarEventDetail }> {
  return request(`${base(unit)}/events/${id}/update`, { method: 'POST', body: input });
}

export function cancel(
  unit: TeamUnit,
  id: number,
  version: number,
): Promise<{ data: TeamCalendarEventDetail }> {
  return request(`${base(unit)}/events/${id}/cancel`, {
    method: 'POST',
    body: { version },
  });
}
