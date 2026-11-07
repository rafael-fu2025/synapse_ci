import React from 'react';
import { QueryClient, QueryClientProvider, focusManager, onlineManager } from '@tanstack/react-query';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { ApiError, NetworkError } from '../src/api/client';
import * as api from '../src/api/endpoints/teamTasks';
import { queryKeys } from '../src/api/queryKeys';
import type { TeamUnit } from '../src/api/types';
import { useActiveScreen } from '../src/lib/useActiveScreen';
import { invalidateTeamTasks } from '../src/features/workspace/invalidateTeamResources';
import { useChangeTeamTaskStatus, useCommentTeamTask, useCreateTeamTask, useTeamMemberSearch, useTeamTask, useTeamTaskAccessRecovery, useTeamTasks, useUpdateTeamTask } from '../src/features/team-tasks/hooks';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/api/endpoints/teamTasks', () => ({ members: jest.fn(), list: jest.fn(), detail: jest.fn(), create: jest.fn(), update: jest.fn(), changeStatus: jest.fn(), comment: jest.fn() }));
jest.mock('../src/store/auth', () => ({ useAuth: () => ({ user: mockIdentity, refreshProfile: mockRefreshProfile }) }));
jest.mock('../src/lib/useActiveScreen', () => ({ useActiveScreen: jest.fn((enabled = true) => enabled && mockActive) }));
jest.mock('../src/features/workspace/invalidateTeamResources', () => ({ invalidateTeamTasks: jest.fn() }));

let mockIdentity: { id: number; tenant_id: number } | null;
let mockActive = true;
const mockRefreshProfile = jest.fn();
let client: QueryClient;
let renderer: ReactTestRenderer | undefined;
let unit: TeamUnit | null;
let filters: api.TeamTaskFilters;
let q: string;
let readError: unknown;
let listResult: ReturnType<typeof useTeamTasks>;
let detailResult: ReturnType<typeof useTeamTask>;
let memberResult: ReturnType<typeof useTeamMemberSearch>;
let createResult: ReturnType<typeof useCreateTeamTask>;
let updateResult: ReturnType<typeof useUpdateTeamTask>;
let statusResult: ReturnType<typeof useChangeTeamTaskStatus>;
let commentResult: ReturnType<typeof useCommentTeamTask>;

