import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { onlineManager, useQuery, useQueryClient } from '@tanstack/react-query';
import { useApiClient, useApiMutation, type ApiClient } from '../../api/runtime';
import type { RefetchOptions, UseQueryResult } from '@tanstack/react-query';
import { NetworkError } from '../../api/client';
import * as calendarApi from '../../api/endpoints/teamCalendar';
import * as tasksApi from '../../api/endpoints/teamTasks';
import type { TeamUnit } from '../../api/types';
import { useActiveScreen } from '../../lib/useActiveScreen';
import { useRealtimeReady } from '../../lib/useRealtimeReady';
import { useAuth } from '../../store/auth';
import { manilaDayBounds, todayInManila } from './manila';

const CALENDAR_POLL_MS = 30_000;

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

/** An imperative retry must obey the same visibility/connection rules as polling. */
function useEligibleQuery<T>(query: UseQueryResult<T>, eligible: boolean) {
  const apiClient = useApiClient();
  const current = useRef({ query, eligible });
  current.current = { query, eligible };
  const refetch = useCallback((options?: RefetchOptions) => {
    const state = current.current;
    return apiClient.isCurrent() && state.eligible
      ? state.query.refetch({ ...options, cancelRefetch: false })
      : Promise.resolve(state.query);
  }, [apiClient]);
  return { ...query, eligible, refetch };
}

export function useTeamCalendar(
  unit: TeamUnit | null,
  filters: calendarApi.TeamCalendarFilters,
  enabled = true,
) {
  const apiClient = useApiClient();
  const identity = useIdentity();
  const realtime = useRealtimeReady();
  const eligible = useActiveScreen(enabled && identity.tenantId > 0 && identity.userId > 0 && unit !== null);
  const query = useQuery({
    queryKey: teamCalendarQueryKeys.list(identity.tenantId, identity.userId, unit, filters),
    queryFn: () => calendarApi.list(requireUnit(unit), filters, apiClient),
    enabled: eligible,
    staleTime: CALENDAR_POLL_MS,
    refetchInterval: eligible && !realtime ? CALENDAR_POLL_MS : false,
    refetchIntervalInBackground: false,
    // A scheduled retry can otherwise start after the screen loses eligibility.
    // Manual retry and the next eligible fallback poll retain the cached result.
    retry: false,
  });
  return useEligibleQuery(query, eligible);
}

export function useTeamCalendarEvent(unit: TeamUnit | null, id: number, enabled = true) {
  const apiClient = useApiClient();
  const identity = useIdentity();
  const realtime = useRealtimeReady();
  const eligible = useActiveScreen(enabled && identity.tenantId > 0 && identity.userId > 0 && unit !== null && id > 0);
  const query = useQuery({
    queryKey: teamCalendarQueryKeys.event(identity.tenantId, identity.userId, unit, id),
    queryFn: () => calendarApi.detail(requireUnit(unit), id, apiClient),
    enabled: eligible,
    staleTime: CALENDAR_POLL_MS,
    refetchInterval: eligible && !realtime ? CALENDAR_POLL_MS : false,
    refetchIntervalInBackground: false,
    retry: false,
  });
  return useEligibleQuery(query, eligible);
}

/** Preserve server pagination rather than permanently caching the first 100 people. */
export function useTeamCalendarMemberSearch(unit: TeamUnit | null, query: string, requestedPage = 1, enabled = true) {
  const apiClient = useApiClient();
  const identity = useIdentity();
  const q = query.trim().slice(0, 80);
  const page = Math.min(100_000, Math.max(1, Math.trunc(requestedPage) || 1));
  const eligible = useActiveScreen(enabled && identity.tenantId > 0 && identity.userId > 0 && unit !== null);
  const result = useQuery({
    queryKey: teamCalendarQueryKeys.members(identity.tenantId, identity.userId, unit, q, page),
    queryFn: () => tasksApi.members(requireUnit(unit), { q, page }, apiClient),
    enabled: eligible,
    staleTime: CALENDAR_POLL_MS,
    // Old search terms are short-lived; selections belong to the form, not this cache.
    gcTime: 60_000,
    retry: false,
  });
  return useEligibleQuery(result, eligible);
}

