import React, { useEffect } from 'react';
import { AppState, type AppStateStatus, Modal, Platform } from 'react-native';
import { useIsFocused } from '@react-navigation/native';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { focusManager, onlineManager, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ApiError, NetworkError } from '../src/api/client';
import type { MeResponse, TeamUnit } from '../src/api/types';
import * as calendar from '../src/api/endpoints/teamCalendar';
import * as tasks from '../src/api/endpoints/teamTasks';
import { ConfirmDialog } from '../src/components/ConfirmDialog';
import { OverlayProvider, useOverlayActivity } from '../src/components/OverlayProvider';
import { useAuth } from '../src/store/auth';
import {
  teamCalendarQueryKeys,
  useCalendarManualRefresh,
  useCancelTeamCalendarEvent,
  useCreateTeamCalendarEvent,
  useManilaToday,
  useTeamCalendar,
  useTeamCalendarAccess,
  useTeamCalendarEvent,
  useTeamCalendarMemberSearch,
  useUpdateTeamCalendarEvent,
} from '../src/features/team-calendar/hooks';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/api/endpoints/teamCalendar', () => ({ list: jest.fn(), detail: jest.fn(), create: jest.fn(), update: jest.fn(), cancel: jest.fn() }));
jest.mock('../src/api/endpoints/teamTasks', () => ({ members: jest.fn() }));
jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('@react-navigation/native', () => ({ useIsFocused: jest.fn() }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: () => true }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }) }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }));