const input: api.TeamTaskCreateInput = { title: 'Task', description: '', status: 'todo', priority: 'normal', assignee_user_id: null, due_date: null, checklist: [{ text: 'Count', done: false }], client_nonce: '12345678-1234-4123-8123-123456789012' };
function task(id = 12): api.TeamTaskDetail {
  return { id, title: `Task ${id}`, status: 'todo', priority: 'normal', assignee: null, due_date: null, checklist: [], checklist_progress: { done: 0, total: 0 }, version: 1, can_edit: true, description: '', created_at: '2026-11-07T08:00:00+08:00', updated_at: '2026-11-07T08:00:00+08:00', comments: [], comments_total: 0, comments_limit: 50, activity: [], activity_total: 0, activity_limit: 50 };
}
function listData(id = 12) { return { data: [task(id)], meta: { page: 1, pages: 2, limit: 50, total: 60, scope: 'team' as const, counts: { todo: 60, in_progress: 0, blocked: 0, done: 0, total: 60 } } }; }
function membersData(page = 1, name = 'Alex') { return { data: [{ id: page, name, role: 'clinic_staff' }], meta: { unit: 'clinic' as const, total: 70, page, pages: 2, limit: 50 } }; }
function ListProbe() { listResult = { ...useTeamTasks(unit, filters) }; return null; }
function DetailProbe() { detailResult = { ...useTeamTask(unit, 12) }; return null; }
function MemberProbe() { memberResult = { ...useTeamMemberSearch(unit, q) }; return null; }
function MutationProbe() {
  createResult = useCreateTeamTask(unit);
  updateResult = useUpdateTeamTask(unit, 12);
  statusResult = useChangeTeamTaskStatus(unit, 12);
  commentResult = useCommentTeamTask(unit, 12);
  return null;
}
function RecoveryProbe() { useTeamTaskAccessRecovery(unit, readError); return null; }
function render(Component: React.ComponentType) { act(() => { renderer = create(<QueryClientProvider client={client}><Component /></QueryClientProvider>); }); }
function rerender(Component: React.ComponentType) { act(() => renderer!.update(<QueryClientProvider client={client}><Component /></QueryClientProvider>)); }
async function flush(ms = 0) { await act(async () => { await jest.advanceTimersByTimeAsync(ms); }); }

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  jest.setSystemTime(new Date('2026-11-07T08:00:00Z'));
  mockIdentity = { id: 5, tenant_id: 7 }; mockActive = true; unit = 'clinic'; q = ''; readError = null;
  onlineManager.setOnline(true); focusManager.setFocused(true);
  filters = { scope: 'team', q: '', status: 'all', priority: 'all', page: 1 };
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity, staleTime: 0 }, mutations: { retry: false } } });
  jest.mocked(api.members).mockReset().mockImplementation(async (_unit, values) => membersData(values?.page));
  jest.mocked(api.list).mockReset().mockResolvedValue(listData());
  jest.mocked(api.detail).mockReset().mockResolvedValue({ data: task() });
  jest.mocked(api.create).mockReset().mockResolvedValue({ data: task() });
  jest.mocked(api.update).mockReset().mockResolvedValue({ data: task() });
  jest.mocked(api.changeStatus).mockReset().mockResolvedValue({ data: task() });
  jest.mocked(api.comment).mockReset().mockResolvedValue({ data: task() });
  mockRefreshProfile.mockReset().mockResolvedValue(undefined);
  jest.mocked(invalidateTeamTasks).mockReset().mockResolvedValue(undefined);
});
afterEach(() => {
  act(() => renderer?.unmount()); renderer = undefined;
  client.clear(); onlineManager.setOnline(true); focusManager.setFocused(undefined);
  jest.useRealTimers();
});

