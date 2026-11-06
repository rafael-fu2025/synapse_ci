import { useCallback, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '../../api/endpoints/teamTasks';
import { queryKeys } from '../../api/queryKeys';
import type { TeamUnit } from '../../api/types';
import { useAuth } from '../../store/auth';
import { useFocusPoll } from '../../lib/useFocusPoll';

const TASK_POLL_MS = 30_000;

function useIdentity(): { tenantId: number; userId: number } {
  const { user } = useAuth();
  return { tenantId: user?.tenant_id ?? 0, userId: user?.id ?? 0 };
}

export function useTeamMembers(unit: TeamUnit | null, filters: { q: string; page: number } = { q: '', page: 1 }) {
  const identity = useIdentity();
  return useQuery({
    queryKey: queryKeys.teamMembers(identity.tenantId, identity.userId, unit, filters),
    queryFn: () => api.members(unit!, filters),
    enabled: identity.tenantId > 0 && identity.userId > 0 && unit !== null,
  });
}

export function useTeamMemberSearch(unit: TeamUnit | null) {
  const identity = useIdentity();
  const client = useQueryClient();
  const cache = useRef(new Map<string, api.TeamMember[]>());
  return useCallback(async (q: string, page = 1): Promise<api.TeamMember[]> => {
    if (unit === null || identity.tenantId < 1 || identity.userId < 1) { return []; }
    const key = `${page}:${q.trim().toLocaleLowerCase()}`;
    const cached = cache.current.get(key);
    if (cached) { return cached; }
    const response = await client.fetchQuery({
      queryKey: queryKeys.teamMembers(identity.tenantId, identity.userId, unit, { q, page }),
      queryFn: () => api.members(unit, { q, page }),
      staleTime: 30_000,
    });
    cache.current.set(key, response.data);
    return response.data;
  }, [client, identity.tenantId, identity.userId, unit]);
}

export function useTeamTasks(unit: TeamUnit | null, filters: api.TeamTaskFilters) {
  const identity = useIdentity();
  const refetchInterval = useFocusPoll(TASK_POLL_MS);
  return useQuery({
    queryKey: queryKeys.teamTasks(identity.tenantId, identity.userId, unit, filters),
    queryFn: () => api.list(unit!, filters),
    enabled: identity.tenantId > 0 && identity.userId > 0 && unit !== null,
    refetchInterval,
  });
}

export function useTeamTask(unit: TeamUnit | null, id: number) {
  const identity = useIdentity();
  return useQuery({
    queryKey: queryKeys.teamTask(identity.tenantId, identity.userId, unit, id),
    queryFn: () => api.detail(unit!, id),
    enabled: identity.tenantId > 0 && identity.userId > 0 && unit !== null && id > 0,
  });
}

function requireUnit(unit: TeamUnit | null): TeamUnit {
  if (unit === null) { throw new Error('A Team Workspace unit is required.'); }
  return unit;
}

function useTaskInvalidation(unit: TeamUnit | null) {
  const identity = useIdentity();
  const client = useQueryClient();
  return async (id?: number) => {
    if (unit === null) { return; }
    await client.invalidateQueries({ queryKey: queryKeys.teamUnit(identity.tenantId, identity.userId, unit), exact: false });
    if (id !== undefined) {
      await client.invalidateQueries({ queryKey: queryKeys.teamTask(identity.tenantId, identity.userId, unit, id) });
    }
  };
}

export function useCreateTeamTask(unit: TeamUnit | null) {
  const invalidate = useTaskInvalidation(unit);
  return useMutation({
    mutationFn: (input: api.TeamTaskCreateInput) => api.create(requireUnit(unit), input),
    onSuccess: response => invalidate(response.data.id),
  });
}

export function useUpdateTeamTask(unit: TeamUnit | null, id: number) {
  const invalidate = useTaskInvalidation(unit);
  return useMutation({
    mutationFn: (input: api.TeamTaskUpdateInput) => api.update(requireUnit(unit), id, input),
    onSuccess: () => invalidate(id),
  });
}

export function useChangeTeamTaskStatus(unit: TeamUnit | null, id: number) {
  const invalidate = useTaskInvalidation(unit);
  return useMutation({
    mutationFn: (input: { status: api.TeamTaskStatus; version: number }) => api.changeStatus(requireUnit(unit), id, input),
    onSuccess: () => invalidate(id),
  });
}

export function useCommentTeamTask(unit: TeamUnit | null, id: number) {
  const invalidate = useTaskInvalidation(unit);
  return useMutation({
    mutationFn: (input: api.TeamTaskCommentInput) => api.comment(requireUnit(unit), id, input),
    onSuccess: () => invalidate(id),
  });
}