const originalAppState = AppState.currentState;
const event: calendar.TeamCalendarEventDetail = {
  type: 'event', id: 4, title: 'Planning', description: '', location: 'Room 1', status: 'scheduled',
  starts_at: '2026-12-10T09:00:00+08:00', ends_at: '2026-12-10T10:00:00+08:00', participants: [],
  version: 2, can_edit: true, created_at: '2026-12-01T00:00:00Z', updated_at: '2026-12-01T00:00:00Z',
};
const editable: calendar.TeamCalendarEventEditableInput = { title: 'Planning', description: '', location: 'Room 1', starts_at: '2026-12-10T09:00', ends_at: '2026-12-10T10:00', participant_ids: [7] };
const input: calendar.TeamCalendarEventCreateInput = { ...editable, client_nonce: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee' };
const refreshProfile = jest.fn();
let user: { id: number; tenant_id: number } | null;
let profile: MeResponse | undefined;
let client: QueryClient;
let renderer: ReactTestRenderer | undefined;
let unit: TeamUnit | null;
let filters: calendar.TeamCalendarFilters;
let memberQ: string;
let memberPage: number;
let detailId: number;
let enabled: boolean;
let overlayVisible: boolean;
let focused: boolean;
let Component: React.ComponentType;
let appListeners: Set<(state: AppStateStatus) => void>;
let listResult: ReturnType<typeof useTeamCalendar>;
let detailResult: ReturnType<typeof useTeamCalendarEvent>;
let memberResult: ReturnType<typeof useTeamCalendarMemberSearch>;
let createResult: ReturnType<typeof useCreateTeamCalendarEvent>;
let updateResult: ReturnType<typeof useUpdateTeamCalendarEvent>;
let cancelResult: ReturnType<typeof useCancelTeamCalendarEvent>;
let recoveryResult: ReturnType<typeof useTeamCalendarAccess>;
let manualResult: ReturnType<typeof useCalendarManualRefresh>;
let today: string;
let overlayBusy: boolean;

function listData(): Awaited<ReturnType<typeof calendar.list>> {
  return { data: [event], meta: { date: filters.date, view: filters.view, scope: filters.scope, start_date: '2026-12-10', end_date: '2027-01-09', total_events: 1, total_tasks: 0, truncated: false } };
}
function memberData(page = 1, name = 'Alex'): Awaited<ReturnType<typeof tasks.members>> {
  return { data: [{ id: page, name, role: 'staff' }], meta: { unit: 'clinic', total: 301, page, pages: 7, limit: 50 } };
}
function ListProbe() { listResult = useTeamCalendar(unit, filters, enabled); return null; }
function DetailProbe() { detailResult = useTeamCalendarEvent(unit, detailId, enabled); return null; }
function MemberProbe() { memberResult = useTeamCalendarMemberSearch(unit, memberQ, memberPage, enabled); return null; }
function QueryProbes() { return <><ListProbe /><DetailProbe /><MemberProbe /></>; }
function MutationProbe() {
  createResult = useCreateTeamCalendarEvent(unit);
  updateResult = useUpdateTeamCalendarEvent(unit, detailId);
  cancelResult = useCancelTeamCalendarEvent(unit, detailId);
  return null;
}
function RecoveryProbe() {
  const recovery = useTeamCalendarAccess(unit);
  const detail = useTeamCalendarEvent(unit, detailId, !recovery.accessDenied);
  recoveryResult = recovery;
  detailResult = detail;
  const { recoverAccess } = recovery;
  useEffect(() => {
    if (detail.isError && detail.error instanceof ApiError && detail.error.status === 403) {
      recoverAccess().catch(() => undefined);
    }
  }, [detail.error, detail.isError, recoverAccess]);
  return null;
}
function ManualProbe() { listResult = useTeamCalendar(unit, filters); manualResult = useCalendarManualRefresh(listResult); return null; }
function TodayProbe() { today = useManilaToday(); return null; }
function OverlayProbe() { overlayBusy = useOverlayActivity(); return null; }
function Host() {
  return (
    <QueryClientProvider client={client}>
      <OverlayProvider>
        <OverlayProbe /><Component />
        <ConfirmDialog visible={overlayVisible} title="Workspace overlay" message="Read queries pause behind this dialog." confirmLabel="Continue" onConfirm={() => undefined} onCancel={() => undefined} />
      </OverlayProvider>
    </QueryClientProvider>
  );
}
function render(next: React.ComponentType) { Component = next; act(() => { renderer = create(<Host />); }); }
function rerender() { act(() => renderer!.update(<Host />)); }
async function flush(ms = 0) {
  await act(async () => { await Promise.resolve(); await jest.advanceTimersByTimeAsync(ms); });
  // Flush observer notifications scheduled by promise completion in the first batch.
  await act(async () => { await jest.advanceTimersByTimeAsync(0); });
}
function setFocused(value: boolean) { focused = value; rerender(); }
function setAppState(state: AppStateStatus) {
  act(() => { AppState.currentState = state; appListeners.forEach(listener => listener(state)); });
}
function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  jest.setSystemTime(new Date('2026-12-10T01:00:00Z'));
  jest.replaceProperty(Platform, 'OS', 'android');
  AppState.currentState = 'active';
  appListeners = new Set();
  jest.spyOn(AppState, 'addEventListener').mockImplementation((_event, listener) => {
    appListeners.add(listener);
    return { remove: () => appListeners.delete(listener) };
  });
  focused = true;
  jest.mocked(useIsFocused).mockImplementation(() => focused);
  onlineManager.setOnline(true);
  focusManager.setFocused(true);
  unit = 'clinic'; user = { id: 7, tenant_id: 3 }; profile = undefined;
  jest.mocked(useAuth).mockImplementation(() => ({ user, profile, refreshProfile } as unknown as ReturnType<typeof useAuth>));
  filters = { date: '2026-12-10', view: 'agenda', scope: 'team', status: 'scheduled' };
  memberQ = ''; memberPage = 1; detailId = 4; enabled = true; overlayVisible = false;
  client = new QueryClient({ defaultOptions: { queries: { retry: 3, gcTime: Infinity, staleTime: 0 }, mutations: { retry: 3 } } });
  jest.mocked(calendar.list).mockReset().mockImplementation(async () => listData());
  jest.mocked(calendar.detail).mockReset().mockResolvedValue({ data: event });
  jest.mocked(tasks.members).mockReset().mockImplementation(async (_unit, values) => memberData(values?.page));
  jest.mocked(calendar.create).mockReset().mockResolvedValue({ data: event });
  jest.mocked(calendar.update).mockReset().mockResolvedValue({ data: event });
  jest.mocked(calendar.cancel).mockReset().mockResolvedValue({ data: { ...event, status: 'cancelled', version: 3 } });
  refreshProfile.mockReset().mockResolvedValue(undefined);
});
afterEach(() => {
  act(() => renderer?.unmount()); renderer = undefined;
  client.clear();
  act(() => jest.runOnlyPendingTimers());
  jest.restoreAllMocks();
  AppState.currentState = originalAppState;
  onlineManager.setOnline(true); focusManager.setFocused(undefined);
  jest.useRealTimers();
});