describe('Team task scoped query hooks', () => {
  it('only fetches active eligible list/detail observers and resumes stale reads with a 30-second list cadence', async () => {
    mockActive = false;
    function Probes() { return <><ListProbe /><DetailProbe /></>; }
    render(Probes); await flush();
    expect(api.list).not.toHaveBeenCalled(); expect(api.detail).not.toHaveBeenCalled();
    mockActive = true; rerender(Probes); await flush();
    expect(api.list).toHaveBeenCalledTimes(1); expect(api.detail).toHaveBeenCalledTimes(1);
    expect(listResult.data?.data[0].id).toBe(12);
    await flush(30_000);
    expect(api.list).toHaveBeenCalledTimes(2); expect(api.detail).toHaveBeenCalledTimes(1);
    mockActive = false; rerender(Probes); await flush(90_000);
    expect(api.list).toHaveBeenCalledTimes(2);
    mockActive = true; rerender(Probes); await flush();
    expect(api.list).toHaveBeenCalledTimes(3); expect(api.detail).toHaveBeenCalledTimes(2);
    expect(useActiveScreen).toHaveBeenCalledWith(true);
  });

  it('keeps same-scope rows while a page/filter loads but never carries them into a new unit or list scope', async () => {
    render(ListProbe); await flush();
    let resolve!: (value: ReturnType<typeof listData>) => void;
    jest.mocked(api.list).mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    filters = { ...filters, q: 'new search', page: 2 }; rerender(ListProbe);
    expect(listResult.data?.data[0].id).toBe(12);
    expect(listResult.isPlaceholderData).toBe(true);
    await act(async () => resolve(listData(20))); await flush();
    expect(listResult.data?.data[0].id).toBe(20);
    jest.mocked(api.list).mockImplementation(() => new Promise(() => undefined));
    filters = { ...filters, scope: 'mine' }; rerender(ListProbe);
    expect(listResult.data).toBeUndefined();
    unit = 'guidance'; rerender(ListProbe);
    expect(listResult.data).toBeUndefined();
  });

  it('does not reuse prior account rows when identity changes, even with identical filters and unit', async () => {
    render(ListProbe); await flush();
    jest.mocked(api.list).mockImplementation(() => new Promise(() => undefined));
    mockIdentity = { id: 6, tenant_id: 7 }; rerender(ListProbe);
    expect(listResult.data).toBeUndefined();
    mockIdentity = { id: 5, tenant_id: 8 }; rerender(ListProbe);
    expect(listResult.data).toBeUndefined();
    expect(client.getQueryData(queryKeys.teamTasks(7, 5, 'clinic', filters))).toEqual(listData());
  });

  it('paginates members with response metadata and permits an explicit retry after the next page fails', async () => {
    render(MemberProbe); await flush();
    expect(api.members).toHaveBeenCalledWith('clinic', { q: '', page: 1 });
    expect(memberResult.hasNextPage).toBe(true);
    jest.mocked(api.members).mockRejectedValueOnce(new NetworkError());
    await act(async () => { await memberResult.fetchNextPage(); }); await flush();
    expect(memberResult.isFetchNextPageError).toBe(true);
    expect(memberResult.data?.pages).toHaveLength(1);
    await act(async () => { await memberResult.fetchNextPage(); }); await flush();
    expect(api.members).toHaveBeenLastCalledWith('clinic', { q: '', page: 2 });
    expect(memberResult.data?.pages).toHaveLength(2);
    expect(memberResult.hasNextPage).toBe(false);
  });

  it('scopes and normalizes member search; query invalidation cannot be bypassed by a stale ref cache', async () => {
    q = '  Alex  '; render(MemberProbe); await flush();
    expect(api.members).toHaveBeenCalledWith('clinic', { q: 'Alex', page: 1 });
    await act(async () => { await client.invalidateQueries({ queryKey: queryKeys.teamUnit(7, 5, 'clinic') }); }); await flush();
    expect(api.members).toHaveBeenCalledTimes(2);
    jest.mocked(api.members).mockImplementation(() => new Promise(() => undefined));
    q = 'Blair'; rerender(MemberProbe);
    expect(memberResult.data).toBeUndefined();
    unit = 'guidance'; rerender(MemberProbe);
    expect(memberResult.data).toBeUndefined();
    mockIdentity = { id: 17, tenant_id: 19 }; rerender(MemberProbe);
    expect(memberResult.data).toBeUndefined();
  });

  it('blocks manual refetch and next-page calls while the query is ineligible', async () => {
    mockActive = false;
    function Probes() { return <><ListProbe /><DetailProbe /><MemberProbe /></>; }
    render(Probes);
    await act(async () => {
      await listResult.refetch();
      await detailResult.refetch();
      await memberResult.refetch();
      await memberResult.fetchNextPage();
    });
    expect(api.list).not.toHaveBeenCalled();
    expect(api.detail).not.toHaveBeenCalled();
    expect(api.members).not.toHaveBeenCalled();
    expect(listResult.eligible).toBe(false);
    expect(memberResult.eligible).toBe(false);
    mockActive = true;
    rerender(Probes);
    await flush();
    expect(api.list).toHaveBeenCalledTimes(1);
    expect(api.detail).toHaveBeenCalledTimes(1);
    expect(api.members).toHaveBeenCalledTimes(1);
  });

  it('does not fetch members while inactive or without an identity/unit', async () => {
    mockActive = false; render(MemberProbe); await flush(); expect(api.members).not.toHaveBeenCalled();
    mockActive = true; mockIdentity = null; rerender(MemberProbe); await flush(); expect(api.members).not.toHaveBeenCalled();
    mockIdentity = { id: 5, tenant_id: 7 }; unit = null; rerender(MemberProbe); await flush(); expect(api.members).not.toHaveBeenCalled();
    unit = 'clinic'; rerender(MemberProbe); await flush(); expect(api.members).toHaveBeenCalledTimes(1);
  });
});

