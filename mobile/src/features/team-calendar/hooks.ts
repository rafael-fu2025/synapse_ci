import { useCallback, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as calendarApi from '../../api/endpoints/teamCalendar';
import * as tasksApi from '../../api/endpoints/teamTasks';
import type { TeamUnit } from '../../api/types';
import { useFocusPoll } from '../../lib/useFocusPoll';
import { useAuth } from '../../store/auth';

const CALENDAR_POLL_MS = 30_000;
const MEMBER_CACHE_LIMIT = 40;
const MEMBER_RESULT_LIMIT = 100;

export const teamCalendarQueryKeys = {
  root: (tenantId: number, userId: number) => ['team-calendar', tenantId, userId] as const,
  unit: (tenantId: number, userId: number, unit: TeamUnit | null) =>
    [...teamCalendarQueryKeys.root(tenantId, userId), unit] as const,
  list: (
    tenantId: number,
    userId: number,
    unit: TeamUnit | null,
    filters: calendarApi.TeamCalendarFilters,
  ) => [...teamCalendarQueryKeys.unit(tenantId, userId, unit), 'list', filters] as const,
  event: (tenantId: number, userId: number, unit: TeamUnit | null, id: number) =>
    [...teamCalendarQueryKeys.unit(tenantId, userId, unit), 'event', id] as const,
  members: (tenantId: number, userId: number, unit: TeamUnit | null, q: string, page: number) =>
    [...teamCalendarQueryKeys.unit(tenantId, userId, unit), 'members', q, page] as const,
};

function useIdentity(): { tenantId: number; userId: number } {
  const { user } = useAuth();
  return { tenantId: user?.tenant_id ?? 0, userId: user?.id ?? 0 };
}

function requireUnit(unit: TeamUnit | null): TeamUnit {
  if (unit === null) { throw new Error('A Team Workspace unit is required.'); }
  return unit;
}

export function useTeamCalendar(
  unit: TeamUnit | null,
  filters: calendarApi.TeamCalendarFilters,
) {
  const identity = useIdentity();
  const refetchInterval = useFocusPoll(CALENDAR_POLL_MS);
  return useQuery({
    queryKey: teamCalendarQueryKeys.list(identity.tenantId, identity.userId, unit, filters),
    queryFn: () => calendarApi.list(unit!, filters),
    enabled: identity.tenantId > 0 && identity.userId > 0 && unit !== null,
    refetchInterval,
  });
}

export function useTeamCalendarEvent(unit: TeamUnit | null, id: number) {
  const identity = useIdentity();
  return useQuery({
    queryKey: teamCalendarQueryKeys.event(identity.tenantId, identity.userId, unit, id),
    queryFn: () => calendarApi.detail(unit!, id),
    enabled: identity.tenantId > 0 && identity.userId > 0 && unit !== null && id > 0,
  });
}

/** Page and cache bounds prevent an unbounded people-search request/cache fan-out. */
export function useTeamCalendarMemberSearch(unit: TeamUnit | null) {
  const identity = useIdentity();
  const client = useQueryClient();
  const cache = useRef(new Map<string, tasksApi.TeamMember[]>());
  return useCallback(async (query: string, requestedPage = 1): Promise<tasksApi.TeamMember[]> => {
    if (unit === null || identity.tenantId < 1 || identity.userId < 1) { return []; }
    const q = query.trim().slice(0, 80);
    const page = Math.min(5, Math.max(1, Math.trunc(requestedPage) || 1));
    const cacheKey = `${identity.tenantId}:${identity.userId}:${unit}:${page}:${q.toLocaleLowerCase()}`;
    const cached = cache.current.get(cacheKey);
    if (cached) { return cached; }
    const response = await client.fetchQuery({
      queryKey: teamCalendarQueryKeys.members(identity.tenantId, identity.userId, unit, q, page),
      queryFn: () => tasksApi.members(unit, { q, page }),
      staleTime: 30_000,
    });
    const bounded = response.data.slice(0, MEMBER_RESULT_LIMIT);
    if (cache.current.size >= MEMBER_CACHE_LIMIT) {
      const oldest = cache.current.keys().next().value as string | undefined;
      if (oldest !== undefined) { cache.current.delete(oldest); }
    }
    cache.current.set(cacheKey, bounded);
    return bounded;
  }, [client, identity.tenantId, identity.userId, unit]);
}

function useCalendarInvalidation(unit: TeamUnit | null) {
  const identity = useIdentity();
  const client = useQueryClient();
  return async (id?: number) => {
    if (unit === null) { return; }
    await client.invalidateQueries({
      queryKey: teamCalendarQueryKeys.unit(identity.tenantId, identity.userId, unit),
      exact: false,
    });
    if (id !== undefined) {
      await client.invalidateQueries({
        queryKey: teamCalendarQueryKeys.event(identity.tenantId, identity.userId, unit, id),
      });
    }
  };
}

export function useCreateTeamCalendarEvent(unit: TeamUnit | null) {
  const invalidate = useCalendarInvalidation(unit);
  return useMutation({
    mutationFn: (input: calendarApi.TeamCalendarEventCreateInput) =>
      calendarApi.create(requireUnit(unit), input),
    onSuccess: response => invalidate(response.data.id),
  });
}

export function useUpdateTeamCalendarEvent(unit: TeamUnit | null, id: number) {
  const invalidate = useCalendarInvalidation(unit);
  return useMutation({
    mutationFn: (input: calendarApi.TeamCalendarEventUpdateInput) =>
      calendarApi.update(requireUnit(unit), id, input),
    onSuccess: () => invalidate(id),
  });
}

export function useCancelTeamCalendarEvent(unit: TeamUnit | null, id: number) {
  const invalidate = useCalendarInvalidation(unit);
  return useMutation({
    mutationFn: (version: number) => calendarApi.cancel(requireUnit(unit), id, version),
    onSuccess: () => invalidate(id),
  });
}
