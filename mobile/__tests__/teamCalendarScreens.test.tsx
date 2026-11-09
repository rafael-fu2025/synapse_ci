import React from 'react';
import { Animated, AppState, Dimensions, KeyboardAvoidingView, Modal, Platform, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { act, create, ReactTestInstance, ReactTestRenderer } from 'react-test-renderer';
import { useIsFocused, useNavigation, usePreventRemove, useRoute } from '@react-navigation/native';
import { useNetInfo } from '@react-native-community/netinfo';
import DateTimePicker from '@react-native-community/datetimepicker';
import { focusManager, onlineManager, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ApiError, NetworkError } from '../src/api/client';
import type { MeResponse } from '../src/api/types';
import type { TeamCalendarEventDetail, TeamCalendarFilters } from '../src/api/endpoints/teamCalendar';
import { Action } from '../src/components/Action';
import { BottomSheet } from '../src/components/BottomSheet';
import { darkTheme, lightTheme } from '../src/theme/tokens';
import { ConfirmDialog } from '../src/components/ConfirmDialog';
import { IconAction } from '../src/components/IconAction';
import { OverlayProvider } from '../src/components/OverlayProvider';
import { PageHeader } from '../src/components/PageHeader';
import { Screen } from '../src/components/Screen';
import { Skeleton } from '../src/components/Skeleton';
import { useAuth } from '../src/store/auth';
import { secureClientNonce } from '../src/lib/secureNonce';
import { useKeyboardVisible } from '../src/lib/useKeyboardVisible';
import { useActiveScreen } from '../src/lib/useActiveScreen';
import { WorkspaceProvider, useWorkspace } from '../src/features/workspace/WorkspaceContext';
import { WorkspaceDiscardDialog } from '../src/features/workspace/WorkspaceDiscardDialog';
import { WorkspaceHeaderActions } from '../src/features/workspace/WorkspaceHeaderActions';
import { ManilaDateTimeField } from '../src/features/team-calendar/components/ManilaDateTimeField';
import { TeamCalendarHomeScreen } from '../src/features/team-calendar/screens/TeamCalendarHomeScreen';
import { TeamCalendarEventDetailScreen } from '../src/features/team-calendar/screens/TeamCalendarEventDetailScreen';
import { TeamCalendarEventFormScreen } from '../src/features/team-calendar/screens/TeamCalendarEventFormScreen';
import { TaskDeadlineNavigationProvider, useOpenTaskDeadline } from '../src/features/team-calendar/navigation';
import * as hooks from '../src/features/team-calendar/hooks';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/secureNonce', () => ({ secureClientNonce: jest.fn() }));
jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: () => true }));
jest.mock('../src/lib/useKeyboardVisible', () => ({ useKeyboardVisible: jest.fn(() => false) }));
jest.mock('../src/navigation/navigationRef', () => ({ navigationRef: { isReady: () => true, navigate: jest.fn() } }));
jest.mock('../src/features/notifications/hooks', () => ({ useNotificationInbox: () => ({ data: { data: { unread_count: 0 } } }) }));
jest.mock('../src/features/team-calendar/hooks', () => ({
  ...jest.requireActual('../src/features/team-calendar/hooks'),
  useTeamCalendar: jest.fn(), useTeamCalendarEvent: jest.fn(), useTeamCalendarMemberSearch: jest.fn(),
  useCreateTeamCalendarEvent: jest.fn(), useUpdateTeamCalendarEvent: jest.fn(), useCancelTeamCalendarEvent: jest.fn(),
}));
jest.mock('../src/features/team-calendar/navigation', () => ({ ...jest.requireActual('../src/features/team-calendar/navigation'), useOpenTaskDeadline: jest.fn() }));
jest.mock('@react-navigation/native', () => ({ useNavigation: jest.fn(), useRoute: jest.fn(), usePreventRemove: jest.fn(), useIsFocused: jest.fn() }));
jest.mock('@react-native-community/netinfo', () => ({ useNetInfo: jest.fn() }));
jest.mock('@react-native-community/datetimepicker', () => ({ __esModule: true, default: 'DateTimePicker' }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: mockTheme }) }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View, useSafeAreaInsets: () => ({ top: 24, right: 0, bottom: 20, left: 0 }) }));
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));

const originalAppState = AppState.currentState;
const originalWindow = Dimensions.get('window');
let mockTheme = lightTheme;
const member = { id: 7, name: 'Alex Member', role: 'staff' };
const other = { id: 8, name: 'Bea Member', role: 'staff' };
const event: TeamCalendarEventDetail = {
  type: 'event', id: 4, title: 'Planning', description: 'Team planning', location: 'Room 1', status: 'scheduled',
  starts_at: '2026-12-10T09:00:00+08:00', ends_at: '2026-12-10T10:00:00+08:00',
  participants: [member], version: 2, can_edit: true, created_at: '2026-12-01T00:00:00Z', updated_at: '2026-12-01T00:00:00Z',
};
const task = { type: 'task_deadline' as const, id: 8, title: 'Submit report', status: 'todo' as const, priority: 'high' as const, due_date: '2026-12-10', assignee_user_id: 7, version: 1 };
const navigation = { goBack: jest.fn(), canGoBack: jest.fn(() => true), navigate: jest.fn(), replace: jest.fn(), dispatch: jest.fn() };
const refetch = jest.fn();
const memberRefetch = jest.fn();
const createRequest = jest.fn();
const updateRequest = jest.fn();
const cancelRequest = jest.fn();
const openTask = jest.fn();
const refreshProfile = jest.fn();
const resetCreate = jest.fn();
const resetUpdate = jest.fn();
const resetCancel = jest.fn();
let renderer: ReactTestRenderer | undefined;
let client: QueryClient;
let workspace: ReturnType<typeof useWorkspace>;
let element: React.ReactElement;
let profile: MeResponse;

type QueryState<T> = { data?: T; error: unknown; isError: boolean; isLoading: boolean; isFetching: boolean; isRefetching: boolean; isSuccess: boolean; fetchStatus: 'idle' | 'paused' | 'fetching'; eligible: boolean; refetch: jest.Mock };
function query<T>(data?: T): QueryState<T> {
  return { data, error: null, isError: false, isLoading: false, isFetching: false, isRefetching: false, isSuccess: true, fetchStatus: 'idle', eligible: true, refetch };
}
let detailQuery: QueryState<{ data: TeamCalendarEventDetail }>;
let listQuery: QueryState<{ data: (TeamCalendarEventDetail | typeof task)[]; meta: { date: string; view: 'agenda'; scope: 'team'; start_date: string; end_date: string; total_events: number; total_tasks: number; truncated: boolean } }>;
let memberQuery: QueryState<{ data: typeof member[]; meta: { total: number; page: number; pages: number; limit: number } }>;