describe('Team task mutation hooks', () => {
  it('passes exact payloads and invalidates all shared task resources once per successful write', async () => {
    render(MutationProbe);
    const { client_nonce: nonce, ...editable } = input;
    expect(nonce).toBe(input.client_nonce);
    const update = { ...editable, version: 4 };
    const status = { status: 'done' as const, version: 5 };
    const comment = { body: 'Update', client_nonce: input.client_nonce };
    await act(async () => {
      await createResult.mutateAsync(input);
      await updateResult.mutateAsync(update);
      await statusResult.mutateAsync(status);
      await commentResult.mutateAsync(comment);
    });
    expect(api.create).toHaveBeenCalledWith('clinic', input);
    expect(api.update).toHaveBeenCalledWith('clinic', 12, update);
    expect(api.changeStatus).toHaveBeenCalledWith('clinic', 12, status);
    expect(api.comment).toHaveBeenCalledWith('clinic', 12, comment);
    expect(invalidateTeamTasks).toHaveBeenCalledTimes(4);
    for (const call of jest.mocked(invalidateTeamTasks).mock.calls) { expect(call).toEqual([client, { tenantId: 7, userId: 5 }, 'clinic']); }
  });

  it('keeps an acknowledged API write successful when resource invalidation fails', async () => {
    jest.mocked(invalidateTeamTasks).mockRejectedValue(new Error('Read refresh failed'));
    render(MutationProbe);
    await act(async () => { await expect(createResult.mutateAsync(input)).resolves.toEqual({ data: task() }); });
    await flush();
    expect(createResult.isSuccess).toBe(true);
    expect(api.create).toHaveBeenCalledTimes(1);
    expect(invalidateTeamTasks).toHaveBeenCalledTimes(1);
    await flush(60_000);
    expect(api.create).toHaveBeenCalledTimes(1);
  });

  it('keeps a pending mutation bound to its original unit instead of retargeting its success invalidation', async () => {
    let resolve!: (value: { data: api.TeamTaskDetail }) => void;
    jest.mocked(api.create).mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    render(MutationProbe);
    let pending!: Promise<{ data: api.TeamTaskSummary }>;
    act(() => { pending = createResult.mutateAsync(input); });
    await flush();
    unit = 'guidance';
    rerender(MutationProbe);
    expect(createResult.isPending).toBe(false);
    await act(async () => { resolve({ data: task() }); await pending; });
    expect(invalidateTeamTasks).toHaveBeenCalledTimes(1);
    expect(invalidateTeamTasks).toHaveBeenCalledWith(client, { tenantId: 7, userId: 5 }, 'clinic');
  });

  it('does not invalidate resources under a new identity after an old write completes', async () => {
    let resolve!: (value: { data: api.TeamTaskDetail }) => void;
    jest.mocked(api.create).mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    render(MutationProbe);
    let pending!: Promise<{ data: api.TeamTaskSummary }>;
    act(() => { pending = createResult.mutateAsync(input); });
    await flush();
    mockIdentity = { id: 15, tenant_id: 17 };
    rerender(MutationProbe);
    await act(async () => { resolve({ data: task() }); await pending; });
    expect(invalidateTeamTasks).not.toHaveBeenCalled();
  });

  it('does not dispatch an old mutation if the scope changes before its API send starts', async () => {
    render(MutationProbe);
    let pending!: Promise<{ data: api.TeamTaskSummary }>;
    act(() => { pending = createResult.mutateAsync(input); });
    mockIdentity = { id: 15, tenant_id: 17 };
    rerender(MutationProbe);
    await act(async () => { await expect(pending).rejects.toThrow('This session has ended.'); });
    expect(api.create).not.toHaveBeenCalled();
    expect(invalidateTeamTasks).not.toHaveBeenCalled();
  });

  it('never auto-retries a failed write, even when QueryClient defaults would', async () => {
    client.setDefaultOptions({ queries: { retry: false }, mutations: { retry: 3 } });
    jest.mocked(api.create).mockRejectedValueOnce(new NetworkError());
    render(MutationProbe);
    await act(async () => { await expect(createResult.mutateAsync(input)).rejects.toBeInstanceOf(NetworkError); });
    await flush(120_000);
    expect(api.create).toHaveBeenCalledTimes(1);
    expect(invalidateTeamTasks).not.toHaveBeenCalled();
    await act(async () => { await createResult.mutateAsync(input); });
    expect(api.create).toHaveBeenCalledTimes(2);
    expect(jest.mocked(api.create).mock.calls[1][1]).toBe(input);
  });

  it('does not park an offline write for automatic reconnect execution', async () => {
    onlineManager.setOnline(false);
    render(MutationProbe);
    await act(async () => { await expect(createResult.mutateAsync(input)).rejects.toBeInstanceOf(NetworkError); });
    expect(api.create).not.toHaveBeenCalled();
    expect(createResult.isPaused).toBe(false);
    onlineManager.setOnline(true); await flush(30_000);
    expect(api.create).not.toHaveBeenCalled();
    await act(async () => { await createResult.mutateAsync(input); });
    expect(api.create).toHaveBeenCalledTimes(1);
  });

  it('does not invalidate, navigate, or refresh auth on a generic record-action 403', async () => {
    jest.mocked(api.changeStatus).mockRejectedValue(new ApiError(403, { code: 'forbidden', message: 'You cannot edit this task.' }));
    render(MutationProbe);
    await act(async () => { await expect(statusResult.mutateAsync({ status: 'done', version: 1 })).rejects.toBeInstanceOf(ApiError); });
    expect(invalidateTeamTasks).not.toHaveBeenCalled();
    expect(mockRefreshProfile).not.toHaveBeenCalled();
  });
});

