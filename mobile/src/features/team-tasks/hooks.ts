import { useCallback, useEffect, useRef } from 'react';
import { onlineManager, useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query';
import { useApiClient, useApiMutation, type ApiClient } from '../../api/runtime';
import type { RefetchOptions } from '@tanstack/react-query';
import { ApiError, NetworkError, SessionChangedError } from '../../api/client';
import * as api from '../../api/endpoints/teamTasks';
import { queryKeys } from '../../api/queryKeys';
import type { TeamUnit } from '../../api/types';
import { useAuth } from '../../store/auth';
import { useActiveScreen } from '../../lib/useActiveScreen';
import { useRealtimeReady } from '../../lib/useRealtimeReady';
import { invalidateTeamTasks } from '../workspace/invalidateTeamResources';

const TASK_POLL_MS = 30_000;

function useIdentity(): { tenantId: number; userId: number } {
  const { user } = useAuth();
  return { tenantId: user?.tenant_id ?? 0, userId: user?.id ?? 0 };
}

/** A route's handlers and async continuations may not outlive its identity/unit/record. */
export function useTeamTaskScreenScope(unit: TeamUnit | null, record = '') {
  const apiClient = useApiClient();
  const identity = useIdentity();
  const key = `${apiClient.runtime.id}:${apiClient.sessionVersion}:${identity.tenantId}:${identity.userId}:${unit ?? 'none'}:${record}`;
  const lifetime = useRef({ key, mounted: true, valid: true });
  if (lifetime.current.key !== key) { lifetime.current.valid = false; }
  useEffect(() => {
    const screen = lifetime.current;
    screen.mounted = true;
    return () => { screen.mounted = false; };
  }, []);
  return useCallback(() => apiClient.isCurrent() && lifetime.current.mounted && lifetime.current.valid, [apiClient]);
}

export function useTeamMembers(unit: TeamUnit | null, filters: { q: string; page: number } = { q: '', page: 1 }) {
  const apiClient = useApiClient();
  const identity = useIdentity();
  const active = useActiveScreen(identity.tenantId > 0 && identity.userId > 0 && unit !== null);
  const normalized = { ...filters, q: filters.q.trim() };
  const query = useQuery({
    queryKey: queryKeys.teamMembers(identity.tenantId, identity.userId, unit, normalized),
    queryFn: () => api.members(unit!, normalized, apiClient),
    enabled: active,
  });
  return { ...query, eligible: active, refetch: (options?: RefetchOptions) => active && apiClient.isCurrent() ? query.refetch(options) : Promise.resolve(query) };
}

/** Search pages live only in the identity/unit-scoped QueryClient, never in a second ref cache. */
export function useTeamMemberSearch(unit: TeamUnit | null, q = '', enabled = true) {
  const apiClient = useApiClient();
  const identity = useIdentity();
  const active = useActiveScreen(enabled && identity.tenantId > 0 && identity.userId > 0 && unit !== null);
  const search = q.trim();
  const query = useInfiniteQuery({
    queryKey: [...queryKeys.teamMembers(identity.tenantId, identity.userId, unit, { q: search, page: 1 }), 'search'],
    queryFn: ({ pageParam }) => api.members(unit!, { q: search, page: pageParam }, apiClient),
    initialPageParam: 1,
    getNextPageParam: (lastPage, _pages, lastPageParam) => {
      const page = lastPage.meta.page ?? lastPageParam;
      const pages = lastPage.meta.pages ?? Math.max(1, Math.ceil(lastPage.meta.total / (lastPage.meta.limit ?? 50)));
      return page < pages ? page + 1 : undefined;
    },
    enabled: active,
    staleTime: TASK_POLL_MS,
  });
  return {
    ...query,
    eligible: active,
    refetch: (options?: RefetchOptions) => active && apiClient.isCurrent() ? query.refetch(options) : Promise.resolve(query),
    fetchNextPage: (options?: Parameters<typeof query.fetchNextPage>[0]) => active && apiClient.isCurrent() ? query.fetchNextPage(options) : Promise.resolve(query),
  };
}

export function useTeamTasks(unit: TeamUnit | null, filters: api.TeamTaskFilters) {
  const apiClient = useApiClient();
  const identity = useIdentity();
  const realtime = useRealtimeReady();
  const active = useActiveScreen(identity.tenantId > 0 && identity.userId > 0 && unit !== null);
  const query = useQuery({
    queryKey: queryKeys.teamTasks(identity.tenantId, identity.userId, unit, filters),
    queryFn: () => api.list(unit!, filters, apiClient),
    enabled: active,
    refetchInterval: active && !realtime ? TASK_POLL_MS : false,
    refetchIntervalInBackground: false,
    // Keep rows while changing a page/filter, but never across identity, unit, or My Tasks scope.
    placeholderData: (previousData, previousQuery) => {
      const key = previousQuery?.queryKey;
      const previousFilters = key?.[5] as api.TeamTaskFilters | undefined;
      return unit !== null && identity.tenantId > 0 && identity.userId > 0
        && key?.[1] === identity.tenantId && key?.[2] === identity.userId
        && key?.[3] === unit && previousFilters?.scope === filters.scope
        ? previousData : undefined;
    },
  });
  const visible = query.error instanceof ApiError && [401, 403].includes(query.error.status)
    ? { ...query, data: undefined } : query;
  return { ...visible, eligible: active, refetch: (options?: RefetchOptions) => active && apiClient.isCurrent() ? query.refetch(options) : Promise.resolve(visible) };
}

export function useTeamTask(unit: TeamUnit | null, id: number) {
  const apiClient = useApiClient();
  const identity = useIdentity();
  const active = useActiveScreen(identity.tenantId > 0 && identity.userId > 0 && unit !== null && id > 0);
  const query = useQuery({
    queryKey: queryKeys.teamTask(identity.tenantId, identity.userId, unit, id),
    queryFn: () => api.detail(unit!, id, apiClient),
    enabled: active,
  });
  const visible = query.error instanceof ApiError && [401, 403].includes(query.error.status)
    ? { ...query, data: undefined } : query;
  return { ...visible, eligible: active, refetch: (options?: RefetchOptions) => active && apiClient.isCurrent() ? query.refetch(options) : Promise.resolve(visible) };
}

/** A denied read establishes access loss. A record-action 403 alone does not. */
export function useTeamTaskAccessRecovery(unit: TeamUnit | null, readError: unknown) {
  const apiClient = useApiClient();
  const identity = useIdentity();
  const { refreshProfile } = useAuth();
  const client = useQueryClient();
  const recovered = useRef<{ scope: string; error: unknown } | null>(null);
  const reconcileAccess = useCallback(() => apiClient.isCurrent() ? refreshProfile().catch(() => undefined) : Promise.resolve(), [apiClient, refreshProfile]);

  useEffect(() => {
    if (!apiClient.isCurrent() || unit === null || identity.tenantId < 1 || identity.userId < 1
      || !(readError instanceof ApiError) || readError.status !== 403) { return; }
    const scope = `${identity.tenantId}:${identity.userId}:${unit}`;
    if (recovered.current?.scope === scope && recovered.current.error === readError) { return; }
    recovered.current = { scope, error: readError };
    const queryKey = queryKeys.teamUnit(identity.tenantId, identity.userId, unit);
    // Preserve the observer's denied state (and its error), not its cached protected data.
    for (const query of client.getQueryCache().findAll({ queryKey, exact: false })) {
      query.setState({ data: undefined, dataUpdatedAt: 0, dataUpdateCount: query.state.dataUpdateCount + 1 });
    }
    client.cancelQueries({ queryKey, exact: false }, { revert: false }).then(() => {
      if (apiClient.isCurrent()) { client.removeQueries({ queryKey, exact: false, type: 'inactive' }); }
    });
    reconcileAccess();
  }, [apiClient, client, identity.tenantId, identity.userId, readError, reconcileAccess, unit]);

  return reconcileAccess;
}

function requireUnit(unit: TeamUnit | null): TeamUnit {
  if (unit === null) { throw new Error('A Team Workspace unit is required.'); }
  // Do not let React Query queue a write for automatic execution on reconnect.
  if (!onlineManager.isOnline()) { throw new NetworkError(); }
  return unit;
}

function useTaskMutation<TInput, TResponse>(
  unit: TeamUnit | null,
  id: number,
  operation: string,
  write: (taskUnit: TeamUnit, input: TInput, client: ApiClient) => Promise<TResponse>,
) {
  const identity = useIdentity();
  const client = useQueryClient();
  const latest = useRef({ ...identity, unit, id });
  latest.current = { ...identity, unit, id };
  return useApiMutation({
    // A changed scope detaches the observer instead of changing an in-flight write's callbacks.
    mutationKey: ['team', identity.tenantId, identity.userId, unit, 'task-write', operation, id],
    mutationFn: (input: TInput, capturedClient) => {
      if (identity.tenantId < 1 || identity.userId < 1
        || latest.current.tenantId !== identity.tenantId || latest.current.userId !== identity.userId
        || latest.current.unit !== unit || latest.current.id !== id) { throw new SessionChangedError(); }
      return write(requireUnit(unit), input, capturedClient);
    },
    retry: false,
    networkMode: 'always',
    onSuccess: async () => {
      if (unit === null || latest.current.tenantId !== identity.tenantId || latest.current.userId !== identity.userId) { return; }
      try {
        await invalidateTeamTasks(client, identity, unit);
      } catch {
        // The API already acknowledged the write. A cache-refresh failure must not offer a duplicate send.
        // Invalidated queries remain stale and expose their own read errors/retry controls.
      }
    },
  });
}

export function useCreateTeamTask(unit: TeamUnit | null) {
  return useTaskMutation(unit, 0, 'create', (taskUnit, input: api.TeamTaskCreateInput, apiClient) => api.create(taskUnit, input, apiClient));
}

export function useUpdateTeamTask(unit: TeamUnit | null, id: number) {
  return useTaskMutation(unit, id, 'update', (taskUnit, input: api.TeamTaskUpdateInput, apiClient) => api.update(taskUnit, id, input, apiClient));
}

export function useChangeTeamTaskStatus(unit: TeamUnit | null, id: number) {
  return useTaskMutation(unit, id, 'status', (taskUnit, input: { status: api.TeamTaskStatus; version: number }, apiClient) => api.changeStatus(taskUnit, id, input, apiClient));
}

export function useCommentTeamTask(unit: TeamUnit | null, id: number) {
  return useTaskMutation(unit, id, 'comment', (taskUnit, input: api.TeamTaskCommentInput, apiClient) => api.comment(taskUnit, id, input, apiClient));
}