describe('real Calendar query eligibility and refresh', () => {
  it('fetches list/detail only when focused, foreground, online and uncovered, with a 30-second fallback', async () => {
    focused = false;
    render(QueryProbes); await flush();
    expect(calendar.list).not.toHaveBeenCalled(); expect(calendar.detail).not.toHaveBeenCalled(); expect(tasks.members).not.toHaveBeenCalled();
    setFocused(true); await flush();
    expect(calendar.list).toHaveBeenCalledTimes(1); expect(calendar.detail).toHaveBeenCalledTimes(1); expect(tasks.members).toHaveBeenCalledTimes(1);
    await flush(29_999);
    expect(calendar.list).toHaveBeenCalledTimes(1);
    await flush(1);
    expect(calendar.list).toHaveBeenCalledTimes(2); expect(calendar.detail).toHaveBeenCalledTimes(2); expect(tasks.members).toHaveBeenCalledTimes(1);
    setFocused(false); await flush(60_000);
    expect(calendar.list).toHaveBeenCalledTimes(2);
    await act(async () => { await listResult.refetch(); await detailResult.refetch(); await memberResult.refetch(); });
    expect(calendar.list).toHaveBeenCalledTimes(2); expect(calendar.detail).toHaveBeenCalledTimes(2); expect(tasks.members).toHaveBeenCalledTimes(1);
    setFocused(true); await flush();
    expect(calendar.list).toHaveBeenCalledTimes(3); expect(calendar.detail).toHaveBeenCalledTimes(3); expect(tasks.members).toHaveBeenCalledTimes(2);
    setAppState('background'); await flush(60_000);
    expect(calendar.list).toHaveBeenCalledTimes(3); expect(calendar.detail).toHaveBeenCalledTimes(3);
    setAppState('active'); await flush();
    expect(calendar.list).toHaveBeenCalledTimes(4); expect(calendar.detail).toHaveBeenCalledTimes(4);
    act(() => onlineManager.setOnline(false)); await flush(60_000);
    expect(calendar.list).toHaveBeenCalledTimes(4);
    await act(async () => { await listResult.refetch(); });
    expect(calendar.list).toHaveBeenCalledTimes(4);
    act(() => onlineManager.setOnline(true)); await flush();
    expect(calendar.list).toHaveBeenCalledTimes(5);
  });

  it('pauses active observers behind a real overlay and resumes stale caches only after native exit', async () => {
    render(QueryProbes); await flush();
    expect(calendar.list).toHaveBeenCalledTimes(1);
    overlayVisible = true; rerender();
    expect(overlayBusy).toBe(true); expect(listResult.eligible).toBe(false);
    await flush(60_000);
    expect(calendar.list).toHaveBeenCalledTimes(1); expect(calendar.detail).toHaveBeenCalledTimes(1); expect(tasks.members).toHaveBeenCalledTimes(1);
    await act(async () => { await listResult.refetch(); await memberResult.refetch(); });
    expect(calendar.list).toHaveBeenCalledTimes(1);
    overlayVisible = false; rerender();
    expect(overlayBusy).toBe(true);
    const visible = renderer!.root.findAllByType(Modal).filter(node => node.props.visible);
    expect(visible).toHaveLength(0);
    await flush(40);
    expect(overlayBusy).toBe(false);
    expect(listResult.eligible).toBe(true);
    expect(calendar.list).toHaveBeenCalledTimes(2); expect(calendar.detail).toHaveBeenCalledTimes(2); expect(tasks.members).toHaveBeenCalledTimes(2);
  });

  it('keeps cached data on transient failures, never schedules hidden retries, and gives manual refresh its own spinner', async () => {
    render(ManualProbe); await flush();
    const cached = listResult.data;
    const pending = deferred<Awaited<ReturnType<typeof calendar.list>>>();
    jest.mocked(calendar.list).mockImplementationOnce(() => pending.promise);
    await flush(30_000);
    await flush(1);
    expect(listResult.isFetching).toBe(true); expect(manualResult.refreshing).toBe(false); expect(listResult.data).toBe(cached);
    act(() => { manualResult.refresh(); manualResult.refresh(); });
    expect(manualResult.refreshing).toBe(true);
    expect(calendar.list).toHaveBeenCalledTimes(2);
    await act(async () => pending.reject(new NetworkError())); await flush();
    expect(manualResult.refreshing).toBe(false); expect(listResult.isError).toBe(true); expect(listResult.data).toBe(cached);
    setFocused(false); await flush(120_000);
    expect(calendar.list).toHaveBeenCalledTimes(2);
    await act(async () => { await manualResult.refresh(); });
    expect(calendar.list).toHaveBeenCalledTimes(2);
    setFocused(true); await flush();
    expect(calendar.list).toHaveBeenCalledTimes(3); expect(listResult.isSuccess).toBe(true);
  });

  it('never exposes a previous unit, filter, record or identity as the new query data', async () => {
    render(QueryProbes); await flush();
    expect(listResult.data?.data[0].id).toBe(4);
    jest.mocked(calendar.list).mockImplementation(() => new Promise(() => undefined));
    jest.mocked(calendar.detail).mockImplementation(() => new Promise(() => undefined));
    jest.mocked(tasks.members).mockImplementation(() => new Promise(() => undefined));
    filters = { ...filters, scope: 'mine', view: 'week' }; detailId = 9; memberQ = 'other'; rerender();
    expect(listResult.data).toBeUndefined(); expect(detailResult.data).toBeUndefined(); expect(memberResult.data).toBeUndefined();
    unit = 'guidance'; rerender();
    expect(listResult.data).toBeUndefined(); expect(memberResult.data).toBeUndefined();
    user = { id: 8, tenant_id: 4 }; rerender();
    expect(listResult.data).toBeUndefined(); expect(detailResult.data).toBeUndefined();
    expect(client.getQueryData(teamCalendarQueryKeys.event(3, 7, 'clinic', 4))).toEqual({ data: event });
  });

  it('does not fetch with missing identity/unit or an explicitly disabled screen', async () => {
    enabled = false; render(QueryProbes); await flush();
    expect(calendar.list).not.toHaveBeenCalled();
    enabled = true; user = null; rerender(); await flush(); expect(calendar.list).not.toHaveBeenCalled();
    user = { id: 7, tenant_id: 3 }; unit = null; rerender(); await flush(); expect(calendar.list).not.toHaveBeenCalled();
    unit = 'clinic'; detailId = 0; rerender(); await flush();
    expect(calendar.list).toHaveBeenCalledTimes(1); expect(calendar.detail).not.toHaveBeenCalled();
  });
});