/** Only a user-initiated refresh owns the native pull-to-refresh indicator. */
export function useCalendarManualRefresh(query: { eligible: boolean; refetch: () => Promise<unknown> }) {
  const apiClient = useApiClient();
  const [refreshing, setRefreshing] = useState(false);
  const busy = useRef(false);
  const mounted = useRef(true);
  const current = useRef(query);
  current.current = query;
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);
  const refresh = useCallback(async () => {
    if (!apiClient.isCurrent() || busy.current || !current.current.eligible) { return; }
    busy.current = true;
    setRefreshing(true);
    try {
      await current.current.refetch();
    } finally {
      busy.current = false;
      if (mounted.current) { setRefreshing(false); }
    }
  }, [apiClient]);
  return { refreshing, refresh };
}

/** Refresh entitlements only after a denied read, never for a record mutation 403. */
export function useTeamCalendarAccess(unit: TeamUnit | null) {
  const apiClient = useApiClient();
  const { user, refreshProfile } = useAuth();
  const client = useQueryClient();
  const identity = `${user?.tenant_id ?? 0}:${user?.id ?? 0}:${unit ?? 'none'}`;
  const currentIdentity = useRef(identity);
  currentIdentity.current = identity;
  const recovering = useRef<string | null>(null);
  const [deniedIdentity, setDeniedIdentity] = useState<string | null>(null);
  const [refreshingAccess, setRefreshingAccess] = useState(false);
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);

  const recoverAccess = useCallback(async () => {
    if (!apiClient.isCurrent() || !unit || !user || recovering.current === identity || deniedIdentity === identity) { return; }
    recovering.current = identity;
    setDeniedIdentity(identity);
    setRefreshingAccess(true);
    try {
      const key = teamCalendarQueryKeys.unit(user.tenant_id, user.id, unit);
      await client.cancelQueries({ queryKey: key, exact: false });
      client.removeQueries({ queryKey: key, exact: false });
      // A successful profile refresh reconciles workspace selection without a splash.
      apiClient.assertCurrent();
      await refreshProfile();
    } catch {
      // Keep the denied screen and an explicit recovery action on transient failure.
    } finally {
      if (recovering.current === identity) { recovering.current = null; }
      if (mounted.current && currentIdentity.current === identity) { setRefreshingAccess(false); }
    }
  }, [apiClient, client, deniedIdentity, identity, refreshProfile, unit, user]);

  const retryAccess = useCallback(async () => {
    if (!apiClient.isCurrent() || recovering.current === identity) { return; }
    recovering.current = identity;
    setRefreshingAccess(true);
    try {
      apiClient.assertCurrent();
      await refreshProfile();
      if (mounted.current && currentIdentity.current === identity) { setDeniedIdentity(null); }
    } catch {
      // Remain blocked; a network error must not pretend access was restored.
    } finally {
      if (recovering.current === identity) { recovering.current = null; }
      if (mounted.current && currentIdentity.current === identity) { setRefreshingAccess(false); }
    }
  }, [apiClient, identity, refreshProfile]);

  return { accessDenied: deniedIdentity === identity, refreshingAccess, recoverAccess, retryAccess };
}

/** Follow Manila midnight and re-check after a suspended app returns to foreground. */
export function useManilaToday(): string {
  const [today, setToday] = useState(() => todayInManila());
  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let foreground = AppState.currentState !== 'background' && AppState.currentState !== 'inactive';
    const update = () => {
      if (timer !== undefined) { clearTimeout(timer); }
      const now = new Date();
      const day = todayInManila(now);
      setToday(day);
      if (foreground) {
        timer = setTimeout(update, Math.max(1, manilaDayBounds(day).end - now.getTime()));
      }
    };
    const subscription = AppState.addEventListener('change', state => {
      foreground = state === 'active';
      if (foreground) { update(); }
      else if (timer !== undefined) { clearTimeout(timer); }
    });
    update();
    return () => {
      if (timer !== undefined) { clearTimeout(timer); }
      subscription.remove();
    };
  }, []);
  return today;
}

