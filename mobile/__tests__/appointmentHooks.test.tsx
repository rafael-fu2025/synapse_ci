import React from 'react';
import { AppState, DeviceEventEmitter, type AppStateStatus } from 'react-native';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { MutationCache, QueryClient, QueryClientProvider, focusManager, onlineManager } from '@tanstack/react-query';
import { useIsFocused } from '@react-navigation/native';
import { useOverlayActivity } from '../src/components/OverlayProvider';
import { queryKeys } from '../src/api/queryKeys';
import * as api from '../src/api/endpoints/appointments';
import { useAppointment, useAppointments, useCancelAppointment, useCreateAppointment } from '../src/features/appointments/hooks';
import { activateApiRuntime, createApiRuntime, setAccessToken, SessionChangedError } from '../src/api/client';
import { useSubmitSurvey, useSurveys } from '../src/features/surveys/hooks';
import * as surveysApi from '../src/api/endpoints/surveys';
import { ApiRuntimeProvider } from '../src/api/runtime';
import { poll } from '../src/api/poll';
import { ownUserRealtime } from '../src/lib/realtime/transportState';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/api/endpoints/surveys', () => ({ listing: jest.fn(), form: jest.fn(), submit: jest.fn() }));
jest.mock('../src/api/endpoints/appointments', () => ({ list: jest.fn(), detail: jest.fn(), create: jest.fn(), cancel: jest.fn() }));
jest.mock('../src/store/auth', () => ({ useAuth: () => ({ user: { id: 42, tenant_id: 7 } }) }));
jest.mock('@react-navigation/native', () => ({ useIsFocused: jest.fn(() => true) }));
jest.mock('../src/components/OverlayProvider', () => ({ useOverlayActivity: jest.fn(() => false) }));
jest.mock('react-native/Libraries/AppState/AppState', () => ({ __esModule: true, default: { currentState: 'active', addEventListener: jest.fn(() => ({ remove: jest.fn() })) } }));

let client: QueryClient;
let renderer: ReactTestRenderer | undefined;
let list: ReturnType<typeof useAppointments>;
let detail: ReturnType<typeof useAppointment>;
let id = 1;
const boundClient = expect.objectContaining({ runtime: expect.any(Object), sessionVersion: expect.any(Number), assertCurrent: expect.any(Function) });
const filters: api.AppointmentFilters = { service: 'all', status: 'active', sort: 'upcoming' };
const originalAppState = AppState.currentState;
const IDENTITY = { tenantId: 7, userId: 42 };
const appStateListeners = new Set<(state: AppStateStatus) => void>();
function emitAppState(state: AppStateStatus) { for (const listener of appStateListeners) { listener(state); } }
function Probe() { list = useAppointments(filters); detail = useAppointment(id); return null; }
async function render() { await act(async () => { renderer = create(<QueryClientProvider client={client}><Probe /></QueryClientProvider>); }); }
async function update() { await act(async () => renderer!.update(<QueryClientProvider client={client}><Probe /></QueryClientProvider>)); }
async function tick(ms = 0) {
  await act(async () => { await jest.advanceTimersByTimeAsync(ms); });
  await act(async () => { await jest.advanceTimersByTimeAsync(0); });
}

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  jest.clearAllMocks();
  id = 1;
  jest.replaceProperty(AppState, 'currentState', 'active');
  jest.mocked(useIsFocused).mockReturnValue(true);
  jest.mocked(useOverlayActivity).mockReturnValue(false);
  onlineManager.setOnline(true); focusManager.setFocused(true);
  appStateListeners.clear();
  jest.spyOn(AppState, 'addEventListener').mockImplementation((_type, callback) => {
    appStateListeners.add(callback);
    return { remove: () => { appStateListeners.delete(callback); } };
  });
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity, staleTime: 0 } } });
  jest.mocked(api.list).mockResolvedValue({ data: [] });
  jest.mocked(api.detail).mockResolvedValue({ data: { id: 1 } } as Awaited<ReturnType<typeof api.detail>>);
});
afterEach(() => {
  act(() => renderer?.unmount()); renderer = undefined;
  client.clear(); onlineManager.setOnline(true); focusManager.setFocused(undefined);
  jest.restoreAllMocks(); AppState.currentState = originalAppState; jest.useRealTimers();
});