describe('real Calendar member paging and freshness', () => {
  it('retains pagination metadata past page five and accepts explicit error retries', async () => {
    memberPage = 6; render(MemberProbe); await flush();
    expect(tasks.members).toHaveBeenCalledWith('clinic', { q: '', page: 6 });
    expect(memberResult.data?.meta).toEqual({ unit: 'clinic', total: 301, page: 6, pages: 7, limit: 50 });
    jest.mocked(tasks.members).mockRejectedValueOnce(new NetworkError());
    memberPage = 7; rerender(); await flush();
    expect(memberResult.isError).toBe(true); expect(memberResult.data).toBeUndefined();
    expect(tasks.members).toHaveBeenCalledTimes(2);
    await act(async () => { await memberResult.refetch(); }); await flush();
    expect(tasks.members).toHaveBeenLastCalledWith('clinic', { q: '', page: 7 });
    expect(memberResult.data?.meta.page).toBe(7);
  });

  it('normalizes search and honors cache freshness/invalidation instead of an unbounded private ref cache', async () => {
    memberQ = '  Alex  '; render(MemberProbe); await flush();
    expect(tasks.members).toHaveBeenCalledWith('clinic', { q: 'Alex', page: 1 });
    memberPage = 2; rerender(); await flush();
    memberPage = 1; rerender(); await flush();
    expect(tasks.members).toHaveBeenCalledTimes(2);
    await act(async () => { await client.invalidateQueries({ queryKey: teamCalendarQueryKeys.unit(3, 7, 'clinic') }); }); await flush();
    expect(tasks.members).toHaveBeenCalledTimes(3);
    setFocused(false); await flush(30_001); setFocused(true); await flush();
    expect(tasks.members).toHaveBeenCalledTimes(4);
    memberQ = ` ${'x'.repeat(90)} `; rerender(); await flush();
    expect(tasks.members).toHaveBeenLastCalledWith('clinic', { q: 'x'.repeat(80), page: 1 });
  });

  it('isolates late search responses from a newer search and another unit', async () => {
    const late = deferred<Awaited<ReturnType<typeof tasks.members>>>();
    jest.mocked(tasks.members).mockImplementationOnce(() => late.promise);
    render(MemberProbe); await flush();
    memberQ = 'Bea'; rerender(); await flush();
    expect(memberResult.data?.data[0].name).toBe('Alex');
    await act(async () => late.resolve(memberData(1, 'Old result'))); await flush();
    expect(memberResult.data?.data[0].name).toBe('Alex');
    const newer = deferred<Awaited<ReturnType<typeof tasks.members>>>();
    jest.mocked(tasks.members).mockImplementationOnce(() => newer.promise);
    unit = 'guidance'; rerender(); expect(memberResult.data).toBeUndefined();
    unit = 'facilities'; rerender(); await flush();
    await act(async () => newer.resolve(memberData(2, 'Guidance only'))); await flush();
    expect(memberResult.data?.data[0].name).not.toBe('Guidance only');
  });
});

