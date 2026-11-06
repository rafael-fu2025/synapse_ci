import type { TeamTaskFilters } from './endpoints/teamTasks';
import type { TeamUnit } from './types';

export const queryKeys = {
  me: ['me'] as const,
  profilePhoto: (tenantId: number, userId: number) => ['me', 'photo', tenantId, userId] as const,
  healthSummary: ['health-summary'] as const,
  appointments: (filters: { service: string; status: string; sort: string }) => ['appointments', filters] as const,
  appointment: (id: number) => ['appointments', 'detail', id] as const,
  queue: ['queue'] as const,
  announcements: (filters: { unit: string; status: string; q: string; page: number }) =>
    ['announcements', filters] as const,
  announcement: (id: number) => ['announcements', 'detail', id] as const,
  surveys: (filters: { status: string; q: string }) => ['surveys', filters] as const,
  survey: (id: number) => ['surveys', 'detail', id] as const,
  notifications: ['notifications'] as const,
  notificationHistory: (page: number, filter: string) => ['notifications', 'history', page, filter] as const,
  staffQueue: (unit: string) => ['staff-queue', unit] as const,
  teamRoot: (tenantId: number, userId: number) => ['team', tenantId, userId] as const,
  teamUnit: (tenantId: number, userId: number, unit: TeamUnit) => ['team', tenantId, userId, unit] as const,
  teamContext: (tenantId: number, userId: number, unit: TeamUnit) => ['team', tenantId, userId, unit, 'context'] as const,
  teamMembers: (tenantId: number, userId: number, unit: TeamUnit | null, filters: { q: string; page: number }) =>
    ['team', tenantId, userId, unit, 'members', filters] as const,
  teamTasks: (tenantId: number, userId: number, unit: TeamUnit | null, filters: TeamTaskFilters) =>
    ['team', tenantId, userId, unit, 'tasks', filters] as const,
  teamTask: (tenantId: number, userId: number, unit: TeamUnit | null, id: number) =>
    ['team', tenantId, userId, unit, 'tasks', 'detail', id] as const,
} as const;