function makeProfile(): MeResponse {
  return {
    user: { id: 7, person_id: null, tenant_id: 3, username: 'member', email: null, name: 'Alex Member', avatar_initials: 'AM', identifier: 'member', role: 'staff', is_teaching: false, permissions: [] },
    entitlements: { portal: true, staff_queue_units: [], personal: { enabled: true }, work: { enabled: true, units: [{ unit: 'clinic', label: 'Clinic', can_manage: false, features: { team_board: true, my_tasks: true, team_chat: true, team_calendar: true } }] } },
    photo_url: '/api/v1/me/photo',
  };
}
function Probe() { workspace = useWorkspace(); return null; }
let openActualTask: (id: number) => void;
const actualTaskHook = jest.requireActual('../src/features/team-calendar/navigation').useOpenTaskDeadline as typeof useOpenTaskDeadline;
function TaskNavigationProbe() { openActualTask = actualTaskHook(); return null; }
function Host({ screen }: { screen: React.ReactElement }) {
  return <QueryClientProvider client={client}><OverlayProvider><WorkspaceProvider><Probe />{screen}<WorkspaceDiscardDialog /></WorkspaceProvider></OverlayProvider></QueryClientProvider>;
}
function render(screen: React.ReactElement) {
  element = screen;
  act(() => { renderer = create(<Host screen={screen} />); });
}
function rerender() { act(() => renderer!.update(<Host screen={React.cloneElement(element)} />)); }
function action(label: string, root = renderer!.root): ReactTestInstance {
  if (root.type === ConfirmDialog) {
    const modal = renderer!.root.findAllByType(Modal).find(node => node.props.visible && node.findAllByType(Text).some(heading => heading.props.children === root.props.title));
    if (!modal) { throw new Error(`Missing presented dialog ${root.props.title}`); }
    root = modal;
  }
  const found = root.findAllByType(Action).find(node => node.props.children === label);
  if (!found) { throw new Error(`Missing action ${label}`); }
  return found;
}
function control(label: string): ReactTestInstance {
  const found = renderer!.root.findAll(node => node.props.accessibilityLabel === label && typeof node.props.onPress === 'function')[0];
  if (!found) { throw new Error(`Missing control ${label}`); }
  return found;
}
function press(label: string) { act(() => control(label).props.onPress()); }
function pressAction(label: string) { act(() => action(label).props.onPress()); }
function field(label: string) { return renderer!.root.findAllByType(TextInput).find(node => node.props.accessibilityLabel === label)!; }
function input(label: string, value: string) { act(() => field(label).props.onChangeText(value)); }
function text(value: string) { return renderer!.root.findAllByType(Text).some(node => typeof node.props.children === 'string' && node.props.children.includes(value)); }
function dialog(title: string) { return renderer!.root.findAllByType(ConfirmDialog).find(node => node.props.title === title)!; }
function lastFilters(): TeamCalendarFilters { const calls = jest.mocked(hooks.useTeamCalendar).mock.calls; return calls[calls.length - 1][1]; }
function calendarFilterSheet() { return renderer!.root.findAllByType(BottomSheet).find(node => node.props.title === 'Calendar filters')!; }
function calendarRadioGroups() { return renderer!.root.findAllByType(View).filter(node => node.props.accessibilityRole === 'radiogroup').map(node => node.props.accessibilityLabel); }
function setRoute(name: string, params?: object) { jest.mocked(useRoute).mockReturnValue({ key: 'calendar-route', name, params }); }
async function settle(ms = 0) {
  // Commit write promises and their hidden-modal render before advancing the
  // native exit frame. React 19's own microtasks must not depend on fake timers.
  await act(async () => { await Promise.resolve(); });
  act(() => jest.advanceTimersByTime(ms));
  await act(async () => { await Promise.resolve(); });
}
function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  jest.setSystemTime(new Date('2026-12-10T01:00:00Z'));
  jest.replaceProperty(Platform, 'OS', 'android');
  AppState.currentState = 'active';
  onlineManager.setOnline(true);
  focusManager.setFocused(true);
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
  profile = makeProfile();
  mockTheme = lightTheme;
  jest.mocked(useKeyboardVisible).mockReturnValue(false);
  jest.mocked(useAuth).mockImplementation(() => ({ user: profile.user, profile, refreshProfile } as unknown as ReturnType<typeof useAuth>));
  refreshProfile.mockReset().mockResolvedValue(undefined);
  jest.mocked(useNavigation).mockReturnValue(navigation as unknown as ReturnType<typeof useNavigation>);
  jest.mocked(useIsFocused).mockReturnValue(true);
  jest.mocked(useNetInfo).mockReturnValue({ isConnected: true, isInternetReachable: true } as ReturnType<typeof useNetInfo>);
  navigation.canGoBack.mockReturnValue(true);
  setRoute('TeamCalendarEventForm', { initialDate: '2026-12-10' });
  jest.mocked(useOpenTaskDeadline).mockReturnValue(openTask);
  jest.mocked(secureClientNonce).mockReset().mockReturnValue('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee');
  detailQuery = query({ data: event });
  listQuery = query({ data: [event, task], meta: { date: '2026-12-10', view: 'agenda', scope: 'team', start_date: '2026-12-10', end_date: '2027-01-09', total_events: 1, total_tasks: 1, truncated: false } });
  memberQuery = { ...query({ data: [member, other], meta: { total: 2, page: 1, pages: 1, limit: 50 } }), refetch: memberRefetch };
  refetch.mockReset().mockImplementation(async () => detailQuery);
  memberRefetch.mockReset().mockImplementation(async () => memberQuery);
  jest.mocked(hooks.useTeamCalendar).mockImplementation(() => listQuery as unknown as ReturnType<typeof hooks.useTeamCalendar>);
  jest.mocked(hooks.useTeamCalendarEvent).mockImplementation(() => detailQuery as unknown as ReturnType<typeof hooks.useTeamCalendarEvent>);
  jest.mocked(hooks.useTeamCalendarMemberSearch).mockImplementation(() => memberQuery as unknown as ReturnType<typeof hooks.useTeamCalendarMemberSearch>);
  createRequest.mockReset().mockResolvedValue({ data: event });
  updateRequest.mockReset().mockResolvedValue({ data: event });
  cancelRequest.mockReset().mockResolvedValue({ data: { ...event, status: 'cancelled' } });
  jest.mocked(hooks.useCreateTeamCalendarEvent).mockReturnValue({ mutateAsync: createRequest, isPending: false, reset: resetCreate } as unknown as ReturnType<typeof hooks.useCreateTeamCalendarEvent>);
  jest.mocked(hooks.useUpdateTeamCalendarEvent).mockReturnValue({ mutateAsync: updateRequest, isPending: false, reset: resetUpdate } as unknown as ReturnType<typeof hooks.useUpdateTeamCalendarEvent>);
  jest.mocked(hooks.useCancelTeamCalendarEvent).mockReturnValue({ mutateAsync: cancelRequest, isPending: false, reset: resetCancel } as unknown as ReturnType<typeof hooks.useCancelTeamCalendarEvent>);
});
afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  Dimensions.set({ window: originalWindow });
  AppState.currentState = originalAppState;
  client.clear();
  act(() => jest.runOnlyPendingTimers());
  jest.restoreAllMocks();
  onlineManager.setOnline(true);
  focusManager.setFocused(undefined);
  jest.useRealTimers();
});

describe('mounted task deadline navigation provider', () => {
  it('uses the existing MyTasks route when TeamBoard is disabled and reports unavailable details when neither route exists', () => {
    profile.entitlements.work.units[0].features.team_board = false;
    render(<TaskDeadlineNavigationProvider><TaskNavigationProbe /></TaskDeadlineNavigationProvider>);
    act(() => openActualTask(8));
    expect(navigation.navigate).toHaveBeenCalledWith('MyTasks', { screen: 'TeamTaskDetail', params: { id: 8 } });
    navigation.navigate.mockClear();
    profile = { ...profile, entitlements: { ...profile.entitlements, work: { ...profile.entitlements.work, units: [{ ...profile.entitlements.work.units[0], features: { ...profile.entitlements.work.units[0].features, my_tasks: false } }] } } };
    rerender();
    act(() => openActualTask(8));
    expect(navigation.navigate).not.toHaveBeenCalled();
    expect(workspace.notice).toContain('Task details are not enabled');
  });

  it('keeps the external task navigation seam intact when supplied by its owner', () => {
    const external = jest.fn();
    render(<TaskDeadlineNavigationProvider onOpenTaskDeadline={external}><TaskNavigationProbe /></TaskDeadlineNavigationProvider>);
    act(() => openActualTask(8));
    expect(external).toHaveBeenCalledWith(8);
    expect(navigation.navigate).not.toHaveBeenCalled();
  });
});