describe('Team task read access reconciliation', () => {
  it('purges only the denied unit scope and refreshes profile without removing other workspace or personal cache', async () => {
    const denied = queryKeys.teamTask(7, 5, 'clinic', 12);
    const otherUnit = queryKeys.teamTask(7, 5, 'guidance', 12);
    client.setQueryData(denied, { data: task() });
    client.setQueryData(otherUnit, { data: task(40) });
    client.setQueryData(['appointments'], { data: ['kept'] });
    readError = new ApiError(403, { code: 'forbidden', message: 'Department membership required.' });
    render(RecoveryProbe); await flush();
    expect(client.getQueryData(denied)).toBeUndefined();
    expect(client.getQueryData(otherUnit)).toEqual({ data: task(40) });
    expect(client.getQueryData(['appointments'])).toEqual({ data: ['kept'] });
    expect(mockRefreshProfile).toHaveBeenCalledTimes(1);
    rerender(RecoveryProbe); await flush();
    expect(mockRefreshProfile).toHaveBeenCalledTimes(1);
    readError = new ApiError(403, { code: 'forbidden', message: 'Still denied.' });
    rerender(RecoveryProbe); await flush();
    expect(mockRefreshProfile).toHaveBeenCalledTimes(2);
  });

  it('keeps a denied active observer in its explicit error state instead of triggering an automatic refetch loop', async () => {
    jest.mocked(api.detail).mockRejectedValue(new ApiError(403, { code: 'forbidden', message: 'No membership' }));
    function ActiveRecovery() {
      detailResult = { ...useTeamTask(unit, 12) };
      useTeamTaskAccessRecovery(unit, detailResult.isError ? detailResult.error : null);
      return null;
    }
    client.setQueryData(queryKeys.teamTask(7, 5, 'clinic', 12), { data: task() });
    render(ActiveRecovery); await flush(); await flush();
    expect(api.detail).toHaveBeenCalledTimes(1);
    expect(detailResult.isError).toBe(true);
    expect(detailResult.data).toBeUndefined();
    expect(mockRefreshProfile).toHaveBeenCalledTimes(1);
    await flush(60_000);
    expect(api.detail).toHaveBeenCalledTimes(1);
  });
});