it.each(['blur', 'overlay', 'offline', 'background'] as const)('skips hidden appointment reads during %s and resumes when eligible', async state => {
  if (state === 'blur') { jest.mocked(useIsFocused).mockReturnValue(false); }
  else if (state === 'overlay') { jest.mocked(useOverlayActivity).mockReturnValue(true); }
  else if (state === 'offline') { onlineManager.setOnline(false); }
  else { AppState.currentState = 'background'; }
  await render(); await tick();
  expect(api.list).not.toHaveBeenCalled(); expect(api.detail).not.toHaveBeenCalled();
  expect(list.eligible).toBe(false); expect(detail.eligible).toBe(false);
  await act(async () => { await list.refetch(); await detail.refetch(); });
  await act(async () => { await client.invalidateQueries({ queryKey: ['appointments'] }); });
  await tick(poll.appointmentsMs * 2);
  expect(api.list).not.toHaveBeenCalled(); expect(api.detail).not.toHaveBeenCalled();
  jest.mocked(useIsFocused).mockReturnValue(true); jest.mocked(useOverlayActivity).mockReturnValue(false);
  act(() => { onlineManager.setOnline(true); AppState.currentState = 'active'; emitAppState('active'); });
  await update(); await tick();
  expect(api.list).toHaveBeenCalledTimes(1); expect(api.detail).toHaveBeenCalledTimes(1);
  expect(list.eligible).toBe(true);
});

it('uses the shared 15-second appointment fallback only before authenticated realtime is ready', async () => {
  expect(poll.appointmentsMs).toBe(15_000);
  await render(); await tick();
  expect(api.list).toHaveBeenCalledTimes(1); expect(api.detail).toHaveBeenCalledTimes(1);
  expect(api.list).toHaveBeenLastCalledWith(filters, boundClient);
  expect(api.detail).toHaveBeenLastCalledWith(1, boundClient);
  await tick(poll.appointmentsMs - 1);
  expect(api.list).toHaveBeenCalledTimes(1); expect(api.detail).toHaveBeenCalledTimes(1);
  await tick(1);
  expect(api.list).toHaveBeenCalledTimes(2); expect(api.detail).toHaveBeenCalledTimes(2);
  let transport!: ReturnType<typeof ownUserRealtime>;
  act(() => { transport = ownUserRealtime(client, IDENTITY); }); await tick(poll.appointmentsMs);
  expect(api.list).toHaveBeenCalledTimes(3); expect(api.detail).toHaveBeenCalledTimes(3);
  try {
    act(() => transport.setReady(true)); await tick(poll.appointmentsMs * 2);
    expect(api.list).toHaveBeenCalledTimes(3); expect(api.detail).toHaveBeenCalledTimes(3);
    await act(async () => { await client.invalidateQueries({ queryKey: ['appointments'] }); }); await tick();
    expect(api.list).toHaveBeenCalledTimes(4); expect(api.detail).toHaveBeenCalledTimes(4);
    act(() => transport.setReady(false)); await tick(poll.appointmentsMs);
    expect(api.list).toHaveBeenCalledTimes(5); expect(api.detail).toHaveBeenCalledTimes(5);
  } finally {
    act(() => transport.release());
  }
});