describe('real Calendar Manila day rollover', () => {
  it('updates at Manila midnight independently of device timezone', async () => {
    jest.setSystemTime(new Date('2026-12-10T15:59:59Z')); render(TodayProbe);
    expect(today).toBe('2026-12-10');
    await flush(1000); expect(today).toBe('2026-12-11');
    await flush(86_400_000); expect(today).toBe('2026-12-12');
  });

  it('recomputes after suspended foreground return without scheduling background midnight work', async () => {
    render(TodayProbe); expect(today).toBe('2026-12-10');
    setAppState('background');
    jest.setSystemTime(new Date('2026-12-13T17:00:00Z'));
    await flush(); expect(today).toBe('2026-12-10');
    setAppState('active'); expect(today).toBe('2026-12-14');
  });
});

describe('real Calendar denied-access reconciliation', () => {
  it('purges denied active observer data and only that unit, without automatic profile/refetch loops', async () => {
    const denied = teamCalendarQueryKeys.event(3, 7, 'clinic', 4);
    const other = teamCalendarQueryKeys.event(3, 7, 'guidance', 4);
    client.setQueryData(denied, { data: event }, { updatedAt: Date.now() - 60_000 });
    client.setQueryData(teamCalendarQueryKeys.list(3, 7, 'clinic', filters), listData());
    client.setQueryData(teamCalendarQueryKeys.members(3, 7, 'clinic', '', 1), memberData());
    client.setQueryData(other, { data: event });
    client.setQueryData(['appointments'], ['kept']);
    jest.mocked(calendar.detail).mockRejectedValue(new ApiError(403, { code: 'forbidden', message: 'Membership required' }));
    render(RecoveryProbe); await flush(); await flush();
    expect(calendar.detail).toHaveBeenCalledTimes(1);
    expect(recoveryResult.accessDenied).toBe(true); expect(detailResult.eligible).toBe(false);
    expect(detailResult.data).toBeUndefined(); expect(client.getQueryData(denied)).toBeUndefined();
    expect(client.getQueryData(teamCalendarQueryKeys.list(3, 7, 'clinic', filters))).toBeUndefined();
    expect(client.getQueryData(other)).toEqual({ data: event }); expect(client.getQueryData(['appointments'])).toEqual(['kept']);
    expect(refreshProfile).toHaveBeenCalledTimes(1);
    rerender(); await flush(120_000);
    expect(calendar.detail).toHaveBeenCalledTimes(1); expect(refreshProfile).toHaveBeenCalledTimes(1);
    await act(async () => { await detailResult.refetch(); });
    expect(calendar.detail).toHaveBeenCalledTimes(1);
  });

  it('keeps denied state on transient profile recovery failure and retries only explicitly', async () => {
    jest.mocked(calendar.detail).mockRejectedValue(new ApiError(403, { code: 'forbidden', message: 'Membership required' }));
    refreshProfile.mockRejectedValueOnce(new NetworkError());
    render(RecoveryProbe); await flush();
    expect(recoveryResult.accessDenied).toBe(true); expect(refreshProfile).toHaveBeenCalledTimes(1);
    refreshProfile.mockRejectedValueOnce(new NetworkError());
    await act(async () => { await recoveryResult.retryAccess(); }); await flush();
    expect(recoveryResult.accessDenied).toBe(true); expect(calendar.detail).toHaveBeenCalledTimes(1);
    jest.mocked(calendar.detail).mockResolvedValue({ data: event });
    await act(async () => { await recoveryResult.retryAccess(); }); await flush();
    expect(recoveryResult.accessDenied).toBe(false); expect(detailResult.data).toEqual({ data: event });
    expect(calendar.detail).toHaveBeenCalledTimes(2);
  });
});