type CalendarResponse = { data: calendarApi.TeamCalendarEventDetail };
type CalendarOrigin = { tenantId: number; userId: number; unit: TeamUnit | null; generation: number };
type CalendarOperation<Input> = {
  input: Input;
  origin: CalendarOrigin;
  request: (unit: TeamUnit, input: Input, client: ApiClient) => Promise<CalendarResponse>;
};

function requireOnlineUnit(unit: TeamUnit | null): TeamUnit {
  if (!onlineManager.isOnline()) { throw new NetworkError(); }
  return requireUnit(unit);
}

/** Capture the endpoint and scope at invocation, even if mutation options rerender. */
function useCalendarMutation<Input>(unit: TeamUnit | null, request: CalendarOperation<Input>['request']) {
  const { user, profile } = useAuth();
  const client = useQueryClient();
  const identity = `${user?.tenant_id ?? 0}:${user?.id ?? 0}`;
  const previousIdentity = useRef(identity);
  const generation = useRef(0);
  if (previousIdentity.current !== identity) {
    previousIdentity.current = identity;
    generation.current += 1;
  }
  const current = useRef({ user, profile, unit, request });
  current.current = { user, profile, unit, request };
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);
  const mutation = useApiMutation({
    mutationFn: (operation: CalendarOperation<Input>, apiClient) =>
      operation.request(requireOnlineUnit(operation.origin.unit), operation.input, apiClient),
    retry: false,
    networkMode: 'always',
    onSuccess: async (response, operation) => {
      const origin = operation.origin;
      const state = current.current;
      if (!mounted.current || generation.current !== origin.generation
        || state.user?.tenant_id !== origin.tenantId || state.user.id !== origin.userId
        || origin.unit === null) { return; }
      if (state.profile && (!state.profile.entitlements.work.enabled
        || !state.profile.entitlements.work.units.some(item => item.unit === origin.unit && item.features.team_calendar))) { return; }
      try {
        client.setQueryData<CalendarResponse>(
          teamCalendarQueryKeys.event(origin.tenantId, origin.userId, origin.unit, response.data.id),
          cached => cached && cached.data.version > response.data.version ? cached : response,
        );
        // The prefix includes detail queries; one reconciliation is sufficient.
        await client.invalidateQueries({
          queryKey: teamCalendarQueryKeys.unit(origin.tenantId, origin.userId, origin.unit),
          exact: false,
        });
      } catch {
        // Acknowledged writes are saved, even if subsequent cache refresh fails.
        // Propagating this error would incorrectly offer an ambiguous create retry.
      }
    },
  });
  const operation = (input: Input): CalendarOperation<Input> => {
    const state = current.current;
    return {
      input,
      origin: { tenantId: state.user?.tenant_id ?? 0, userId: state.user?.id ?? 0, unit: state.unit, generation: generation.current },
      request: state.request,
    };
  };
  return {
    ...mutation,
    variables: mutation.variables?.input,
    mutate: (input: Input) => mutation.mutate(operation(input)),
    mutateAsync: (input: Input) => mutation.mutateAsync(operation(input)),
  };
}

export function useCreateTeamCalendarEvent(unit: TeamUnit | null) {
  return useCalendarMutation(unit, calendarApi.create);
}

export function useUpdateTeamCalendarEvent(unit: TeamUnit | null, id: number) {
  return useCalendarMutation(unit, (target, input: calendarApi.TeamCalendarEventUpdateInput, apiClient) => calendarApi.update(target, id, input, apiClient));
}

export function useCancelTeamCalendarEvent(unit: TeamUnit | null, id: number) {
  return useCalendarMutation(unit, (target, version: number, apiClient) => calendarApi.cancel(target, id, version, apiClient));
}
