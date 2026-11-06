import { request } from '../client';
import type { TeamUnit } from '../types';

export type TeamTaskStatus = 'todo' | 'in_progress' | 'blocked' | 'done';
export type TeamTaskPriority = 'low' | 'normal' | 'high';

export interface TeamMember {
  id: number;
  name: string;
  role: string;
}

export interface TeamTaskChecklistItem {
  text: string;
  done: boolean;
}

export interface TeamTaskSummary {
  id: number;
  title: string;
  status: TeamTaskStatus;
  priority: TeamTaskPriority;
  assignee: Pick<TeamMember, 'id' | 'name'> | null;
  /** Date-only YYYY-MM-DD in Asia/Manila business time. */
  due_date: string | null;
  checklist_progress: { done: number; total: number };
  version: number;
  can_edit: boolean;
  /** RFC3339 with explicit offset. */
  created_at: string;
  /** RFC3339 with explicit offset. */
  updated_at: string;
}

export interface TeamTaskComment {
  id: number;
  actor: Pick<TeamMember, 'id' | 'name'>;
  body: string;
  created_at: string;
}

export interface TeamTaskActivity {
  id: number;
  actor: Pick<TeamMember, 'id' | 'name'>;
  event: string;
  old_status: TeamTaskStatus | null;
  new_status: TeamTaskStatus | null;
  created_at: string;
}

export interface TeamTaskDetail extends TeamTaskSummary {
  description: string;
  checklist: TeamTaskChecklistItem[];
  comments: TeamTaskComment[];
  activity: TeamTaskActivity[];
  comments_total: number;
  comments_limit: number;
  activity_total: number;
  activity_limit: number;
}

export interface TeamTaskCounts {
  todo: number;
  in_progress: number;
  blocked: number;
  done: number;
  total: number;
}

export interface TeamTaskListMeta {
  counts: TeamTaskCounts;
  total: number;
  page: number;
  pages: number;
  limit: number;
  scope: 'team' | 'mine';
}

export interface TeamTaskFilters {
  scope: 'team' | 'mine';
  q: string;
  status: TeamTaskStatus | 'all';
  priority: TeamTaskPriority | 'all';
  page: number;
}

export interface TeamTaskEditableInput {
  title: string;
  description: string;
  status: TeamTaskStatus;
  priority: TeamTaskPriority;
  assignee_user_id: number | null;
  due_date: string | null;
  checklist: TeamTaskChecklistItem[];
}

export interface TeamTaskCreateInput extends TeamTaskEditableInput {
  /** UUID v4, generated once and reused for an identical retry. */
  client_nonce: string;
}

export interface TeamTaskUpdateInput extends TeamTaskEditableInput {
  version: number;
}

export interface TeamTaskCommentInput {
  body: string;
  /** UUID v4, generated once and reused for an identical retry. */
  client_nonce: string;
}

export interface TeamContext {
  unit: TeamUnit;
  label: string;
  can_manage: boolean;
  actor: TeamMember;
}

function base(unit: TeamUnit): string {
  return `api/v1/team/${unit}`;
}

function params(values: Record<string, string | number | undefined>): string {
  const query = Object.entries(values)
    .filter(([, value]) => value !== undefined && value !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join('&');
  return query === '' ? '' : `?${query}`;
}

export function context(unit: TeamUnit): Promise<{ data: TeamContext }> {
  return request(`${base(unit)}`);
}

export function members(
  unit: TeamUnit,
  filters: { q?: string; page?: number } = {},
): Promise<{ data: TeamMember[]; meta: { unit: TeamUnit; total: number; page?: number; pages?: number; limit?: number } }> {
  return request(`${base(unit)}/members${params({ q: filters.q?.trim() || undefined, page: filters.page })}`);
}

export function list(unit: TeamUnit, filters: TeamTaskFilters): Promise<{ data: TeamTaskSummary[]; meta: TeamTaskListMeta }> {
  return request(`${base(unit)}/tasks${params({
    mine: filters.scope === 'mine' ? '1' : undefined,
    q: filters.q.trim() || undefined,
    status: filters.status === 'all' ? undefined : filters.status,
    priority: filters.priority === 'all' ? undefined : filters.priority,
    page: filters.page,
  })}`);
}

export function detail(unit: TeamUnit, id: number): Promise<{ data: TeamTaskDetail }> {
  return request(`${base(unit)}/tasks/${id}`);
}

export function create(unit: TeamUnit, input: TeamTaskCreateInput): Promise<{ data: TeamTaskSummary }> {
  return request(`${base(unit)}/tasks`, { method: 'POST', body: input });
}

export function update(unit: TeamUnit, id: number, input: TeamTaskUpdateInput): Promise<{ data: TeamTaskSummary }> {
  return request(`${base(unit)}/tasks/${id}/update`, { method: 'POST', body: input });
}

export function changeStatus(
  unit: TeamUnit,
  id: number,
  input: { status: TeamTaskStatus; version: number },
): Promise<{ data: TeamTaskSummary }> {
  return request(`${base(unit)}/tasks/${id}/status`, { method: 'POST', body: input });
}

export function comment(unit: TeamUnit, id: number, input: TeamTaskCommentInput): Promise<{ data: TeamTaskDetail }> {
  return request(`${base(unit)}/tasks/${id}/comments`, { method: 'POST', body: input });
}