describe('real Calendar mutation acknowledgement and origin safety', () => {
  it('sends exact versions/payloads and invalidates one origin prefix for each acknowledged write', async () => {
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    render(MutationProbe);
    await act(async () => {
      await createResult.mutateAsync(input);
      await updateResult.mutateAsync({ ...editable, version: 2 });
      await cancelResult.mutateAsync(3);
    });
    expect(calendar.create).toHaveBeenCalledWith('clinic', input);
    expect(jest.mocked(calendar.create).mock.calls[0][1]).toBe(input);
    expect(calendar.update).toHaveBeenCalledWith('clinic', 4, { ...editable, version: 2 });
    expect(calendar.cancel).toHaveBeenCalledWith('clinic', 4, 3);
    expect(invalidate).toHaveBeenCalledTimes(3);
    invalidate.mock.calls.forEach(call => expect(call).toEqual([{ queryKey: teamCalendarQueryKeys.unit(3, 7, 'clinic'), exact: false }]));
    expect(client.getQueryData(teamCalendarQueryKeys.event(3, 7, 'clinic', 4))).toEqual({ data: { ...event, status: 'cancelled', version: 3 } });
  });

  it('never automatically replays a failed create even when client defaults request retries', async () => {
    jest.mocked(calendar.create).mockRejectedValueOnce(new NetworkError());
    render(MutationProbe);
    await act(async () => { await expect(createResult.mutateAsync(input)).rejects.toBeInstanceOf(NetworkError); });
    await flush(120_000);
    expect(calendar.create).toHaveBeenCalledTimes(1);
    await act(async () => { await createResult.mutateAsync(input); });
    expect(calendar.create).toHaveBeenCalledTimes(2); expect(jest.mocked(calendar.create).mock.calls[1][1]).toBe(input);
  });

  it('rejects rather than parks create/update/cancel while offline, and reconnect cannot execute them', async () => {
    onlineManager.setOnline(false); render(MutationProbe);
    await act(async () => {
      await expect(createResult.mutateAsync(input)).rejects.toBeInstanceOf(NetworkError);
      await expect(updateResult.mutateAsync({ ...editable, version: 2 })).rejects.toBeInstanceOf(NetworkError);
      await expect(cancelResult.mutateAsync(2)).rejects.toBeInstanceOf(NetworkError);
    });
    expect(createResult.isPaused).toBe(false); expect(updateResult.isPaused).toBe(false); expect(cancelResult.isPaused).toBe(false);
    expect(calendar.create).not.toHaveBeenCalled(); expect(calendar.update).not.toHaveBeenCalled(); expect(calendar.cancel).not.toHaveBeenCalled();
    act(() => onlineManager.setOnline(true)); await flush(60_000);
    expect(calendar.create).not.toHaveBeenCalled(); expect(calendar.update).not.toHaveBeenCalled(); expect(calendar.cancel).not.toHaveBeenCalled();
  });

  it('keeps an acknowledged write successful if cache refresh rejects instead of offering ambiguous replay', async () => {
    jest.spyOn(client, 'invalidateQueries').mockRejectedValueOnce(new Error('Refresh failed'));
    render(MutationProbe);
    await act(async () => { await expect(createResult.mutateAsync(input)).resolves.toEqual({ data: event }); }); await flush();
    expect(createResult.isSuccess).toBe(true); expect(calendar.create).toHaveBeenCalledTimes(1);
  });

  it('does not retarget pending endpoint or invalidation when the record/unit changes', async () => {
    const pending = deferred<{ data: calendar.TeamCalendarEventDetail }>();
    jest.mocked(calendar.update).mockImplementationOnce(() => pending.promise);
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    render(MutationProbe);
    let saved!: Promise<{ data: calendar.TeamCalendarEventDetail }>;
    act(() => { saved = updateResult.mutateAsync({ ...editable, version: 2 }); });
    unit = 'guidance'; detailId = 9; rerender(); await flush();
    expect(calendar.update).toHaveBeenCalledWith('clinic', 4, { ...editable, version: 2 });
    await act(async () => { pending.resolve({ data: event }); await saved; });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: teamCalendarQueryKeys.unit(3, 7, 'clinic'), exact: false });
    expect(client.getQueryData(teamCalendarQueryKeys.event(3, 7, 'guidance', 4))).toBeUndefined();
  });

  it('never resurrects old identity data after logout and return to the same account', async () => {
    const pending = deferred<{ data: calendar.TeamCalendarEventDetail }>();
    jest.mocked(calendar.create).mockImplementationOnce(() => pending.promise);
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    render(MutationProbe);
    let saved!: Promise<{ data: calendar.TeamCalendarEventDetail }>;
    act(() => { saved = createResult.mutateAsync(input); }); await flush();
    user = null; rerender(); client.clear();
    user = { id: 7, tenant_id: 3 }; rerender();
    await act(async () => { pending.resolve({ data: event }); await saved; });
    expect(invalidate).not.toHaveBeenCalled(); expect(client.getQueryData(teamCalendarQueryKeys.event(3, 7, 'clinic', 4))).toBeUndefined();
  });

  it('ignores late cache reconciliation after unmount', async () => {
    const pending = deferred<{ data: calendar.TeamCalendarEventDetail }>();
    jest.mocked(calendar.create).mockImplementationOnce(() => pending.promise);
    render(MutationProbe);
    let saved!: Promise<{ data: calendar.TeamCalendarEventDetail }>;
    act(() => { saved = createResult.mutateAsync(input); }); await flush();
    act(() => renderer!.unmount()); renderer = undefined;
    await act(async () => { pending.resolve({ data: event }); await saved; });
    expect(client.getQueryData(teamCalendarQueryKeys.event(3, 7, 'clinic', 4))).toBeUndefined();
  });

  it('does not resurrect a unit whose calendar access was revoked while its write was pending', async () => {
    const pending = deferred<{ data: calendar.TeamCalendarEventDetail }>();
    jest.mocked(calendar.create).mockImplementationOnce(() => pending.promise);
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    render(MutationProbe);
    let saved!: Promise<{ data: calendar.TeamCalendarEventDetail }>;
    act(() => { saved = createResult.mutateAsync(input); }); await flush();
    profile = { entitlements: { work: { enabled: true, units: [] } } } as unknown as MeResponse;
    rerender();
    await act(async () => { pending.resolve({ data: event }); await saved; });
    expect(invalidate).not.toHaveBeenCalled();
    expect(client.getQueryData(teamCalendarQueryKeys.event(3, 7, 'clinic', 4))).toBeUndefined();
  });

  it('does not downgrade a newer cached version with an older acknowledged response', async () => {
    client.setQueryData(teamCalendarQueryKeys.event(3, 7, 'clinic', 4), { data: { ...event, version: 4, title: 'Newer version' } });
    render(MutationProbe);
    await act(async () => { await updateResult.mutateAsync({ ...editable, version: 2 }); });
    expect(client.getQueryData(teamCalendarQueryKeys.event(3, 7, 'clinic', 4))).toEqual({ data: { ...event, version: 4, title: 'Newer version' } });
  });

  it('does not turn denied record mutations into lost unit access or profile recovery', async () => {
    jest.mocked(calendar.cancel).mockRejectedValueOnce(new ApiError(403, { code: 'forbidden', message: 'Not organizer' }));
    const invalidate = jest.spyOn(client, 'invalidateQueries'); render(MutationProbe);
    await act(async () => { await expect(cancelResult.mutateAsync(2)).rejects.toBeInstanceOf(ApiError); });
    expect(invalidate).not.toHaveBeenCalled(); expect(refreshProfile).not.toHaveBeenCalled();
  });
});