it.each(['blur', 'overlay', 'offline', 'background'] as const)('stops active appointment fallback and ignores hints during %s', async state => {
  await render(); await tick(poll.appointmentsMs);
  expect(api.list).toHaveBeenCalledTimes(2); expect(api.detail).toHaveBeenCalledTimes(2);
  const cachedList = list.data;
  const cachedDetail = detail.data;
  if (state === 'blur') { jest.mocked(useIsFocused).mockReturnValue(false); }
  else if (state === 'overlay') { jest.mocked(useOverlayActivity).mockReturnValue(true); }
  else if (state === 'offline') { act(() => onlineManager.setOnline(false)); }
  else { act(() => { AppState.currentState = 'background'; emitAppState('background'); }); }
  await update(); await tick();
  await act(async () => { await client.invalidateQueries({ queryKey: ['appointments'] }); });
  await tick(poll.appointmentsMs * 2);
  expect(api.list).toHaveBeenCalledTimes(2); expect(api.detail).toHaveBeenCalledTimes(2);
  expect(list.data).toBe(cachedList); expect(detail.data).toBe(cachedDetail);
  expect(list.eligible).toBe(false); expect(detail.eligible).toBe(false);
  let transport!: ReturnType<typeof ownUserRealtime>;
  act(() => { transport = ownUserRealtime(client, IDENTITY); transport.setReady(true); }); await tick();
  try {
    await act(async () => { await client.invalidateQueries({ queryKey: ['appointments'] }); });
    await tick(poll.appointmentsMs * 2);
    expect(api.list).toHaveBeenCalledTimes(2); expect(api.detail).toHaveBeenCalledTimes(2);
    act(() => transport.setReady(false)); await tick(poll.appointmentsMs);
    expect(api.list).toHaveBeenCalledTimes(2); expect(api.detail).toHaveBeenCalledTimes(2);
    jest.mocked(useIsFocused).mockReturnValue(true); jest.mocked(useOverlayActivity).mockReturnValue(false);
    act(() => { onlineManager.setOnline(true); AppState.currentState = 'active'; emitAppState('active'); });
    await update(); await tick();
    expect(api.list).toHaveBeenCalledTimes(3); expect(api.detail).toHaveBeenCalledTimes(3);
    await tick(poll.appointmentsMs);
    expect(api.list).toHaveBeenCalledTimes(4); expect(api.detail).toHaveBeenCalledTimes(4);
  } finally {
    act(() => transport.release());
  }
});

it('does not add automatic appointment reads on keyboard visibility changes', async () => {
  await render(); await tick();
  act(() => { DeviceEventEmitter.emit('keyboardDidShow', {}); DeviceEventEmitter.emit('keyboardDidHide', {}); });
  await tick();
  expect(api.list).toHaveBeenCalledTimes(1); expect(api.detail).toHaveBeenCalledTimes(1);
});

it('retains cached appointments across blur and uses the existing keys for active retry', async () => {
  await render(); await tick();
  const cached = list.data;
  expect(client.getQueryData(queryKeys.appointments(filters))).toBe(cached);
  jest.mocked(useIsFocused).mockReturnValue(false); await update();
  expect(list.data).toBe(cached);
  await act(async () => { await list.refetch(); }); expect(api.list).toHaveBeenCalledTimes(1);
  jest.mocked(useIsFocused).mockReturnValue(true); await update(); await tick();
  await act(async () => { await list.refetch(); }); expect(api.list).toHaveBeenCalledTimes(3);
});

it.each(['blur', 'overlay', 'offline', 'background'] as const)('blocks retained eligible retries after %s and resumes only when eligible', async state => {
  await render(); await tick();
  const retryList = list.refetch;
  const retryDetail = detail.refetch;
  if (state === 'blur') { jest.mocked(useIsFocused).mockReturnValue(false); }
  else if (state === 'overlay') { jest.mocked(useOverlayActivity).mockReturnValue(true); }
  else if (state === 'offline') { act(() => onlineManager.setOnline(false)); }
  else { act(() => { AppState.currentState = 'background'; emitAppState('background'); }); }
  await update(); await tick();
  await act(async () => { await retryList(); await retryDetail(); });
  expect(api.list).toHaveBeenCalledTimes(1); expect(api.detail).toHaveBeenCalledTimes(1);
  jest.mocked(useIsFocused).mockReturnValue(true); jest.mocked(useOverlayActivity).mockReturnValue(false);
  act(() => { onlineManager.setOnline(true); AppState.currentState = 'active'; emitAppState('active'); });
  await update(); await tick();
  expect(api.list).toHaveBeenCalledTimes(2); expect(api.detail).toHaveBeenCalledTimes(2);
});