describe('mounted calendar home', () => {
  it('keeps the toolbar and period controls stable while an uncached calendar loads', () => {
    listQuery = { ...listQuery, data: undefined, isLoading: false, isFetching: true, fetchStatus: 'fetching' };
    render(<TeamCalendarHomeScreen />);
    expect(renderer!.root.findByType(Skeleton).props).toMatchObject({ label: 'Loading calendar', variant: 'calendar' });
    expect(renderer!.root.findAllByProps({ testID: 'calendar-toolbar' }).length).toBeGreaterThan(0);
    expect(control('Next calendar period')).toBeDefined();
    expect(text('Nothing scheduled')).toBe(false);
    press('Calendar view: Week');
    expect(renderer!.root.findByType(Skeleton)).toBeDefined();
    expect(text('Nothing scheduled')).toBe(false);
  });

  it('shows idle and paused no-data calendar waiting states without inventing an empty period', () => {
    listQuery = { ...listQuery, data: undefined, eligible: false };
    render(<TeamCalendarHomeScreen />);
    expect(text('Calendar is waiting to load')).toBe(true);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    expect(text('Nothing scheduled')).toBe(false);
    listQuery = { ...listQuery, isLoading: true, fetchStatus: 'paused' };
    rerender();
    expect(text('Calendar is waiting for a connection')).toBe(true);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    press('Calendar view: Month');
    expect(text('Nothing scheduled')).toBe(false);
  });

  it('keeps cached empty agenda and week content through refetch and transient errors', () => {
    listQuery = { ...listQuery, data: { ...listQuery.data!, data: [] }, isLoading: true, isFetching: true, fetchStatus: 'fetching' };
    render(<TeamCalendarHomeScreen />);
    expect(text('Nothing scheduled')).toBe(true);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    listQuery = { ...listQuery, isLoading: false, isFetching: false, fetchStatus: 'idle', isError: true, error: new Error('refresh failed') };
    rerender();
    expect(text('Nothing scheduled')).toBe(true);
    expect(text('Showing the last available calendar')).toBe(true);
    press('Calendar view: Week');
    expect(text('No events or task deadlines on this day.')).toBe(true);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
  });

  it('allows ordinary authorized members to create and uses the shared root header without an inert unit switch', () => {
    render(<TeamCalendarHomeScreen />);
    expect(workspace.activeUnit?.can_manage).toBe(false);
    expect(renderer!.root.findByType(WorkspaceHeaderActions).props.root).toBe(true);
    const labels = renderer!.root.findAllByType(IconAction).map(node => node.props.label);
    expect(labels).toEqual(expect.arrayContaining(['Create event', 'Open notifications', 'Open account menu']));
    expect(labels).not.toContain('Switch Team Workspace unit');
    press('Create event');
    expect(navigation.navigate).toHaveBeenCalledWith('TeamCalendarEventForm', { initialDate: '2026-12-10' });
    listQuery = { ...listQuery, data: { ...listQuery.data!, data: [] } };
    rerender();
    expect(action('Create event')).toBeDefined();
  });

  it('preserves range/view/filter/scope when opening task deadlines and events', () => {
    render(<TeamCalendarHomeScreen />);
    press('Open calendar filters');
    press('Calendar scope: Mine');
    press('Event status: All events');
    press('Done filtering calendar');
    press('Calendar view: Week');
    const expected = { date: '2026-12-10', view: 'week', scope: 'mine', status: 'all' };
    expect(lastFilters()).toEqual(expected);
    press('Open task deadline Submit report');
    expect(openTask).toHaveBeenCalledWith(8);
    rerender();
    expect(lastFilters()).toEqual(expected);
    press('Open event Planning');
    expect(navigation.navigate).toHaveBeenCalledWith('TeamCalendarEventDetail', { id: 4 });
    rerender();
    expect(lastFilters()).toEqual(expected);
    press('Next calendar period');
    expect(lastFilters()).toEqual({ ...expected, date: '2026-12-17' });
  });

  it('exposes real week and mine narrowing actions for truncated results', () => {
    listQuery.data!.meta.truncated = true;
    listQuery.data!.meta.total_events = 310;
    render(<TeamCalendarHomeScreen />);
    expect(text('250 events and 250 task deadlines')).toBe(false);
    expect(text('Results are truncated')).toBe(true);
    pressAction('Show selected week');
    expect(lastFilters().view).toBe('week');
    pressAction('Show only mine');
    expect(lastFilters().scope).toBe('mine');
    expect(text('narrowest available range')).toBe(true);
  });

  it.each([lightTheme, darkTheme])('keeps only the view switcher in a compact themed toolbar in $mode mode', theme => {
    mockTheme = theme;
    Dimensions.set({ window: { width: 360, height: 800, scale: 3, fontScale: 1 } });
    render(<TeamCalendarHomeScreen />);
    const toolbar = renderer!.root.findByProps({ testID: 'calendar-toolbar' });
    expect(StyleSheet.flatten(toolbar.props.style)).toMatchObject({ flexDirection: 'row', flexWrap: 'wrap' });
    expect(calendarRadioGroups()).toEqual(['Calendar view']);
    expect(text('Team · Scheduled events')).toBe(true);
    expect(text('250 events and 250 task deadlines')).toBe(false);
    const viewControl = renderer!.root.findByProps({ testID: 'calendar-view-control' });
    expect(StyleSheet.flatten(viewControl.props.style)).toMatchObject({ flex: 1, minWidth: 210 });
    const button = control('Open calendar filters');
    const buttonStyle = StyleSheet.flatten(button.props.style({ pressed: false }));
    expect(buttonStyle).toMatchObject({ minWidth: 50, minHeight: 52, backgroundColor: theme.surface });
    const selectedTarget = control('Calendar view: Agenda');
    expect(StyleSheet.flatten(selectedTarget.props.style)).toMatchObject({ minHeight: 44 });
    expect(StyleSheet.flatten(selectedTarget.props.style).transform).toBeUndefined();
    const selectedPill = selectedTarget.findAllByType(Animated.View).find(node => StyleSheet.flatten(node.props.style)?.backgroundColor === theme.brand)!;
    expect(StyleSheet.flatten(selectedPill.props.style).opacity.__getValue()).toBe(1);
    press('Open calendar filters');
    expect(calendarFilterSheet().props.visible).toBe(true);
    expect(text('250 events and 250 task deadlines')).toBe(true);
    expect(calendarRadioGroups()).toEqual(['Calendar view', 'Calendar scope', 'Event status']);
    expect(control('Calendar scope: Team').props.accessibilityState.checked).toBe(true);
    expect(control('Event status: Scheduled').props.accessibilityState.checked).toBe(true);
    expect(StyleSheet.flatten(control('Event status: Cancelled').props.style).minHeight).toBeGreaterThanOrEqual(44);
  });

  it('keeps filter timing stable and filter selections immediate while overlay query eligibility is paused', () => {
    jest.mocked(hooks.useTeamCalendar).mockImplementation(() => ({
      ...listQuery, eligible: useActiveScreen(),
    }) as unknown as ReturnType<typeof hooks.useTeamCalendar>);
    render(<TeamCalendarHomeScreen />);
    const timing = calendarFilterSheet().props.timing;
    expect(timing).toEqual({ enterMs: 360, exitMs: 240 });
    press('Open calendar filters');
    const refresh = renderer!.root.findAllByType(ScrollView)[0].props.refreshControl;
    expect(refresh.props.enabled).toBe(false);
    expect(control('Calendar scope: Mine').props.disabled).toBe(false);
    press('Calendar scope: Mine');
    press('Event status: All events');
    expect(lastFilters()).toMatchObject({ scope: 'mine', status: 'all' });
    expect(control('Calendar scope: Mine').props.accessibilityState.checked).toBe(true);
    expect(control('Event status: All events').props.accessibilityState.checked).toBe(true);
    listQuery = { ...listQuery, isRefetching: true, isFetching: true };
    mockTheme = darkTheme;
    rerender();
    expect(calendarFilterSheet().props.timing).toBe(timing);
    press('Reset calendar filters');
    expect(lastFilters()).toMatchObject({ scope: 'team', status: 'scheduled' });
    expect(calendarFilterSheet().props.visible).toBe(true);
  });

  it('lets larger text use a separate toolbar line rather than overflow or shrink the targets', () => {
    Dimensions.set({ window: { width: 320, height: 700, scale: 3, fontScale: 2 } });
    render(<TeamCalendarHomeScreen />);
    const viewControl = renderer!.root.findByProps({ testID: 'calendar-view-control' });
    expect(StyleSheet.flatten(viewControl.props.style).minWidth).toBe(284);
    const toolbar = renderer!.root.findByProps({ testID: 'calendar-toolbar' });
    expect(StyleSheet.flatten(toolbar.props.style).flexWrap).toBe('wrap');
    expect(StyleSheet.flatten(control('Calendar view: Month').props.style).minHeight).toBeGreaterThanOrEqual(44);
    expect(StyleSheet.flatten(control('Open calendar filters').props.style({ pressed: false })).minHeight).toBeGreaterThanOrEqual(44);
  });

  it('keeps sheet selections on dismissal and resets only filters without moving the calendar', async () => {
    render(<TeamCalendarHomeScreen />);
    press('Calendar view: Month'); press('Next calendar period');
    const browsed = lastFilters();
    expect(browsed).toMatchObject({ view: 'month', date: '2027-01-10' });
    press('Open calendar filters');
    press('Calendar scope: Mine'); press('Event status: Cancelled');
    expect(lastFilters()).toEqual({ ...browsed, scope: 'mine', status: 'cancelled' });
    expect(text('Mine · Cancelled events')).toBe(true);
    expect(control('Open calendar filters').props.accessibilityHint).toBe('Mine · Cancelled events');
    expect(StyleSheet.flatten(control('Open calendar filters').props.style({ pressed: false })).backgroundColor).toBe(lightTheme.brand50);
    const staleChoice = control('Calendar scope: Team').props.onPress;
    act(() => renderer!.root.findByType(Modal).props.onRequestClose());
    expect(calendarFilterSheet().props.visible).toBe(false);
    act(() => staleChoice());
    expect(lastFilters()).toEqual({ ...browsed, scope: 'mine', status: 'cancelled' });
    await settle(40);
    press('Open calendar filters');
    expect(control('Calendar scope: Mine').props.accessibilityState.checked).toBe(true);
    expect(control('Event status: Cancelled').props.accessibilityState.checked).toBe(true);
    press('Reset calendar filters');
    expect(lastFilters()).toEqual(browsed);
    expect(calendarFilterSheet().props.visible).toBe(true);
    expect(text('Team · Scheduled events')).toBe(true);
    press('Done filtering calendar');
    expect(refetch).not.toHaveBeenCalled();
    expect(navigation.navigate).not.toHaveBeenCalled();
  });

  it.each([
    ['Agenda', 'agenda', '2027-01-09'],
    ['Week', 'week', '2026-12-17'],
    ['Month', 'month', '2027-01-10'],
  ] as const)('preserves secondary filters through %s period arrows and Today', async (label, view, nextDate) => {
    render(<TeamCalendarHomeScreen />);
    press('Open calendar filters'); press('Calendar scope: Mine'); press('Event status: All events'); press('Done filtering calendar');
    await settle(40);
    press(`Calendar view: ${label}`);
    press('Next calendar period');
    expect(lastFilters()).toEqual({ date: nextDate, view, scope: 'mine', status: 'all' });
    press('Previous calendar period');
    expect(lastFilters()).toEqual({ date: '2026-12-10', view, scope: 'mine', status: 'all' });
    press('Next calendar period'); press('Go to today');
    expect(lastFilters()).toEqual({ date: '2026-12-10', view, scope: 'mine', status: 'all' });
  });

  it('rejects old sheet callbacks after closing and reopening', async () => {
    render(<TeamCalendarHomeScreen />);
    press('Open calendar filters');
    const select = control('Event status: Cancelled').props.onPress;
    const close = calendarFilterSheet().props.onClose;
    const reset = control('Reset calendar filters').props.onPress;
    press('Close calendar filters');
    await settle(40);
    press('Open calendar filters'); press('Calendar scope: Mine');
    act(() => { select(); close(); reset(); });
    expect(lastFilters()).toMatchObject({ scope: 'mine', status: 'scheduled' });
    expect(calendarFilterSheet().props.visible).toBe(true);
  });

  it('closes and retires filter actions on blur without losing the browsed period or filters', () => {
    render(<TeamCalendarHomeScreen />);
    press('Calendar view: Week'); press('Next calendar period');
    press('Open calendar filters'); press('Calendar scope: Mine');
    const select = control('Event status: Cancelled').props.onPress;
    const expected = lastFilters();
    jest.mocked(useIsFocused).mockReturnValue(false);
    rerender();
    expect(calendarFilterSheet().props.visible).toBe(false);
    act(() => select());
    expect(lastFilters()).toEqual(expected);
    jest.mocked(useIsFocused).mockReturnValue(true);
    rerender();
    expect(lastFilters()).toEqual(expected);
    expect(calendarFilterSheet().props.visible).toBe(false);
  });

  it.each(['account', 'unit', 'access'] as const)('retires an open sheet after a %s change', async change => {
    render(<TeamCalendarHomeScreen />);
    press('Open calendar filters');
    const select = control('Event status: Cancelled').props.onPress;
    const close = calendarFilterSheet().props.onClose;
    if (change === 'account') {
      profile = { ...profile, user: { ...profile.user, id: 99 } };
    } else if (change === 'unit') {
      profile = { ...profile, entitlements: { ...profile.entitlements, work: { ...profile.entitlements.work,
        units: [{ ...profile.entitlements.work.units[0], unit: 'guidance', label: 'Guidance' }] } } };
    } else {
      listQuery = { ...listQuery, isError: true, error: new ApiError(403, { code: 'forbidden', message: 'No membership' }) };
    }
    rerender();
    await settle(40);
    act(() => { select(); close(); });
    expect(lastFilters().status).toBe('scheduled');
    if (change === 'access') {
      expect(calendarFilterSheet()).toBeUndefined();
      expect(text('Calendar access changed')).toBe(true);
    } else {
      expect(calendarFilterSheet().props.visible).toBe(false);
    }
  });

  it('keeps cached data after refresh errors and distinguishes quiet polling from manual refresh', async () => {
    listQuery.isRefetching = true;
    listQuery.isFetching = true;
    render(<TeamCalendarHomeScreen />);
    let refresh = renderer!.root.findByType(ScrollView).props.refreshControl;
    expect(refresh.props.refreshing).toBe(false);
    const pending = deferred<unknown>();
    refetch.mockImplementationOnce(() => pending.promise);
    act(() => { refresh.props.onRefresh(); refresh.props.onRefresh(); });
    expect(refetch).toHaveBeenCalledTimes(1);
    refresh = renderer!.root.findByType(ScrollView).props.refreshControl;
    expect(refresh.props.refreshing).toBe(true);
    await act(async () => pending.resolve(undefined));
    expect(renderer!.root.findByType(ScrollView).props.refreshControl.props.refreshing).toBe(false);
    listQuery = { ...listQuery, isError: true, error: new NetworkError() };
    rerender();
    expect(text('Showing the last available calendar')).toBe(true);
    expect(control('Open event Planning')).toBeDefined();
    expect(action('Retry')).toBeDefined();
  });

  it('updates today across Manila midnight without resetting a deliberately browsed range', async () => {
    jest.setSystemTime(new Date('2026-12-10T15:59:59Z'));
    render(<TeamCalendarHomeScreen />);
    expect(lastFilters().date).toBe('2026-12-10');
    await settle(1001);
    expect(lastFilters().date).toBe('2026-12-11');
    press('Next calendar period');
    const browsed = lastFilters().date;
    await settle(24 * 60 * 60 * 1000);
    expect(lastFilters().date).toBe(browsed);
    press('Go to today');
    expect(lastFilters().date).toBe('2026-12-12');
  });

  it('hides a denied read and refreshes access without full bootstrap or forced workspace exit', async () => {
    listQuery = { ...listQuery, isError: true, error: new ApiError(403, { code: 'forbidden', message: 'No membership' }) };
    render(<TeamCalendarHomeScreen />);
    await settle();
    expect(refreshProfile).toHaveBeenCalledTimes(1);
    expect(text('Calendar access changed')).toBe(true);
    expect(renderer!.root.findAllByType(IconAction).some(node => node.props.label === 'Create event')).toBe(false);
    expect(text('Planning')).toBe(false);
  });
});