it.each(['offline', 'background'] as const)('checks native %s eligibility even before React commits', async state => {
  await render(); await tick();
  const retryList = list.refetch;
  const retryDetail = detail.refetch;
  await act(async () => {
    if (state === 'offline') { onlineManager.setOnline(false); }
    else { AppState.currentState = 'background'; emitAppState('background'); }
    await retryList(); await retryDetail();
  });
  expect(api.list).toHaveBeenCalledTimes(1); expect(api.detail).toHaveBeenCalledTimes(1);
});

it.each([0, -1, NaN, 1.5, 2])('rejects an old detail retry after its observer changes to id %s', async nextId => {
  await render(); await tick();
  const retryDetail = detail.refetch;
  id = nextId; await update(); await tick();
  const calls = jest.mocked(api.detail).mock.calls.length;
  await act(async () => { await retryDetail(); });
  expect(api.detail).toHaveBeenCalledTimes(calls);
  expect(api.detail).not.toHaveBeenCalledWith(0);
  if (nextId === 2) {
    await act(async () => { await detail.refetch(); });
    expect(api.detail).toHaveBeenCalledTimes(calls + 1);
    expect(api.detail).toHaveBeenLastCalledWith(2, boundClient);
  }
});

it('rejects retained retries after unmount and a new query-client lifetime', async () => {
  await render(); await tick();
  const retryList = list.refetch;
  const retryDetail = detail.refetch;
  act(() => renderer!.unmount()); renderer = undefined;
  client.clear();
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity, staleTime: 0 } } });
  await render(); await tick();
  const listCalls = jest.mocked(api.list).mock.calls.length;
  const detailCalls = jest.mocked(api.detail).mock.calls.length;
  await act(async () => { await retryList(); await retryDetail(); });
  expect(api.list).toHaveBeenCalledTimes(listCalls); expect(api.detail).toHaveBeenCalledTimes(detailCalls);
});

it.each([0, -1, NaN, 1.5])('does not read invalid appointment id %s', async invalid => {
  id = invalid; await render(); await tick();
  expect(api.detail).not.toHaveBeenCalled(); expect(detail.eligible).toBe(false);
  expect(api.list).toHaveBeenCalledTimes(1);
});

describe('queued runtime-bound appointment mutations', () => {
  const input: api.BookingInput = { unit: 'clinic', date: '2026-12-10', preferred_time: '09:00', type: 'routine' };
  let runtime: ReturnType<typeof createApiRuntime>;
  let mutation: ReturnType<typeof useCreateAppointment>;
  function ProbeMutation() { mutation = useCreateAppointment(); return null; }
  function tree() {
    return <ApiRuntimeProvider value={runtime}><QueryClientProvider client={client}><ProbeMutation /></QueryClientProvider></ApiRuntimeProvider>;
  }
  function activate(profileId: 'university' | 'home') {
    runtime = createApiRuntime({ apiBaseUrl: profileId === 'university' ? 'http://172.16.153.237:8080' : 'http://192.168.1.11:8080', profileId, credentialBinding: `fixture-${profileId}`, lanTest: true });
    activateApiRuntime(runtime);
    setAccessToken(`fixture-access-${profileId}`, runtime);
  }
  function rejection(pending: Promise<unknown>) { return pending.then(() => undefined, error => error); }

  beforeEach(() => {
    activate('university');
    jest.mocked(api.create).mockResolvedValue({ data: { id: 7 } } as Awaited<ReturnType<typeof api.create>>);
  });

  it('does not retry failed appointment booking or cancellation mutations', async () => {
    let cancellation!: ReturnType<typeof useCancelAppointment>;
    function MutationProbe() { mutation = useCreateAppointment(); cancellation = useCancelAppointment(); return null; }
    jest.mocked(api.create).mockRejectedValue(new Error('Booking failed'));
    jest.mocked(api.cancel).mockRejectedValue(new Error('Cancellation failed'));
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    await act(async () => { renderer = create(<ApiRuntimeProvider value={runtime}><QueryClientProvider client={client}><MutationProbe /></QueryClientProvider></ApiRuntimeProvider>); });
    await act(async () => {
      await expect(mutation.mutateAsync(input)).rejects.toThrow('Booking failed');
      await expect(cancellation.mutateAsync({ id: 7, reason: 'Changed schedule' })).rejects.toThrow('Cancellation failed');
    });
    await tick(60_000);
    expect(api.create).toHaveBeenCalledTimes(1); expect(api.cancel).toHaveBeenCalledTimes(1);
    expect(invalidate).not.toHaveBeenCalled();
  });

  it('rejects a retained mutateAsync callback rather than following updated observer options to Home', async () => {
    await act(async () => { renderer = create(tree()); });
    const retained = mutation.mutateAsync;
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    activate('home');
    await act(async () => renderer!.update(tree()));
    await act(async () => { await expect(retained(input)).rejects.toBeInstanceOf(SessionChangedError); });
    expect(api.create).not.toHaveBeenCalled();
    expect(invalidate).not.toHaveBeenCalled();
    await act(async () => { await mutation.mutateAsync(input); });
    expect(api.create).toHaveBeenCalledWith(input, expect.objectContaining({ runtime, sessionVersion: runtime.sessionVersion }));
  });

  it('does not replay an offline parked mutation after the server changes', async () => {
    onlineManager.setOnline(false);
    await act(async () => { renderer = create(tree()); });
    const rejected = rejection(mutation.mutateAsync(input));
    await tick();
    expect(mutation.isPaused).toBe(true);
    expect(api.create).not.toHaveBeenCalled();
    const old = runtime;
    activate('home');
    await act(async () => renderer!.update(tree()));
    act(() => onlineManager.setOnline(true));
    await act(async () => { await client.resumePausedMutations(); });
    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    expect(api.create).not.toHaveBeenCalled();
    expect(old.active).toBe(false);
  });

  it('keeps a mutation behind delayed onMutate bound even after the observer rerenders', async () => {
    let release!: () => void;
    let entered!: () => void;
    const started = new Promise<void>(resolve => { entered = resolve; });
    const gate = new Promise<void>(resolve => { release = resolve; });
    client.clear();
    client = new QueryClient({
      mutationCache: new MutationCache({ onMutate: async () => { entered(); await gate; } }),
      defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false, gcTime: Infinity } },
    });
    await act(async () => { renderer = create(tree()); });
    const rejected = rejection(mutation.mutateAsync(input));
    await act(async () => { await started; });
    activate('home');
    await act(async () => renderer!.update(tree()));
    await act(async () => { release(); await rejected; });
    await expect(rejected).resolves.toBeInstanceOf(SessionChangedError);
    expect(api.create).not.toHaveBeenCalled();
  });

  it('rejects old queued work across sign-out and a fresh sign-in as the same account', async () => {
    await act(async () => { renderer = create(tree()); });
    const retained = mutation.mutateAsync;
    setAccessToken(null, runtime);
    setAccessToken('fixture-access-university', runtime);
    await act(async () => renderer!.update(tree()));
    await act(async () => { await expect(retained(input)).rejects.toBeInstanceOf(SessionChangedError); });
    expect(api.create).not.toHaveBeenCalled();
  });

  it.each(['runtime', 'session'] as const)('keeps retained appointment reads retired across %s replacement', async replacement => {
    function appointmentTree() { return <ApiRuntimeProvider value={runtime}><QueryClientProvider client={client}><Probe /></QueryClientProvider></ApiRuntimeProvider>; }
    await act(async () => { renderer = create(appointmentTree()); }); await tick();
    const retryList = list.refetch;
    const retryDetail = detail.refetch;
    const old = runtime;
    if (replacement === 'runtime') { activate('home'); }
    else { setAccessToken(null, runtime); setAccessToken('fixture-access-university', runtime); }
    await act(async () => renderer!.update(appointmentTree())); await tick();
    const listCalls = jest.mocked(api.list).mock.calls.length;
    const detailCalls = jest.mocked(api.detail).mock.calls.length;
    await act(async () => { await retryList(); await retryDetail(); });
    expect(api.list).toHaveBeenCalledTimes(listCalls); expect(api.detail).toHaveBeenCalledTimes(detailCalls);
    await act(async () => { await list.refetch(); await detail.refetch(); });
    expect(api.list).toHaveBeenCalledTimes(listCalls + 1); expect(api.detail).toHaveBeenCalledTimes(detailCalls + 1);
    expect(api.list).toHaveBeenLastCalledWith(filters, expect.objectContaining({ runtime, sessionVersion: runtime.sessionVersion }));
    expect(api.detail).toHaveBeenLastCalledWith(1, expect.objectContaining({ runtime, sessionVersion: runtime.sessionVersion }));
    if (replacement === 'runtime') { expect(old.active).toBe(false); }
  });

  it('keeps a retained personal refetch from following its mutable observer into a new server', async () => {
    let result!: ReturnType<typeof useSurveys>;
    function SurveyProbe() { result = useSurveys({ status: 'all' }); return null; }
    function surveyTree() { return <ApiRuntimeProvider value={runtime}><QueryClientProvider client={client}><SurveyProbe /></QueryClientProvider></ApiRuntimeProvider>; }
    jest.mocked(surveysApi.listing).mockResolvedValue({ data: [], meta: { page: 1, pages: 1, total: 0 }, counts: { all: 0, available: 0, submitted: 0, closed: 0 }, has_profile: true });
    await act(async () => { renderer = create(surveyTree()); });
    await tick();
    const retained = result.refetch;
    const calls = jest.mocked(surveysApi.listing).mock.calls.length;
    activate('home');
    await act(async () => renderer!.update(surveyTree()));
    await act(async () => { await retained(); });
    expect(surveysApi.listing).toHaveBeenCalledTimes(calls);
    await act(async () => { await result.refetch(); });
    expect(surveysApi.listing).toHaveBeenCalledTimes(calls + 1);
    expect(surveysApi.listing).toHaveBeenLastCalledWith({ status: 'all' }, expect.objectContaining({ runtime }));
  });

  it('cannot cross environments with a retained public mutate callback or publish stale callbacks', async () => {
    let result!: ReturnType<typeof useSubmitSurvey>;
    function SurveyProbe() { result = useSubmitSurvey(); return null; }
    function surveyTree() { return <ApiRuntimeProvider value={runtime}><QueryClientProvider client={client}><SurveyProbe /></QueryClientProvider></ApiRuntimeProvider>; }
    jest.mocked(surveysApi.submit).mockResolvedValue({ data: { response_id: 3 } });
    await act(async () => { renderer = create(surveyTree()); });
    const retained = result.mutate;
    const onSuccess = jest.fn();
    const onError = jest.fn();
    activate('home');
    await act(async () => renderer!.update(surveyTree()));
    act(() => { retained({ id: 7, answers: ['fixture'] }, { onSuccess, onError }); });
    await tick();
    expect(surveysApi.submit).not.toHaveBeenCalled();
    expect(onSuccess).not.toHaveBeenCalled();
    expect(onError).not.toHaveBeenCalled();
    await act(async () => { await result.mutateAsync({ id: 7, answers: ['fixture'] }, { onSuccess }); });
    expect(onSuccess).toHaveBeenCalledTimes(1);
    expect(onSuccess.mock.calls[0][1]).toEqual({ id: 7, answers: ['fixture'] });
    expect(result.variables).toEqual({ id: 7, answers: ['fixture'] });
  });
});