describe('mounted calendar form', () => {
  it('skeletonizes only an uncached edit and leaves the local new-event shell ready', () => {
    setRoute('TeamCalendarEventForm', { id: event.id });
    detailQuery = { ...detailQuery, data: undefined, isLoading: false, isFetching: true, fetchStatus: 'fetching' };
    render(<TeamCalendarEventFormScreen />);
    expect(renderer!.root.findByType(Skeleton).props).toMatchObject({ label: 'Loading event form', variant: 'form' });
    expect(field('Event title')).toBeUndefined();
    expect(renderer!.root.findByType(PageHeader).props.title).toBe('Edit event');
    act(() => renderer!.unmount()); renderer = undefined;
    setRoute('TeamCalendarEventForm');
    render(<TeamCalendarEventFormScreen />);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    expect(field('Event title').props.value).toBe('');
    expect(action('Create event')).toBeDefined();
  });

  it('initializes a warm edit on its first render without an unavailable frame or erasing later edits', () => {
    setRoute('TeamCalendarEventForm', { id: event.id });
    detailQuery = { ...detailQuery, isLoading: true, isFetching: true, fetchStatus: 'fetching' };
    const frames: string[] = [];
    const capture = () => {
      if (renderer) { frames.push(renderer.root.findAllByType(Text).map(node => React.Children.toArray(node.props.children).join('')).join('\n')); }
    };
    element = <TeamCalendarEventFormScreen />;
    act(() => { renderer = create(<React.Profiler id="warm-calendar-edit" onRender={capture}><Host screen={element} /></React.Profiler>); });
    expect(frames.length).toBeGreaterThan(0);
    expect(frames.every(frame => !frame.includes('Could not load event'))).toBe(true);
    expect(field('Event title').props.value).toBe('Planning');
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    input('Event title', 'Kept warm edit');
    detailQuery = { ...detailQuery, data: { data: { ...event, title: 'Server refresh', version: 3 } },
      isLoading: false, isFetching: false, fetchStatus: 'idle', isError: true, error: new Error('refresh failed') };
    act(() => renderer!.update(<React.Profiler id="warm-calendar-edit" onRender={capture}><Host screen={React.cloneElement(element)} /></React.Profiler>));
    expect(field('Event title').props.value).toBe('Kept warm edit');
    expect(text('Your draft and original version are kept.')).toBe(true);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
  });

  it('hydrates a cold edit once when data arrives and distinguishes a paused wait', () => {
    setRoute('TeamCalendarEventForm', { id: event.id });
    detailQuery = { ...detailQuery, data: undefined, eligible: false, fetchStatus: 'idle' };
    render(<TeamCalendarEventFormScreen />);
    expect(text('Event is waiting to load')).toBe(true);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    detailQuery = { ...detailQuery, isLoading: true, fetchStatus: 'paused' };
    rerender();
    expect(text('You are offline')).toBe(true);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    detailQuery = query({ data: event });
    rerender();
    expect(field('Event title').props.value).toBe('Planning');
    expect(text('Could not load event')).toBe(false);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    input('Event title', 'Cold edit');
    detailQuery = query({ data: { ...event, title: 'Updated elsewhere', version: 4 } });
    rerender();
    expect(field('Event title').props.value).toBe('Cold edit');
  });

  it('uses keyboard-aware scrolling with pinned save/cancel for ordinary team members', () => {
    render(<TeamCalendarEventFormScreen />);
    const screen = renderer!.root.findByType(Screen);
    expect(screen.props.keyboardAware).toBe(true);
    expect(screen.props.bottomSafeArea).toBe(true);
    expect(renderer!.root.findByType(KeyboardAvoidingView)).toBeDefined();
    expect(renderer!.root.findByType(ScrollView).findAllByType(Action).some(node => node.props.children === 'Create event')).toBe(false);
    expect(action('Create event')).toBeDefined();
    expect(action('Cancel')).toBeDefined();
    expect(field('Event title')).toBeDefined();
    expect(renderer!.root.findByType(WorkspaceHeaderActions).props.disabled).toBe(false);
  });

  it.each([
    [lightTheme, 'android'], [darkTheme, 'android'], [lightTheme, 'ios'], [darkTheme, 'ios'],
  ] as const)('keeps the %s calendar form footer safe-area owner and controls mounted across %s keyboard toggles', (theme, os) => {
    mockTheme = theme;
    jest.replaceProperty(Platform, 'OS', os);
    render(<TeamCalendarEventFormScreen />);
    input('Event title', 'Kept calendar draft');
    const screen = renderer!.root.findByType(Screen);
    const header = renderer!.root.findByType(PageHeader);
    const title = field('Event title');
    const save = action('Create event');
    const cancel = action('Cancel');
    const footer = screen.findAllByType(View).find(node => node.props.testID === 'screen-footer')!;
    const outerSafeArea = screen.findAllByType(View).find(node => Array.isArray(node.props.edges))!;
    const scroll = screen.findByType(ScrollView);
    const footerStyle = StyleSheet.flatten(footer.props.style);
    expect(screen.props.bottomSafeArea).toBe(true);
    expect(screen.props.footerStyle).toBeUndefined();
    expect(outerSafeArea.props.edges).toEqual(['top']);
    expect(footer.props.edges).toEqual({ bottom: 'additive' });
    expect(footerStyle).toMatchObject({ paddingHorizontal: 18, paddingVertical: 12, borderTopWidth: 1, borderTopColor: theme.line, backgroundColor: theme.surface });
    expect(footer.findAllByType(Action)).toEqual([save, cancel]);
    expect(scroll.findAllByType(Action).some(node => node === save || node === cancel)).toBe(false);
    expect(screen.findByType(KeyboardAvoidingView).props.enabled).toBe(os === 'ios');
    expect(screen.findByType(KeyboardAvoidingView).props.behavior).toBe(os === 'ios' ? 'padding' : undefined);

    jest.mocked(useKeyboardVisible).mockReturnValue(true);
    rerender();
    expect(screen.findAllByType(View).find(node => node.props.testID === 'screen-footer')).toBe(footer);
    expect(footer.props.edges).toEqual({ bottom: 'off' });
    expect(StyleSheet.flatten(footer.props.style)).toEqual(footerStyle);
    expect(renderer!.root.findByType(PageHeader)).toBe(header);
    expect(field('Event title')).toBe(title);
    expect(title.props.value).toBe('Kept calendar draft');
    expect(action('Create event')).toBe(save);
    expect(action('Cancel')).toBe(cancel);
    expect(outerSafeArea.props.edges).toEqual(['top']);

    jest.mocked(useKeyboardVisible).mockReturnValue(false);
    rerender();
    expect(screen.findAllByType(View).find(node => node.props.testID === 'screen-footer')).toBe(footer);
    expect(footer.props.edges).toEqual({ bottom: 'additive' });
    expect(field('Event title')).toBe(title);
    expect(action('Create event')).toBe(save);
    expect(action('Cancel')).toBe(cancel);
  });

  it('validates required title and native Manila schedule without sending invalid events', async () => {
    render(<TeamCalendarEventFormScreen />);
    pressAction('Create event');
    expect(text('Enter an event title')).toBe(true);
    input('Event title', '  Planning  ');
    const starts = renderer!.root.findAllByType(ManilaDateTimeField)[0];
    const ends = renderer!.root.findAllByType(ManilaDateTimeField)[1];
    act(() => ends.props.onChange(starts.props.value));
    pressAction('Create event');
    expect(text('End time must be after start time')).toBe(true);
    expect(createRequest).not.toHaveBeenCalled();
    press('Choose starts date');
    const picker = renderer!.root.findByType(DateTimePicker);
    expect(picker.props.timeZoneName).toBe('Asia/Manila');
    expect(picker.props.value.toISOString()).toBe('2026-12-10T02:00:00.000Z');
    act(() => picker.props.onChange({ type: 'set' }, new Date('2026-12-11T02:00:00Z')));
    expect(renderer!.root.findAllByType(ManilaDateTimeField)[0].props.value).toBe('2026-12-11T10:00');
    await settle();
  });

  it('blocks duplicate submit and every field/participant/back control while saving and marks saved before navigation', async () => {
    const pending = deferred<{ data: TeamCalendarEventDetail }>();
    createRequest.mockImplementationOnce(() => pending.promise);
    render(<TeamCalendarEventFormScreen />);
    input('Event title', '  Planning  ');
    input('Event description', ' Notes ');
    press('Alex Member, staff');
    const remove = control('Remove Alex Member').props.onPress;
    const changeTitle = field('Event title').props.onChangeText;
    const submit = action('Create event').props.onPress;
    act(() => { submit(); submit(); remove(); changeTitle('wrong'); });
    expect(createRequest).toHaveBeenCalledTimes(1);
    expect(createRequest).toHaveBeenCalledWith(expect.objectContaining({ title: 'Planning', description: 'Notes', participant_ids: [7], client_nonce: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee' }));
    expect(field('Event title').props.value).toBe('  Planning  ');
    expect(field('Event title').props.editable).toBe(false);
    expect(control('Remove Alex Member').props.disabled).toBe(true);
    expect(control('Choose starts date').props.disabled).toBe(true);
    expect(workspace.hasPendingSubmission).toBe(true);
    expect(renderer!.root.findByType(PageHeader).props.backDisabled).toBe(true);
    act(() => renderer!.root.findByType(PageHeader).props.onBack());
    expect(navigation.goBack).not.toHaveBeenCalled();
    await act(async () => pending.resolve({ data: event }));
    expect(workspace.hasDirtyDrafts).toBe(false);
    expect(workspace.hasPendingSubmission).toBe(false);
    expect(navigation.replace).toHaveBeenCalledWith('TeamCalendarEventDetail', { id: event.id });
    act(() => submit());
    expect(createRequest).toHaveBeenCalledTimes(1);
  });

  it('does not automatically replay ambiguous create and retries the exact locked payload and nonce only on request', async () => {
    createRequest.mockRejectedValueOnce(new NetworkError()).mockResolvedValueOnce({ data: event });
    render(<TeamCalendarEventFormScreen />);
    input('Event title', 'Planning');
    press('Alex Member, staff');
    pressAction('Create event');
    await settle();
    expect(createRequest).toHaveBeenCalledTimes(1);
    await settle(30000);
    expect(createRequest).toHaveBeenCalledTimes(1);
    expect(field('Event title').props.editable).toBe(false);
    expect(control('Remove Alex Member').props.disabled).toBe(true);
    expect(action('Retry create safely')).toBeDefined();
    const first = createRequest.mock.calls[0][0];
    pressAction('Retry create safely');
    await settle();
    expect(createRequest).toHaveBeenCalledTimes(2);
    expect(createRequest.mock.calls[1][0]).toBe(first);
    expect(secureClientNonce).toHaveBeenCalledTimes(1);
  });

  it('warns before abandoning an ambiguous request and clears its nonce only after dialog dismissal', async () => {
    createRequest.mockRejectedValueOnce(new NetworkError()).mockResolvedValue({ data: event });
    render(<TeamCalendarEventFormScreen />);
    input('Event title', 'Planning');
    pressAction('Create event');
    await settle();
    pressAction('Change draft');
    const warning = dialog('Change an unconfirmed event?');
    expect(warning.props.visible).toBe(true);
    act(() => action('Change draft', warning).props.onPress());
    expect(warning.props.visible).toBe(false);
    expect(renderer!.root.findAllByType(Modal).filter(node => node.props.visible)).toHaveLength(0);
    expect(field('Event title').props.editable).toBe(false);
    await settle(40);
    expect(renderer!.root.findAllByType(Modal)).toHaveLength(0);
    expect(field('Event title').props.editable).toBe(true);
    jest.mocked(secureClientNonce).mockReturnValue('ffffffff-ffff-4fff-8fff-ffffffffffff');
    input('Event title', 'Different planning');
    pressAction('Create event');
    await settle();
    expect(createRequest.mock.calls[1][0]).toEqual(expect.objectContaining({ title: 'Different planning', client_nonce: 'ffffffff-ffff-4fff-8fff-ffffffffffff' }));
  });

  it('guards native/header/cancel leave, keeps edits on cancel, and resets them before continuing after native dismiss', async () => {
    render(<TeamCalendarEventFormScreen />);
    input('Event title', 'Draft title');
    input('Search team members', 'Alex');
    await settle(250);
    press('Alex Member, staff');
    const guards = jest.mocked(usePreventRemove).mock.calls;
    const guard = guards[guards.length - 1];
    expect(guard[0]).toBe(true);
    act(() => guard[1]({ data: { action: { type: 'GO_BACK' } } } as never));
    expect(workspace.pendingDiscard).toBe(true);
    pressAction('Keep editing');
    await settle(40);
    expect(field('Event title').props.value).toBe('Draft title');
    pressAction('Cancel');
    pressAction('Discard and continue');
    expect(field('Event title').props.value).toBe('');
    expect(field('Search team members').props.value).toBe('');
    expect(navigation.goBack).not.toHaveBeenCalled();
    await settle(40);
    expect(navigation.goBack).toHaveBeenCalledTimes(1);
    expect(workspace.hasDirtyDrafts).toBe(false);
  });

  it('opening the unit chooser is non-destructive and does not discard a draft', () => {
    profile.entitlements.work.units.push({ ...profile.entitlements.work.units[0], unit: 'guidance', label: 'Guidance' });
    render(<TeamCalendarEventFormScreen />);
    act(() => workspace.selectUnit('clinic'));
    input('Event title', 'Keep this');
    press('Switch Team Workspace unit');
    expect(workspace.unitPickerVisible).toBe(true);
    expect(workspace.pendingDiscard).toBe(false);
    expect(field('Event title').props.value).toBe('Keep this');
  });

  it('keeps loaded snapshot/version and all draft fields through transient refresh and stale conflicts', async () => {
    setRoute('TeamCalendarEventForm', { id: event.id });
    render(<TeamCalendarEventFormScreen />);
    input('Event title', 'My draft');
    detailQuery = { ...detailQuery, data: { data: { ...event, title: 'Other device', version: 3 } }, isError: true, error: new NetworkError() };
    rerender();
    expect(field('Event title').props.value).toBe('My draft');
    expect(text('original version are kept')).toBe(true);
    updateRequest.mockRejectedValueOnce(new ApiError(409, { code: 'stale_version', message: 'Changed' }));
    pressAction('Save changes');
    await settle();
    expect(updateRequest).toHaveBeenCalledWith(expect.objectContaining({ title: 'My draft', version: 2 }));
    expect(action('Save changes').props.disabled).toBe(true);
    expect(action('Review latest event')).toBeDefined();
    expect(field('Event title').props.value).toBe('My draft');
    pressAction('Review latest event');
    expect(workspace.pendingDiscard).toBe(true);
  });

  it('retains the draft for a record-level 403 without falsely treating it as lost unit access', async () => {
    setRoute('TeamCalendarEventForm', { id: event.id });
    render(<TeamCalendarEventFormScreen />);
    input('Event title', 'My draft');
    updateRequest.mockRejectedValueOnce(new ApiError(403, { code: 'forbidden', message: 'You cannot edit this event.' }));
    pressAction('Save changes');
    await settle();
    expect(field('Event title').props.value).toBe('My draft');
    expect(text('Your draft and calendar remain available')).toBe(true);
    expect(refreshProfile).not.toHaveBeenCalled();
    expect(navigation.goBack).not.toHaveBeenCalled();
  });

  it('retains organizer/manager edit permissions and never queues an offline save', async () => {
    setRoute('TeamCalendarEventForm', { id: event.id });
    detailQuery = { ...detailQuery, data: { data: { ...event, can_edit: false } } };
    render(<TeamCalendarEventFormScreen />);
    expect(action('Save changes').props.disabled).toBe(true);
    expect(field('Event title').props.editable).toBe(false);
    pressAction('Save changes');
    expect(updateRequest).not.toHaveBeenCalled();
    act(() => renderer!.unmount());
    renderer = undefined;
    setRoute('TeamCalendarEventForm');
    render(<TeamCalendarEventFormScreen />);
    input('Event title', 'Offline draft');
    act(() => onlineManager.setOnline(false));
    pressAction('Create event');
    await settle();
    expect(createRequest).not.toHaveBeenCalled();
    expect(field('Event title').props.value).toBe('Offline draft');
    act(() => onlineManager.setOnline(true));
    await settle();
    expect(createRequest).not.toHaveBeenCalled();
  });

  it('pages member metadata, retains selections off-page, and retries errors without discarding selected rows', async () => {
    jest.mocked(hooks.useTeamCalendarMemberSearch).mockImplementation((_unit, q, page = 1) => {
      if (memberQuery.isError) { return memberQuery as unknown as ReturnType<typeof hooks.useTeamCalendarMemberSearch>; }
      return { ...memberQuery, data: { data: q ? [other] : page === 1 ? [member] : [other], meta: { total: 301, page, pages: 7, limit: 50 } } } as unknown as ReturnType<typeof hooks.useTeamCalendarMemberSearch>;
    });
    render(<TeamCalendarEventFormScreen />);
    press('Alex Member, staff');
    for (let page = 1; page < 6; page += 1) { pressAction('Next members'); }
    expect(jest.mocked(hooks.useTeamCalendarMemberSearch).mock.calls.some(call => call[2] === 6)).toBe(true);
    expect(control('Remove Alex Member')).toBeDefined();
    expect(control('Bea Member, staff').props.accessibilityState.checked).toBe(false);
    input('Search team members', 'Bea');
    await settle(250);
    expect(renderer!.root.findAll(node => node.props.accessibilityRole === 'checkbox' && node.props.accessibilityLabel === 'Alex Member, staff')).toHaveLength(0);
    expect(control('Remove Alex Member')).toBeDefined();
    press('Bea Member, staff');
    expect(control('Bea Member, staff').props.accessibilityState.checked).toBe(true);
    memberQuery = { ...memberQuery, data: undefined, isError: true, error: new NetworkError() };
    rerender();
    expect(control('Remove Alex Member')).toBeDefined();
    expect(control('Remove Bea Member')).toBeDefined();
    pressAction('Retry member search');
    await settle();
    expect(memberRefetch).toHaveBeenCalledTimes(1);
    expect(text('No matching members')).toBe(false);
  });

  it('blocks late picker callbacks and pending cancel/participant controls without losing the original payload', async () => {
    const pending = deferred<{ data: TeamCalendarEventDetail }>();
    createRequest.mockImplementationOnce(() => pending.promise);
    render(<TeamCalendarEventFormScreen />);
    input('Event title', 'Planning');
    press('Alex Member, staff');
    press('Choose starts date');
    const pick = renderer!.root.findByType(DateTimePicker).props.onChange;
    const originalStart = renderer!.root.findAllByType(ManilaDateTimeField)[0].props.value;
    const cancel = action('Cancel').props.onPress;
    pressAction('Create event');
    expect(renderer!.root.findAllByType(DateTimePicker)).toHaveLength(0);
    act(() => { pick({ type: 'set' }, new Date('2027-01-01T02:00:00Z')); cancel(); });
    expect(renderer!.root.findAllByType(ManilaDateTimeField)[0].props.value).toBe(originalStart);
    expect(createRequest.mock.calls[0][0].starts_at).toBe(originalStart);
    expect(navigation.goBack).not.toHaveBeenCalled();
    expect(workspace.pendingDiscard).toBe(false);
    expect(action('Cancel').props.disabled).toBe(true);
    act(() => renderer!.unmount()); renderer = undefined;
    await act(async () => pending.resolve({ data: event }));
    expect(navigation.replace).not.toHaveBeenCalled();
  });

  it('keeps the pinned actions keyboard-aware on iOS as well as Android', () => {
    jest.replaceProperty(Platform, 'OS', 'ios');
    render(<TeamCalendarEventFormScreen />);
    const keyboard = renderer!.root.findByType(KeyboardAvoidingView);
    expect(keyboard.props.behavior).toBe('padding');
    expect(keyboard.props.enabled).toBe(true);
    expect(renderer!.root.findByType(ScrollView).props.keyboardShouldPersistTaps).toBe('handled');
    expect(renderer!.root.findByType(ScrollView).findAllByType(Action).some(node => node.props.children === 'Create event')).toBe(false);
    press('Choose starts time');
    expect(renderer!.root.findByType(DateTimePicker).props.timeZoneName).toBe('Asia/Manila');
    expect(renderer!.root.findByType(DateTimePicker).props.display).toBe('spinner');
    expect(action('Done')).toBeDefined();
  });

  it('reinitializes only on a new route record and has a no-history back fallback', async () => {
    setRoute('TeamCalendarEventForm', { id: event.id });
    render(<TeamCalendarEventFormScreen />);
    input('Event title', 'Old draft');
    detailQuery = { ...detailQuery, data: { data: { ...event, id: 9, title: 'New record', version: 6 } } };
    setRoute('TeamCalendarEventForm', { id: 9 });
    rerender();
    expect(field('Event title').props.value).toBe('New record');
    expect(workspace.hasDirtyDrafts).toBe(false);
    navigation.canGoBack.mockReturnValue(false);
    act(() => renderer!.root.findByType(PageHeader).props.onBack());
    expect(navigation.replace).toHaveBeenCalledWith('TeamCalendarEventDetail', { id: 9 });
    await settle();
  });
});

describe('mounted event cancellation', () => {
  it('uses a detail skeleton for an uncached active event and a waiting state for paused reads', () => {
    detailQuery = { ...detailQuery, data: undefined, isLoading: false, isFetching: true, fetchStatus: 'fetching' };
    render(<TeamCalendarEventDetailScreen />);
    expect(renderer!.root.findByType(Skeleton).props).toMatchObject({ label: 'Loading event', variant: 'detail' });
    expect(renderer!.root.findByType(PageHeader).props.title).toBe('Event details');
    detailQuery = { ...detailQuery, isLoading: true, fetchStatus: 'paused', eligible: false };
    rerender();
    expect(text('You are offline')).toBe(true);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    detailQuery = { ...query({ data: event }), isLoading: true, isFetching: true, fetchStatus: 'fetching' };
    rerender();
    expect(text('Planning')).toBe(true);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    detailQuery = { ...detailQuery, isLoading: false, isFetching: false, isError: true, error: new Error('refresh failed') };
    rerender();
    expect(text('Planning')).toBe(true);
    expect(text('Showing the last available event details')).toBe(true);
  });

  it.each([401, 403, 404])('does not replace a protected %s read with cached event content or a skeleton', async status => {
    detailQuery = { ...detailQuery, isLoading: true, isFetching: true, fetchStatus: 'fetching',
      isError: true, error: new ApiError(status, { code: 'denied', message: 'Unavailable' }) };
    render(<TeamCalendarEventDetailScreen />);
    await settle();
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    expect(text('Planning')).toBe(false);
    expect(renderer!.root.findAllByType(IconAction).some(node => node.props.label === 'Edit event')).toBe(false);
  });

  beforeEach(() => setRoute('TeamCalendarEventDetail', { id: event.id }));

  it('uses the shared cancel dialog, keeps event on dismiss, and blocks duplicate/pending actions', async () => {
    const pending = deferred<{ data: TeamCalendarEventDetail }>();
    cancelRequest.mockImplementationOnce(() => pending.promise);
    render(<TeamCalendarEventDetailScreen />);
    pressAction('Cancel event');
    expect(dialog('Cancel event?').props.visible).toBe(true);
    const visibleModal = renderer!.root.findAllByType(Modal).find(node => node.props.visible)!;
    act(() => visibleModal.props.onRequestClose());
    await settle(40);
    expect(cancelRequest).not.toHaveBeenCalled();
    pressAction('Cancel event');
    const confirm = action('Cancel event', dialog('Cancel event?')).props.onPress;
    act(() => { confirm(); confirm(); });
    expect(cancelRequest).toHaveBeenCalledTimes(1);
    expect(cancelRequest).toHaveBeenCalledWith(2);
    expect(dialog('Cancel event?').props.loading).toBe(true);
    expect(renderer!.root.findByType(PageHeader).props.backDisabled).toBe(true);
    expect(workspace.hasPendingSubmission).toBe(true);
    act(() => renderer!.root.findByType(PageHeader).props.onBack());
    expect(navigation.goBack).not.toHaveBeenCalled();
    await act(async () => pending.resolve({ data: { ...event, status: 'cancelled' } }));
    await settle(40);
    expect(text('cancelled')).toBe(true);
    expect(renderer!.root.findAllByType(Action).some(node => node.props.children === 'Edit event')).toBe(false);
    act(() => confirm());
    expect(cancelRequest).toHaveBeenCalledTimes(1);
  });

  it('requires explicit review after a stale cancellation before using the latest version', async () => {
    cancelRequest.mockRejectedValueOnce(new ApiError(409, { code: 'stale_version', message: 'Changed' }));
    render(<TeamCalendarEventDetailScreen />);
    pressAction('Cancel event');
    act(() => action('Cancel event', dialog('Cancel event?')).props.onPress());
    await settle(40);
    expect(action('Cancel event').props.disabled).toBe(true);
    expect(action('Edit event').props.disabled).toBe(true);
    detailQuery = { ...detailQuery, data: { data: { ...event, version: 3 } } };
    rerender();
    pressAction('Review latest');
    await settle();
    pressAction('Cancel event');
    act(() => action('Cancel event', dialog('Cancel event?')).props.onPress());
    await settle(40);
    expect(cancelRequest.mock.calls.map(call => call[0])).toEqual([2, 3]);
  });

  it('retries transient cancellation explicitly with the reviewed version even after a quiet cache refresh', async () => {
    cancelRequest.mockRejectedValueOnce(new NetworkError());
    render(<TeamCalendarEventDetailScreen />);
    pressAction('Cancel event');
    act(() => action('Cancel event', dialog('Cancel event?')).props.onPress());
    await settle(40);
    expect(cancelRequest).toHaveBeenCalledTimes(1);
    expect(dialog('Cancel event?').props.visible).toBe(false);
    expect(renderer!.root.findAllByType(Modal).filter(node => node.props.visible)).toHaveLength(0);
    detailQuery = { ...detailQuery, data: { data: { ...event, version: 3 } } };
    rerender();
    pressAction('Retry cancellation');
    expect(dialog('Cancel event?').props.visible).toBe(true);
    // A new request queues behind the outgoing native presentation. Advance its
    // exit frame, then confirm the real visible dialog (never a hidden host).
    await settle(40);
    expect(renderer!.root.findAllByType(Modal).filter(node => node.props.visible)).toHaveLength(1);
    act(() => action('Cancel event', dialog('Cancel event?')).props.onPress());
    await settle(40);
    expect(cancelRequest.mock.calls.map(call => call[0])).toEqual([2, 2]);
  });

  it('preserves readable detail and does not exit workspace after a denied record mutation', async () => {
    cancelRequest.mockRejectedValueOnce(new ApiError(403, { code: 'forbidden', message: 'Not organizer' }));
    render(<TeamCalendarEventDetailScreen />);
    pressAction('Cancel event');
    act(() => action('Cancel event', dialog('Cancel event?')).props.onPress());
    await settle(40);
    expect(text('Team planning')).toBe(true);
    expect(text('Your calendar remains available')).toBe(true);
    expect(refreshProfile).not.toHaveBeenCalled();
    expect(navigation.goBack).not.toHaveBeenCalled();
  });

  it('hides edit/cancel for a non-editor and preserves cached read data during a transient refresh', () => {
    detailQuery = { ...detailQuery, data: { data: { ...event, can_edit: false } }, isError: true, error: new NetworkError() };
    render(<TeamCalendarEventDetailScreen />);
    expect(text('Team planning')).toBe(true);
    expect(text('last available event details')).toBe(true);
    expect(action('Retry refresh')).toBeDefined();
    expect(renderer!.root.findAllByType(Action).some(node => ['Cancel event', 'Edit event'].includes(node.props.children))).toBe(false);
  });
});
