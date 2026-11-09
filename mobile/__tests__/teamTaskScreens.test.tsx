import React from 'react';
import { ActivityIndicator, AppState, Keyboard, KeyboardAvoidingView, Modal, Platform, ScrollView, SectionList, StyleSheet, Text, TextInput, View } from 'react-native';
import type { KeyboardEvent } from 'react-native';
import DateTimePicker from '@react-native-community/datetimepicker';
import { act, create, ReactTestInstance, ReactTestRenderer } from 'react-test-renderer';
import { usePreventRemove } from '@react-navigation/native';
import { ApiError, NetworkError } from '../src/api/client';
import type { TeamTaskDetail, TeamTaskFilters } from '../src/api/endpoints/teamTasks';
import { Action } from '../src/components/Action';
import { BottomSheet } from '../src/components/BottomSheet';
import { darkTheme, lightTheme } from '../src/theme/tokens';
import { PageHeader } from '../src/components/PageHeader';
import { Screen } from '../src/components/Screen';
import { TextField } from '../src/components/TextField';
import { secureClientNonce } from '../src/lib/secureNonce';
import { WorkspaceHeaderActions } from '../src/features/workspace/WorkspaceHeaderActions';
import * as hooks from '../src/features/team-tasks/hooks';
import { TeamTaskFormScreen } from '../src/features/team-tasks/screens/TeamTaskFormScreen';
import { TeamTaskDetailScreen } from '../src/features/team-tasks/screens/TeamTaskDetailScreen';
import { TeamTaskListScreen } from '../src/features/team-tasks/screens/TeamTaskListScreen';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/lib/secureNonce', () => ({ secureClientNonce: jest.fn() }));
jest.mock('../src/features/team-tasks/hooks', () => ({
  useTeamTask: jest.fn(), useTeamTasks: jest.fn(), useTeamMemberSearch: jest.fn(), useTeamTaskAccessRecovery: jest.fn(),
  useTeamTaskScreenScope: jest.requireActual('../src/features/team-tasks/hooks').useTeamTaskScreenScope,
  useCreateTeamTask: jest.fn(), useUpdateTeamTask: jest.fn(), useChangeTeamTaskStatus: jest.fn(), useCommentTeamTask: jest.fn(),
}));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: mockTheme }) }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: () => true }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View, useSafeAreaInsets: () => ({ top: 24, right: 0, bottom: 20, left: 0 }) }));
jest.mock('@react-native-community/datetimepicker', () => 'DateTimePicker');
jest.mock('lucide-react-native', () => ({
  ArrowLeft: () => null, CalendarDays: () => null, Check: () => null, Plus: () => null, Trash2: () => null,
  Users: () => null, X: () => null, AlertTriangle: () => null, CheckSquare2: () => null, Search: () => null,
  Edit3: () => null, MessageSquare: () => null, RefreshCw: () => null, Square: () => null,
  ChevronRight: () => null, Flag: () => null, SlidersHorizontal: () => null, UserRound: () => null,
}));
jest.mock('@react-navigation/native', () => ({
  NavigationContext: require('react').createContext(null),
  useNavigation: () => mockNavigation, useRoute: () => mockRoute, usePreventRemove: jest.fn(),
}));
jest.mock('../src/store/auth', () => ({ useAuth: () => ({ user: mockIdentity, refreshProfile: mockReconcile }) }));
jest.mock('../src/features/workspace/WorkspaceContext', () => ({ useWorkspace: () => mockWorkspace }));
jest.mock('../src/features/workspace/WorkspaceHeaderActions', () => ({
  WorkspaceHeaderActions: ({ children, disabled }: { children?: React.ReactNode; disabled?: boolean }) => {
    const ReactModule = require('react');
    const { View: NativeView, Pressable } = require('react-native');
    return ReactModule.createElement(NativeView, null,
      mockWorkspace.units.length > 1 ? ReactModule.createElement(Pressable, { accessibilityLabel: 'Switch Team Workspace unit', disabled, onPress: disabled ? undefined : mockWorkspace.openUnitPicker }) : null,
      children);
  },
}));

let mockIdentity = { id: 5, tenant_id: 7 };
let mockTheme = lightTheme;
const mockNavigation = { goBack: jest.fn(), navigate: jest.fn(), replace: jest.fn(), dispatch: jest.fn() };
let mockRoute: { key: string; name: string; params?: { id?: number } };
const mockDrafts = new Map<string, { onDiscard: () => void; warning?: string }>();
const mockSubmissions = new Set<string>();
let mockPendingLeave: (() => void) | undefined;
const mockReconcile = jest.fn();
const mockWorkspace = {
  activeUnit: { unit: 'clinic', label: 'Clinic' } as { unit: string; label: string } | null,
  units: [{ unit: 'clinic', label: 'Clinic' }],
  openUnitPicker: jest.fn(),
  registerDirtyDraft: jest.fn((key: string, options: { onDiscard: () => void; warning?: string }) => {
    mockDrafts.set(key, options);
    return () => { mockDrafts.delete(key); };
  }),
  registerSubmission: jest.fn((key: string) => {
    mockSubmissions.add(key);
    return () => { mockSubmissions.delete(key); };
  }),
  requestDiscard: jest.fn((continueAction: () => void, source?: string) => {
    if (mockSubmissions.size > 0) { return; }
    const drafts = [...mockDrafts.entries()].filter(([key]) => source === undefined || key === source);
    if (drafts.length === 0) { continueAction(); return; }
    mockPendingLeave = () => { for (const [, draft] of drafts) { draft.onDiscard(); } continueAction(); };
  }),
};
const createRequest = jest.fn();
const updateRequest = jest.fn();
const statusRequest = jest.fn();
const commentRequest = jest.fn();
const refetch = jest.fn();
const fetchNextPage = jest.fn();
const refetchMembers = jest.fn();
let renderer: ReactTestRenderer | undefined;
let keyboardCleanup: (() => void) | undefined;
let element: React.ReactElement;
let detailQuery: Partial<Record<keyof ReturnType<typeof hooks.useTeamTask>, unknown>> & { data?: { data: TeamTaskDetail } };
let listQuery: Partial<Record<keyof ReturnType<typeof hooks.useTeamTasks>, unknown>> & { data?: NonNullable<ReturnType<typeof hooks.useTeamTasks>['data']> };
let membersQuery: ReturnType<typeof hooks.useTeamMemberSearch>;

function task(overrides: Partial<TeamTaskDetail> = {}): TeamTaskDetail {
  return {
    id: 12, title: 'Check supplies', description: 'Count stock', status: 'todo', priority: 'normal',
    assignee: { id: 9, name: 'Alex' }, due_date: '2026-11-08', checklist: [{ text: 'Count boxes', done: false }],
    checklist_progress: { done: 0, total: 1 }, version: 4, can_edit: true,
    created_at: '2026-11-07T08:00:00+08:00', updated_at: '2026-11-07T08:00:00+08:00',
    comments: [], comments_total: 0, comments_limit: 50, activity: [], activity_total: 0, activity_limit: 50, ...overrides,
  };
}
function queryResult<T>(data: T) {
  return { data, isLoading: false, isError: false, error: null, isFetching: false, isRefetching: false, isPlaceholderData: false, refetch };
}
function render(node: React.ReactElement) {
  element = node;
  act(() => { renderer = create(element); });
}
function rerender() { act(() => renderer!.update(React.cloneElement(element))); }
function field(label: string) { return renderer!.root.findAllByType(TextInput).find(node => node.props.accessibilityLabel === label)!; }
function change(label: string, value: string) { act(() => field(label).props.onChangeText(value)); }
function button(label: string) {
  return renderer!.root.findAll(node => node.props.accessibilityLabel === label && typeof node.props.onPress === 'function')[0];
}
function press(label: string) { act(() => button(label).props.onPress()); }
function action(label: string) { return renderer!.root.findAllByType(Action).find(node => node.props.children === label)!; }
async function tap(label: string) { await act(async () => { await action(label).props.onPress(); }); }
function hasText(text: string) { return renderer!.root.findAllByType(Text).some(node => node.props.children === text); }
function guard() { return jest.mocked(usePreventRemove).mock.calls.at(-1)!; }
function headerBack() { act(() => renderer!.root.findByType(PageHeader).props.onBack()); }
function confirmDiscard() { act(() => { const leave = mockPendingLeave; mockPendingLeave = undefined; leave?.(); }); }
function mutation(fn: jest.Mock) { return { mutateAsync: fn, isPending: false, reset: jest.fn() }; }
function addItem(text: string) { change('New checklist item', text); press('Add checklist item'); }
function filters(): TeamTaskFilters { return jest.mocked(hooks.useTeamTasks).mock.calls.at(-1)![1]; }
function filterChoice(label: string) { return renderer!.root.findAll(node => node.props.accessibilityRole === 'radio' && node.props.accessibilityLabel === label && typeof node.props.onPress === 'function')[0]; }
function finishSheetDismissal() {
  act(() => { renderer!.root.findAllByType(Modal).filter(node => !node.props.visible).forEach(node => node.props.onDismiss?.()); });
  act(() => { jest.advanceTimersByTime(40); });
}
function expectOutsideScroll(node: ReactTestInstance) {
  let parent = node.parent;
  while (parent && parent.type !== Screen) { expect(parent.type).not.toBe(ScrollView); parent = parent.parent; }
  expect(parent?.type).toBe(Screen);
}

/** Native measurements reproduce the edge-to-edge phone: keyboard covers an unresized root. */
function keyboardViewport() {
  let visible = false;
  let scrollY = 0;
  let viewportHeight = 820;
  const keyboardTop = 623;
  const listeners = new Map<string, Array<{ emit: (event: KeyboardEvent) => void; remove: jest.Mock }>>();
  const coordinates = { screenX: 0, screenY: keyboardTop, width: 480, height: 345 };
  jest.spyOn(Keyboard, 'isVisible').mockImplementation(() => visible);
  jest.spyOn(Keyboard, 'metrics').mockImplementation(() => visible ? coordinates : undefined);
  jest.spyOn(Keyboard, 'addListener').mockImplementation((event, listener) => {
    const subscription = { emit: listener, remove: jest.fn() };
    listeners.set(event, [...(listeners.get(event) ?? []), subscription]);
    return subscription;
  });
  const viewPrototype = View.prototype as React.ComponentRef<typeof View>;
  const scrollPrototype = ScrollView.prototype as React.ComponentRef<typeof ScrollView>;
  const previousAppState = AppState.currentState;
  AppState.currentState = 'active';
  keyboardCleanup = () => { AppState.currentState = previousAppState; };
  jest.spyOn(viewPrototype, 'measureInWindow').mockImplementation(callback => callback(0, 0, 480, 968));
  const measureViewport = jest.fn<ReturnType<React.ComponentRef<typeof View>['measureInWindow']>, Parameters<React.ComponentRef<typeof View>['measureInWindow']>>(callback => callback(18, 110, 444, viewportHeight));
  jest.spyOn(scrollPrototype, 'getNativeScrollRef').mockReturnValue({ measureInWindow: measureViewport } as unknown as React.ComponentRef<typeof ScrollView>);
  const scrollTo = jest.spyOn(scrollPrototype, 'scrollTo').mockImplementation(options => {
    if (options && typeof options === 'object' && typeof options.y === 'number') {
      scrollY = options.y;
      const viewport = renderer?.root.findAllByType(ScrollView)[0];
      viewport?.props.onScroll?.({ nativeEvent: { contentOffset: { x: 0, y: scrollY } } });
    }
  });
  const emit = (name: string) => {
    const event = { duration: 0, easing: 'keyboard', endCoordinates: coordinates } as KeyboardEvent;
    for (const subscription of listeners.get(name) ?? []) { subscription.emit(event); }
  };
  return {
    scrollTo,
    measureViewport,
    listeners,
    keyboardTop,
    nativeInput(label: string, top: number, height: number) {
      const input = field(label);
      const instance = input.instance as React.ComponentRef<typeof TextInput>;
      const inputMeasure = jest.fn<ReturnType<typeof instance.measureInWindow>, Parameters<typeof instance.measureInWindow>>(callback => callback(18, top - scrollY, 444, height));
      instance.measureInWindow = inputMeasure;
      jest.spyOn(instance, 'isFocused').mockReturnValue(true);
      jest.spyOn(TextInput.State, 'currentlyFocusedInput').mockReturnValue(instance as NonNullable<ReturnType<typeof TextInput.State.currentlyFocusedInput>>);
      return { input, inputMeasure, bottom: () => top - scrollY + height };
    },
    show() { visible = true; act(() => { emit('keyboardWillShow'); emit('keyboardDidShow'); }); },
    hide() { visible = false; act(() => { emit('keyboardWillHide'); emit('keyboardDidHide'); }); },
    resetScroll() {
      scrollY = 0;
      act(() => renderer!.root.findByType(ScrollView).props.onScroll?.({ nativeEvent: { contentOffset: { x: 0, y: 0 } } }));
    },
    resize(height: number) {
      viewportHeight = height;
      act(() => renderer!.root.findByType(ScrollView).props.onLayout?.({ nativeEvent: { layout: { x: 18, y: 110, width: 444, height } } }));
    },
  };
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers();
  jest.setSystemTime(new Date('2026-11-07T18:00:00Z'));
  mockIdentity = { id: 5, tenant_id: 7 };
  mockTheme = lightTheme;
  mockRoute = { key: 'task-route', name: 'TeamTaskForm', params: {} };
  mockDrafts.clear(); mockSubmissions.clear(); mockPendingLeave = undefined;
  mockWorkspace.units = [{ unit: 'clinic', label: 'Clinic' }];
  mockWorkspace.activeUnit = mockWorkspace.units[0];
  createRequest.mockReset().mockResolvedValue({ data: task() });
  updateRequest.mockReset().mockResolvedValue({ data: task({ version: 5 }) });
  statusRequest.mockReset().mockResolvedValue({ data: task({ status: 'done', version: 5 }) });
  commentRequest.mockReset().mockResolvedValue({ data: task() });
  refetch.mockReset().mockResolvedValue({ data: { data: task() }, isError: false });
  fetchNextPage.mockReset().mockResolvedValue(undefined);
  refetchMembers.mockReset().mockResolvedValue(undefined);
  mockReconcile.mockReset().mockResolvedValue(undefined);
  let nonce = 0;
  jest.mocked(secureClientNonce).mockImplementation(() => `11111111-1111-4111-8111-${String(++nonce).padStart(12, '0')}`);
  detailQuery = queryResult({ data: task() }) as unknown as typeof detailQuery;
  listQuery = queryResult({ data: [task()], meta: { counts: { todo: 1, in_progress: 0, blocked: 0, done: 0, total: 1 }, total: 1, page: 1, pages: 1, limit: 50, scope: 'team' } }) as unknown as typeof listQuery;
  membersQuery = {
    data: { pages: [{ data: [{ id: 9, name: 'Alex', role: 'clinic_staff' }, { id: 10, name: 'Blair', role: 'clinic_admin' }], meta: { unit: 'clinic', total: 2, page: 1, pages: 1 } }], pageParams: [1] },
    isLoading: false, isError: false, error: null, isFetching: false, isFetchingNextPage: false, isFetchNextPageError: false, hasNextPage: false, fetchNextPage, refetch: refetchMembers,
  } as unknown as typeof membersQuery;
  jest.mocked(hooks.useTeamTask).mockImplementation(() => detailQuery as unknown as ReturnType<typeof hooks.useTeamTask>);
  jest.mocked(hooks.useTeamTasks).mockImplementation(() => listQuery as unknown as ReturnType<typeof hooks.useTeamTasks>);
  jest.mocked(hooks.useTeamMemberSearch).mockImplementation(() => membersQuery);
  jest.mocked(hooks.useTeamTaskAccessRecovery).mockReturnValue(mockReconcile);
  jest.mocked(hooks.useCreateTeamTask).mockReturnValue(mutation(createRequest) as unknown as ReturnType<typeof hooks.useCreateTeamTask>);
  jest.mocked(hooks.useUpdateTeamTask).mockReturnValue(mutation(updateRequest) as unknown as ReturnType<typeof hooks.useUpdateTeamTask>);
  jest.mocked(hooks.useChangeTeamTaskStatus).mockReturnValue(mutation(statusRequest) as unknown as ReturnType<typeof hooks.useChangeTeamTaskStatus>);
  jest.mocked(hooks.useCommentTeamTask).mockReturnValue(mutation(commentRequest) as unknown as ReturnType<typeof hooks.useCommentTeamTask>);
});
afterEach(() => {
  act(() => renderer?.unmount()); renderer = undefined;
  keyboardCleanup?.(); keyboardCleanup = undefined;
  jest.restoreAllMocks();
  jest.useRealTimers();
});

describe('Team Task form rendered handlers', () => {
  it('reveals the focused description through the shared keyboard viewport without moving or remounting the pinned actions', async () => {
    jest.replaceProperty(Platform, 'OS', 'android');
    const keyboard = keyboardViewport();
    render(<TeamTaskFormScreen />);
    const { input, bottom } = keyboard.nativeInput('Task description', 840, 100);
    change('Task description', 'Description draft - unsent');
    act(() => input.props.onFocus({ nativeEvent: {} }));
    keyboard.show();
    await act(async () => jest.advanceTimersByTimeAsync(500));
    expect(keyboard.measureViewport).toHaveBeenCalled();
    expect(keyboard.scrollTo).toHaveBeenCalled();
    expect(bottom() + 16).toBeLessThanOrEqual(keyboard.keyboardTop);
    expect(field('Task description')).toBe(input);
    expect(field('Task description').props.value).toBe('Description draft - unsent');
    expectOutsideScroll(action('Create Task'));
    expectOutsideScroll(action('Cancel'));
    expect(createRequest).not.toHaveBeenCalled();
    expect(updateRequest).not.toHaveBeenCalled();
  });

  it('pins actions outside the scroll in a keyboard-aware form and keeps create unassigned', async () => {
    render(<TeamTaskFormScreen />);
    expect(renderer!.root.findByType(Screen).props.keyboardAware).toBe(true);
    expect(renderer!.root.findByType(Screen).props.bottomSafeArea).toBe(true);
    expect(renderer!.root.findAllByType(KeyboardAvoidingView)).toHaveLength(1);
    expectOutsideScroll(action('Create Task'));
    expectOutsideScroll(renderer!.root.findByType(PageHeader));
    expect(button('Switch Team Workspace unit')).toBeUndefined();
    expect(guard()[0]).toBe(false);
    change('Task title', '  Prepare stock  ');
    await tap('Create Task');
    expect(createRequest).toHaveBeenCalledWith(expect.objectContaining({ title: 'Prepare stock', assignee_user_id: null, checklist: [] }));
    expect(mockNavigation.replace).toHaveBeenCalledWith('TeamTaskDetail', { id: 12 });
    expect(guard()[0]).toBe(false);
  });

  it('keeps checklist input instances through typing/removal and never sends client IDs', async () => {
    render(<TeamTaskFormScreen />);
    change('Task title', 'Checklist test');
    addItem('First'); addItem('Second');
    const first = field('Checklist item 1');
    const second = field('Checklist item 2');
    change('Checklist item 1', 'First edited');
    expect(field('Checklist item 1')).toBe(first);
    press('Remove checklist item 1');
    expect(field('Checklist item 1')).toBe(second);
    expect(field('Checklist item 1').props.value).toBe('Second');
    press('Complete checklist item 1');
    await tap('Create Task');
    expect(createRequest.mock.calls[0][0].checklist).toEqual([{ text: 'Second', done: true }]);
    expect(JSON.stringify(createRequest.mock.calls[0][0])).not.toContain('clientId');
  });

  it('treats unadded checklist text as dirty, blocks accidental omission, and really resets it on discard', async () => {
    render(<TeamTaskFormScreen />);
    change('New checklist item', 'Not added yet');
    expect(guard()[0]).toBe(true);
    change('Task title', 'Draft');
    await tap('Create Task');
    expect(createRequest).not.toHaveBeenCalled();
    expect(hasText('Add or clear the new checklist item before saving.')).toBe(true);
    headerBack();
    expect(mockNavigation.goBack).not.toHaveBeenCalled();
    confirmDiscard();
    expect(field('New checklist item').props.value).toBe('');
    expect(field('Task title').props.value).toBe('');
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
    expect(guard()[0]).toBe(false);
  });

  it('does not discard a dirty draft merely to open the unit picker', () => {
    mockWorkspace.units.push({ unit: 'guidance', label: 'Guidance' });
    render(<TeamTaskFormScreen />);
    change('Task title', 'Keep me');
    press('Switch Team Workspace unit');
    expect(mockWorkspace.openUnitPicker).toHaveBeenCalledTimes(1);
    expect(mockWorkspace.requestDiscard).not.toHaveBeenCalled();
    expect(field('Task title').props.value).toBe('Keep me');
  });

  it('keeps the original clean snapshot and edit version when fresh data arrives', async () => {
    mockRoute.params = { id: 12 };
    render(<TeamTaskFormScreen />);
    expect(guard()[0]).toBe(false);
    detailQuery = { ...detailQuery, data: { data: task({ title: 'Changed elsewhere', version: 9 }) } };
    rerender();
    expect(guard()[0]).toBe(false);
    expect(field('Task title').props.value).toBe('Check supplies');
    change('Task title', 'My original edit');
    await tap('Save Changes');
    expect(updateRequest).toHaveBeenCalledWith(expect.objectContaining({ title: 'My original edit', version: 4 }));
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
  });

  it('loads an edit snapshot once after the initial request finishes', () => {
    mockRoute.params = { id: 12 };
    detailQuery = { ...detailQuery, data: undefined, isLoading: true } as typeof detailQuery;
    render(<TeamTaskFormScreen />);
    expect(field('Task title')).toBeUndefined();
    detailQuery = queryResult({ data: task() }) as typeof detailQuery;
    rerender();
    expect(field('Task title').props.value).toBe('Check supplies');
    expect(guard()[0]).toBe(false);
  });

  it('locks stale handlers, all controls, header back, and removal in the same tick as submit', async () => {
    let resolve!: (value: unknown) => void;
    createRequest.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    render(<TeamTaskFormScreen />);
    change('Task title', 'Original'); addItem('Original item');
    const submit = action('Create Task').props.onPress;
    const edit = field('Task title').props.onChangeText;
    const toggle = button('Complete checklist item 1').props.onPress;
    const remove = button('Remove checklist item 1').props.onPress;
    const member = button('Assign to Blair').props.onPress;
    const status = button('Status: Done').props.onPress;
    const back = renderer!.root.findByType(PageHeader).props.onBack;
    act(() => { submit(); submit(); edit('Changed'); toggle(); remove(); member(); status(); back(); });
    expect(createRequest).toHaveBeenCalledTimes(1);
    expect(field('Task title').props.value).toBe('Original');
    expect(field('Checklist item 1').props.value).toBe('Original item');
    expect(field('Task title').props.editable).toBe(false);
    expect(button('Assign to Blair').props.disabled).toBe(true);
    expect(button('Complete checklist item 1').props.accessibilityState).toEqual({ checked: false, disabled: true });
    expect(renderer!.root.findByType(PageHeader).props.backDisabled).toBe(true);
    expect(renderer!.root.findByType(WorkspaceHeaderActions).props.disabled).toBe(true);
    expect(action('Cancel').props.disabled).toBe(true);
    expect(mockWorkspace.requestDiscard).not.toHaveBeenCalled();
    expect(mockSubmissions.size).toBe(1);
    act(() => guard()[1]({ data: { action: { type: 'GO_BACK' } } }));
    expect(mockNavigation.dispatch).not.toHaveBeenCalled();
    await act(async () => resolve({ data: task() }));
    await act(async () => submit());
    expect(createRequest).toHaveBeenCalledTimes(1);
    expect(guard()[0]).toBe(false);
    expect(mockNavigation.replace).toHaveBeenCalledTimes(1);
  });

  it('freezes an ambiguous create and explicitly retries the identical payload and nonce', async () => {
    createRequest.mockRejectedValueOnce(new NetworkError());
    render(<TeamTaskFormScreen />);
    change('Task title', 'Exactly this'); addItem('The checklist');
    const submit = action('Create Task').props.onPress;
    await act(async () => submit());
    const original = createRequest.mock.calls[0][0];
    expect(action('Retry original request')).toBeDefined();
    expect(field('Task title').props.editable).toBe(false);
    change('Task title', 'Should not replace pending payload');
    await act(async () => { submit(); await jest.advanceTimersByTimeAsync(60_000); });
    expect(createRequest).toHaveBeenCalledTimes(1);
    expect(field('Task title').props.value).toBe('Exactly this');
    expect([...mockDrafts.values()][0].warning).toContain('may already have reached');
    await tap('Retry original request');
    expect(createRequest).toHaveBeenCalledTimes(2);
    expect(createRequest.mock.calls[1][0]).toBe(original);
    expect(secureClientNonce).toHaveBeenCalledTimes(1);
  });

  it('retries an uncertain update with the original version and forces review on a conflict', async () => {
    mockRoute.params = { id: 12 };
    updateRequest.mockRejectedValueOnce(new ApiError(503, { code: 'unavailable', message: 'Unavailable' }));
    updateRequest.mockRejectedValueOnce(new ApiError(409, { code: 'stale_version', message: 'Changed' }));
    render(<TeamTaskFormScreen />);
    change('Task title', 'Original edit');
    await tap('Save Changes');
    const original = updateRequest.mock.calls[0][0];
    detailQuery = { ...detailQuery, data: { data: task({ version: 8 }) } };
    rerender();
    await tap('Retry original request');
    expect(updateRequest.mock.calls[1][0]).toBe(original);
    expect(original.version).toBe(4);
    expect(action('Review latest task')).toBeDefined();
    expect(action('Save Changes')).toBeUndefined();
    await tap('Review latest task');
    expect(mockNavigation.goBack).not.toHaveBeenCalled();
    confirmDiscard();
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
  });

  it('preserves a selected member and metadata through paged searches and retryable failures', async () => {
    render(<TeamTaskFormScreen />);
    press('Assign to Blair');
    expect(hasText('clinic admin')).toBe(true);
    change('Search members', '  Alex  ');
    await act(async () => { await jest.advanceTimersByTimeAsync(250); });
    expect(hooks.useTeamMemberSearch).toHaveBeenLastCalledWith('clinic', 'Alex', true);
    membersQuery = { ...membersQuery, data: { pages: [{ data: [{ id: 9, name: 'Alex', role: 'clinic_staff' }], meta: { unit: 'clinic', total: 70, page: 1, pages: 2 } }], pageParams: [1] }, hasNextPage: true } as typeof membersQuery;
    rerender();
    expect(button('Assign to Blair').props.accessibilityState.checked).toBe(true);
    await tap('Load more members');
    expect(fetchNextPage).toHaveBeenCalledTimes(1);
    membersQuery = { ...membersQuery, isError: true, isFetchNextPageError: true } as typeof membersQuery;
    rerender();
    await tap('Retry members');
    expect(fetchNextPage).toHaveBeenCalledTimes(2);
    expect(button('Assign to Blair').props.accessibilityState.checked).toBe(true);
    change('Task title', 'Assigned task');
    await tap('Create Task');
    expect(createRequest.mock.calls[0][0].assignee_user_id).toBe(10);
  });

  it('uses an optional Manila native date picker, handles dismissal, and clears dates', () => {
    render(<TeamTaskFormScreen />);
    press('Choose due date');
    let picker = renderer!.root.findByType(DateTimePicker);
    expect(picker.props.timeZoneName).toBe('Asia/Manila');
    expect(picker.props.value.toISOString()).toBe('2026-11-08T04:00:00.000Z');
    act(() => picker.props.onChange({ type: 'dismissed' }));
    expect(guard()[0]).toBe(false);
    press('Choose due date');
    picker = renderer!.root.findByType(DateTimePicker);
    act(() => picker.props.onChange({ type: 'set' }, new Date('2026-11-08T18:00:00Z')));
    expect(button('Clear due date')).toBeDefined();
    expect(guard()[0]).toBe(true);
    press('Clear due date');
    expect(hasText('No due date')).toBe(true);
    expect(guard()[0]).toBe(false);
  });

  it('upgrades the initial selected assignee with available member metadata and retains it on later searches', () => {
    mockRoute.params = { id: 12 };
    render(<TeamTaskFormScreen />);
    expect(hasText('clinic staff')).toBe(true);
    membersQuery = { ...membersQuery, data: { pages: [{ data: [], meta: { unit: 'clinic', total: 0 } }], pageParams: [1] } } as typeof membersQuery;
    rerender();
    expect(button('Assign to Alex').props.accessibilityState.checked).toBe(true);
    expect(hasText('clinic staff')).toBe(true);
  });

  it('forces review for a definitive missing-task update without treating it as a retryable send', async () => {
    mockRoute.params = { id: 12 };
    updateRequest.mockRejectedValueOnce(new ApiError(404, { code: 'not_found', message: 'Deleted' }));
    render(<TeamTaskFormScreen />);
    change('Task title', 'Kept draft');
    await tap('Save Changes');
    expect(action('Review latest task')).toBeDefined();
    expect(action('Retry original request')).toBeUndefined();
    expect(field('Task title').props.editable).toBe(false);
    await tap('Review latest task');
    expect(mockNavigation.goBack).not.toHaveBeenCalled();
    confirmDiscard();
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
  });

  it('rejects stale save and editing handlers after live can_edit revocation', async () => {
    mockRoute.params = { id: 12 };
    render(<TeamTaskFormScreen />);
    const save = action('Save Changes').props.onPress;
    const edit = field('Task title').props.onChangeText;
    detailQuery = { ...detailQuery, data: { data: task({ can_edit: false }) } };
    rerender();
    await act(async () => { edit('Changed'); save(); });
    expect(updateRequest).not.toHaveBeenCalled();
    expect(field('Task title').props.value).toBe('Check supplies');
    expect(field('Task title').props.editable).toBe(false);
  });

  it.each(['unit', 'identity', 'unmount'] as const)('ignores late create success after %s and cannot replay old submit handlers', async scopeChange => {
    let resolve!: (value: unknown) => void;
    createRequest.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    render(<TeamTaskFormScreen />);
    change('Task title', 'Old draft');
    const submit = action('Create Task').props.onPress;
    act(() => submit());
    if (scopeChange === 'unmount') {
      act(() => renderer!.unmount()); renderer = undefined;
    } else {
      if (scopeChange === 'unit') { mockWorkspace.activeUnit = { unit: 'guidance', label: 'Guidance' }; }
      else { mockIdentity = { id: 6, tenant_id: 7 }; }
      rerender();
      expect(field('Task title')).toBeUndefined();
    }
    await act(async () => resolve({ data: task() }));
    await act(async () => submit());
    expect(createRequest).toHaveBeenCalledTimes(1);
    expect(mockNavigation.replace).not.toHaveBeenCalled();
    expect(mockNavigation.goBack).not.toHaveBeenCalled();
  });

  it('does not allow a non-editor to save even through the rendered action handler', async () => {
    mockRoute.params = { id: 12 };
    detailQuery = { ...detailQuery, data: { data: task({ can_edit: false }) } };
    render(<TeamTaskFormScreen />);
    expect(action('Save Changes').props.disabled).toBe(true);
    await tap('Save Changes');
    expect(updateRequest).not.toHaveBeenCalled();
    expect(field('Task title').props.editable).toBe(false);
  });
});

describe('Team Task detail rendered handlers', () => {
  beforeEach(() => { mockRoute = { key: 'detail-route', name: 'TeamTaskDetail', params: { id: 12 } }; });

  it('reveals the comment and its inline action on keyboard show and resize while preserving the unsent input', async () => {
    jest.replaceProperty(Platform, 'OS', 'android');
    const keyboard = keyboardViewport();
    render(<TeamTaskDetailScreen />);
    const { input, bottom } = keyboard.nativeInput('Comment', 840, 92);
    const fieldComponent = renderer!.root.findAllByType(TextField).find(node => node.props.accessibilityLabel === 'Comment')!;
    expect(fieldComponent.props.keyboardRevealOffset).toBe(72);
    change('Comment', 'Local UI check - unsent');
    act(() => input.props.onFocus({ nativeEvent: {} }));
    keyboard.show();
    await act(async () => jest.advanceTimersByTimeAsync(500));
    expect(keyboard.measureViewport).toHaveBeenCalled();
    expect(keyboard.scrollTo).toHaveBeenCalled();
    expect(bottom() + 72).toBeLessThanOrEqual(keyboard.keyboardTop);
    expect(field('Comment')).toBe(input);
    expect(field('Comment').props.value).toBe('Local UI check - unsent');
    expect(guard()[0]).toBe(true);
    expect(commentRequest).not.toHaveBeenCalled();
    expect(statusRequest).not.toHaveBeenCalled();
    expect(updateRequest).not.toHaveBeenCalled();

    const calls = keyboard.scrollTo.mock.calls.length;
    keyboard.resize(300);
    await act(async () => jest.advanceTimersByTimeAsync(500));
    expect(keyboard.scrollTo.mock.calls.length).toBeGreaterThan(calls);
    expect(bottom() + 72).toBeLessThanOrEqual(410);
    keyboard.hide();
    act(() => input.props.onBlur({ nativeEvent: {} }));
    expect(field('Comment').props.value).toBe('Local UI check - unsent');
    keyboard.resize(820);
    keyboard.resetScroll();
    act(() => input.props.onFocus({ nativeEvent: {} }));
    keyboard.show();
    await act(async () => jest.advanceTimersByTimeAsync(500));
    expect(field('Comment')).toBe(input);
    expect(bottom() + 72).toBeLessThanOrEqual(keyboard.keyboardTop);
    expect(commentRequest).not.toHaveBeenCalled();
    expect(mockNavigation.navigate).not.toHaveBeenCalled();
    expect(mockNavigation.goBack).not.toHaveBeenCalled();

    act(() => renderer!.unmount());
    renderer = undefined;
    for (const subscription of [...keyboard.listeners.values()].flat()) { expect(subscription.remove).toHaveBeenCalled(); }
  });

  it('does not move a pending comment field or replay a send when keyboard focus callbacks fire', async () => {
    jest.replaceProperty(Platform, 'OS', 'android');
    const keyboard = keyboardViewport();
    let resolve!: (value: unknown) => void;
    commentRequest.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    render(<TeamTaskDetailScreen />);
    const { input } = keyboard.nativeInput('Comment', 840, 92);
    change('Comment', 'Pending comment');
    const staleFocus = input.props.onFocus;
    const staleEdit = input.props.onChangeText;
    act(() => action('Add comment').props.onPress());
    keyboard.show();
    act(() => { staleFocus({ nativeEvent: {} }); staleEdit('Changed under keyboard'); });
    await act(async () => jest.advanceTimersByTimeAsync(500));
    expect(field('Comment')).toBe(input);
    expect(field('Comment').props.editable).toBe(false);
    expect(field('Comment').props.value).toBe('Pending comment');
    expect(commentRequest).toHaveBeenCalledTimes(1);
    expect(guard()[0]).toBe(true);
    await act(async () => resolve({ data: task() }));
    expect(field('Comment').props.value).toBe('');
  });

  it('gates status and checklist by can_edit without preventing a member comment', async () => {
    detailQuery = { ...detailQuery, data: { data: task({ can_edit: false }) } };
    render(<TeamTaskDetailScreen />);
    expect(button('Status: Done').props.disabled).toBe(true);
    expect(button('Count boxes').props.disabled).toBe(true);
    press('Status: Done'); press('Count boxes');
    expect(statusRequest).not.toHaveBeenCalled(); expect(updateRequest).not.toHaveBeenCalled();
    expect(button('Edit task')).toBeUndefined();
    change('Comment', 'Team update');
    await tap('Add comment');
    expect(commentRequest).toHaveBeenCalledWith(expect.objectContaining({ body: 'Team update' }));
    expect(field('Comment').props.value).toBe('');
    expect(guard()[0]).toBe(false);
    expect(renderer!.root.findByType(Screen).props.keyboardAware).toBe(true);
    expect(action('Add comment').props.icon).toBeDefined();
  });

  it('quick-toggles the checklist with the full versioned editable payload and no IDs', async () => {
    render(<TeamTaskDetailScreen />);
    await act(async () => button('Count boxes').props.onPress());
    expect(updateRequest).toHaveBeenCalledWith({ title: 'Check supplies', description: 'Count stock', status: 'todo', priority: 'normal', assignee_user_id: 9, due_date: '2026-11-08', checklist: [{ text: 'Count boxes', done: true }], version: 4 });
  });

  it('locks other actions, comment editing, edit/header navigation and native removal for a status send', async () => {
    let resolve!: (value: unknown) => void;
    statusRequest.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    render(<TeamTaskDetailScreen />);
    change('Comment', 'Preserved draft');
    const changeStatus = button('Status: Done').props.onPress;
    const checklist = button('Count boxes').props.onPress;
    const editTask = button('Edit task').props.onPress;
    const editComment = field('Comment').props.onChangeText;
    const submitComment = action('Add comment').props.onPress;
    const back = renderer!.root.findByType(PageHeader).props.onBack;
    act(() => { changeStatus(); changeStatus(); checklist(); editTask(); editComment('Changed'); submitComment(); back(); });
    expect(statusRequest).toHaveBeenCalledTimes(1);
    expect(statusRequest).toHaveBeenCalledWith({ status: 'done', version: 4 });
    expect(updateRequest).not.toHaveBeenCalled(); expect(commentRequest).not.toHaveBeenCalled();
    expect(field('Comment').props.value).toBe('Preserved draft');
    expect(field('Comment').props.editable).toBe(false);
    expect(renderer!.root.findByType(PageHeader).props.backDisabled).toBe(true);
    expect(button('Count boxes').props.accessibilityState.disabled).toBe(true);
    expect(guard()[0]).toBe(true);
    act(() => guard()[1]({ data: { action: { type: 'GO_BACK' } } }));
    expect(mockNavigation.dispatch).not.toHaveBeenCalled(); expect(mockNavigation.navigate).not.toHaveBeenCalled();
    await act(async () => resolve({ data: task({ status: 'done', version: 5 }) }));
    expect(field('Comment').props.editable).toBe(true);
    expect(guard()[0]).toBe(true);
  });

  it('keeps a record-action 403 inline without workspace/auth resets and supports reviewing the latest task', async () => {
    statusRequest.mockRejectedValueOnce(new ApiError(403, { code: 'forbidden', message: 'You cannot edit this task.' }));
    render(<TeamTaskDetailScreen />);
    await act(async () => button('Status: Done').props.onPress());
    expect(mockReconcile).not.toHaveBeenCalled();
    expect(mockNavigation.goBack).not.toHaveBeenCalled();
    expect(hasText('This action is not permitted for this task. Review the latest task details or check your access.')).toBe(true);
    expect(button('Status: Done').props.disabled).toBe(true);
    await tap('Review latest');
    expect(refetch).toHaveBeenCalledTimes(1);
    expect(button('Status: Done').props.disabled).toBe(false);
  });

  it('reconciles an explicit entitlement denial without treating it as a generic record permission', async () => {
    commentRequest.mockRejectedValueOnce(new ApiError(403, { code: 'team_membership_required', message: 'No access' }));
    render(<TeamTaskDetailScreen />);
    change('Comment', 'Comment'); await tap('Add comment');
    expect(mockReconcile).toHaveBeenCalledTimes(1);
    expect(mockNavigation.goBack).not.toHaveBeenCalled();
  });

  it('explicitly retries an ambiguous comment with the same nonce and rejects stale edits/submits', async () => {
    commentRequest.mockRejectedValueOnce(new NetworkError());
    render(<TeamTaskDetailScreen />);
    change('Comment', '  Original comment  ');
    const submit = action('Add comment').props.onPress;
    const edit = field('Comment').props.onChangeText;
    await act(async () => { submit(); submit(); edit('Changed during send'); });
    expect(commentRequest).toHaveBeenCalledTimes(1);
    const original = commentRequest.mock.calls[0][0];
    expect(original.body).toBe('Original comment');
    change('Comment', 'Changed after uncertain send');
    expect(field('Comment').props.value).toBe('  Original comment  ');
    expect(action('Add comment').props.disabled).toBe(true);
    await act(async () => { submit(); await jest.advanceTimersByTimeAsync(60_000); });
    expect(commentRequest).toHaveBeenCalledTimes(1);
    await tap('Retry original comment');
    expect(commentRequest.mock.calls[1][0]).toBe(original);
    expect(secureClientNonce).toHaveBeenCalledTimes(1);
    expect(field('Comment').props.value).toBe('');
    await act(async () => submit());
    expect(commentRequest).toHaveBeenCalledTimes(2);
    expect(guard()[0]).toBe(false);
  });

  it('really clears a discarded comment and retry ref before a newly authored send', async () => {
    commentRequest.mockRejectedValueOnce(new NetworkError());
    render(<TeamTaskDetailScreen />);
    change('Comment', 'First'); await tap('Add comment');
    const originalNonce = commentRequest.mock.calls[0][0].client_nonce;
    await tap('Discard comment draft');
    expect(field('Comment').props.value).toBe('First');
    confirmDiscard();
    expect(field('Comment').props.value).toBe('');
    change('Comment', 'New'); await tap('Add comment');
    expect(commentRequest.mock.calls[1][0].body).toBe('New');
    expect(commentRequest.mock.calls[1][0].client_nonce).not.toBe(originalNonce);
  });

  it('keeps a quick-toggle retry tied to its original version even after a refresh', async () => {
    updateRequest.mockRejectedValueOnce(new NetworkError());
    render(<TeamTaskDetailScreen />);
    await act(async () => button('Count boxes').props.onPress());
    const original = updateRequest.mock.calls[0][0];
    detailQuery = { ...detailQuery, data: { data: task({ version: 8, checklist: [{ text: 'Edited elsewhere', done: true }] }) } };
    rerender();
    await tap('Retry checklist update');
    expect(updateRequest.mock.calls[1][0]).toBe(original);
    expect(original.version).toBe(4);
    expect(original.checklist).toEqual([{ text: 'Count boxes', done: true }]);
  });

  it('re-arms the shared draft guard for a second comment after the first succeeds', async () => {
    render(<TeamTaskDetailScreen />);
    change('Comment', 'First comment');
    await tap('Add comment');
    expect(guard()[0]).toBe(false);
    expect(mockDrafts.size).toBe(0);
    change('Comment', 'Second unsent comment');
    expect(guard()[0]).toBe(true);
    expect(mockDrafts.size).toBe(1);
    headerBack();
    expect(mockNavigation.goBack).not.toHaveBeenCalled();
    confirmDiscard();
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
    expect(field('Comment').props.value).toBe('');
  });

  it('blocks stale editor controls after a live can_edit revocation without losing the comment draft', async () => {
    render(<TeamTaskDetailScreen />);
    change('Comment', 'Kept comment');
    const status = button('Status: Done').props.onPress;
    const checklist = button('Count boxes').props.onPress;
    const edit = button('Edit task').props.onPress;
    detailQuery = { ...detailQuery, data: { data: task({ can_edit: false }) } };
    rerender();
    act(() => { status(); checklist(); edit(); });
    expect(statusRequest).not.toHaveBeenCalled();
    expect(updateRequest).not.toHaveBeenCalled();
    expect(mockNavigation.navigate).not.toHaveBeenCalled();
    expect(field('Comment').props.value).toBe('Kept comment');
    expect(field('Comment').props.editable).toBe(true);
    await tap('Add comment');
    expect(commentRequest).toHaveBeenCalledTimes(1);
  });

  it('keeps idempotency conflicts locked until the original comment is explicitly discarded', async () => {
    commentRequest.mockRejectedValueOnce(new ApiError(409, { code: 'idempotency_conflict', message: 'Different request' }));
    render(<TeamTaskDetailScreen />);
    change('Comment', 'Original');
    await tap('Add comment');
    expect(action('Retry original comment')).toBeUndefined();
    expect(action('Add comment').props.disabled).toBe(true);
    change('Comment', 'Changed');
    expect(field('Comment').props.value).toBe('Original');
    await tap('Review latest');
    expect(action('Add comment').props.disabled).toBe(true);
    await tap('Discard comment draft');
    confirmDiscard();
    expect(field('Comment').props.value).toBe('');
    change('Comment', 'New comment');
    await tap('Add comment');
    expect(commentRequest).toHaveBeenCalledTimes(2);
    expect(commentRequest.mock.calls[1][0].client_nonce).not.toBe(commentRequest.mock.calls[0][0].client_nonce);
  });

  it('keeps refresh and writes from racing and does not clear review state if the refresh fails', async () => {
    statusRequest.mockRejectedValueOnce(new ApiError(409, { code: 'stale_version', message: 'Changed' }));
    render(<TeamTaskDetailScreen />);
    await act(async () => button('Status: Done').props.onPress());
    let resolve!: (value: unknown) => void;
    refetch.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    const review = action('Review latest').props.onPress;
    const staleStatus = button('Status: Done').props.onPress;
    act(() => { review(); staleStatus(); });
    expect(statusRequest).toHaveBeenCalledTimes(1);
    await act(async () => resolve({ isError: true }));
    expect(button('Status: Done').props.disabled).toBe(true);
    await tap('Review latest');
    expect(button('Status: Done').props.disabled).toBe(false);
  });

  it.each(['unit', 'identity', 'unmount'] as const)('ignores a late comment failure after %s and refuses old handlers', async scopeChange => {
    let reject!: (value: unknown) => void;
    commentRequest.mockImplementationOnce(() => new Promise((_done, fail) => { reject = fail; }));
    render(<TeamTaskDetailScreen />);
    change('Comment', 'Old comment');
    const submit = action('Add comment').props.onPress;
    const back = renderer!.root.findByType(PageHeader).props.onBack;
    act(() => submit());
    if (scopeChange === 'unmount') {
      act(() => renderer!.unmount()); renderer = undefined;
    } else {
      if (scopeChange === 'unit') { mockWorkspace.activeUnit = { unit: 'guidance', label: 'Guidance' }; }
      else { mockIdentity = { id: 6, tenant_id: 7 }; }
      rerender();
      expect(hasText('Check supplies')).toBe(false);
    }
    await act(async () => reject(new ApiError(403, { code: 'team_membership_required', message: 'Old account denied' })));
    act(() => { submit(); back(); });
    expect(commentRequest).toHaveBeenCalledTimes(1);
    expect(mockReconcile).not.toHaveBeenCalled();
    expect(mockNavigation.goBack).not.toHaveBeenCalled();
    expect(mockWorkspace.requestDiscard).not.toHaveBeenCalled();
  });

  it('hides cached protected details on a denied read but still offers an explicit retry', async () => {
    detailQuery = { ...detailQuery, isError: true, error: new ApiError(403, { code: 'forbidden', message: 'No access' }) } as typeof detailQuery;
    render(<TeamTaskDetailScreen />);
    expect(hasText('Check supplies')).toBe(false);
    expect(hasText('Access changed')).toBe(true);
    await tap('Try again');
    expect(refetch).toHaveBeenCalledTimes(1);
  });
});

describe('Team Task list rendered handlers', () => {
  it('wires search, clear/reset, pagination, card navigation and unassigned create entry', async () => {
    listQuery = { ...listQuery, data: { ...listQuery.data!, meta: { ...listQuery.data!.meta, pages: 3, total: 120 } } };
    render(<TeamTaskListScreen scope="mine" />);
    expect(renderer!.root.findByType(WorkspaceHeaderActions).props.root).toBe(true);
    change('Search tasks', '  stock  '); press('Search');
    expect(filters()).toMatchObject({ scope: 'mine', q: 'stock', page: 1 });
    press('Open task filters');
    press('Status filter: Done'); press('Priority filter: High');
    expect(filters()).toMatchObject({ status: 'done', priority: 'high' });
    press('Done filtering tasks');
    await tap('Next'); expect(filters().page).toBe(2);
    press('Clear task search'); expect(filters().q).toBe('');
    await tap('Reset filters'); expect(filters()).toEqual({ scope: 'mine', q: '', status: 'all', priority: 'all', page: 1 });
    press('Open task Check supplies'); expect(mockNavigation.navigate).toHaveBeenCalledWith('TeamTaskDetail', { id: 12 });
    press('Create task'); expect(mockNavigation.navigate).toHaveBeenLastCalledWith('TeamTaskForm', undefined);
  });

  it('keeps unchanged task-card rendering isolated from search draft typing', () => {
    render(<TeamTaskListScreen scope="team" />);
    const list = renderer!.root.findByType(SectionList);
    const renderItem = list.props.renderItem;
    const card = button('Open task Check supplies');
    const props = card.props;
    const title = card.findAllByType(Text).find(node => node.props.children === 'Check supplies')!;
    change('Search tasks', 'draft');
    change('Search tasks', 'draft continues');
    expect(renderer!.root.findByType(SectionList).props.renderItem).toBe(renderItem);
    expect(button('Open task Check supplies').props).toBe(props);
    expect(button('Open task Check supplies').findAllByType(Text).find(node => node.props.children === 'Check supplies')).toBe(title);
    expect(filters().q).toBe('');
    listQuery = { ...listQuery, data: { ...listQuery.data!, data: [task({ title: 'Updated task' })] } };
    rerender();
    expect(button('Open task Updated task')).toBeDefined();
  });

  it('uses server workflow counts across pages instead of counting the visible cards', () => {
    listQuery = { ...listQuery, data: { data: [task()], meta: { ...listQuery.data!.meta,
      counts: { todo: 40, in_progress: 27, blocked: 3, done: 50, total: 120 }, total: 120, pages: 3 } } };
    render(<TeamTaskListScreen scope="team" />);
    const overview = renderer!.root.findByProps({ testID: 'task-workflow-overview' });
    expect(overview.findAllByProps({ accessibilityLabel: 'Open tasks: 70' }).length).toBeGreaterThan(0);
    expect(overview.findAllByProps({ accessibilityLabel: 'Blocked tasks: 3' }).length).toBeGreaterThan(0);
    expect(overview.findAllByProps({ accessibilityLabel: 'Done tasks: 50' }).length).toBeGreaterThan(0);
    expect(renderer!.root.findAll(node => node.props.accessibilityRole === 'radio')).toHaveLength(0);
    expect(hasText('120 tasks')).toBe(true);
    expect(renderer!.root.findByType(SectionList).props.sections[0].data).toHaveLength(1);

    press('Open task filters'); press('Status filter: Done'); press('Done filtering tasks');
    listQuery = { ...listQuery, data: { ...listQuery.data!, data: [task({ status: 'done' })], meta: { ...listQuery.data!.meta, total: 50 } } };
    rerender();
    expect(hasText('50 tasks found')).toBe(true);
    expect(hasText('All statuses')).toBe(true);
    expect(overview.findAllByProps({ accessibilityLabel: 'Open tasks: 70' }).length).toBeGreaterThan(0);
    expect(button('Open task Check supplies').props.accessibilityHint).toContain('Done');
  });

  it('keeps draft search separate while the filter sheet applies selections and retains them on dismissal', () => {
    const dismissKeyboard = jest.spyOn(Keyboard, 'dismiss');
    render(<TeamTaskListScreen scope="team" />);
    change('Search tasks', '  not applied yet  ');
    expect(filters().q).toBe('');
    press('Open task filters');
    expect(dismissKeyboard).toHaveBeenCalledTimes(1);
    expect(renderer!.root.findByType(BottomSheet).props.visible).toBe(true);
    const radios = renderer!.root.findAll(node => node.props.accessibilityRole === 'radio' && typeof node.props.onPress === 'function');
    expect(radios).toHaveLength(9);
    expect(filterChoice('Status filter: All').props.accessibilityState.checked).toBe(true);
    press('Status filter: In progress'); press('Priority filter: High');
    expect(filters()).toMatchObject({ q: '', status: 'in_progress', priority: 'high', page: 1 });
    expect(field('Search tasks').props.value).toBe('  not applied yet  ');
    expect(filterChoice('Priority filter: High').props.accessibilityState.checked).toBe(true);
    const staleChoice = button('Priority filter: Low').props.onPress;
    act(() => renderer!.root.findByType(Modal).props.onRequestClose());
    expect(renderer!.root.findByType(BottomSheet).props.visible).toBe(false);
    expect(filters()).toMatchObject({ status: 'in_progress', priority: 'high', q: '' });
    act(() => staleChoice());
    expect(filters().priority).toBe('high');
    act(() => field('Search tasks').props.onSubmitEditing());
    expect(filters().q).toBe('not applied yet');
    press('Clear task search');
    expect(filters()).toMatchObject({ q: '', status: 'in_progress', priority: 'high' });
    finishSheetDismissal();
    press('Open task filters'); press('Reset filters in sheet');
    expect(filters()).toEqual({ scope: 'team', q: '', status: 'all', priority: 'all', page: 1 });
    expect(renderer!.root.findByType(BottomSheet).props.visible).toBe(true);
    expect(field('Search tasks').props.value).toBe('');
    press('Done filtering tasks');
  });

  it.each([lightTheme, darkTheme])('structures readable, theme-aware task cards in $mode mode without clipped titles or owners', theme => {
    mockTheme = theme;
    const title = 'Prepare the department handover and reconcile all outstanding supply requests';
    const owner = 'A long team member name that needs room to wrap';
    listQuery = { ...listQuery, data: { ...listQuery.data!, data: [
      task({ title, assignee: { id: 9, name: owner }, status: 'blocked', priority: 'high', due_date: '2026-10-30', checklist_progress: { done: 2, total: 5 } }),
      task({ id: 13, title: 'Unassigned follow-up', assignee: null, due_date: null, status: 'done', priority: 'low', checklist_progress: { done: 0, total: 0 }, can_edit: false }),
    ] } };
    render(<TeamTaskListScreen scope="team" />);
    const card = button(`Open task ${title}`);
    expect(StyleSheet.flatten(card.props.style({ pressed: false }))).toMatchObject({ padding: 16, borderRadius: 18, backgroundColor: theme.surface });
    expect(card.props.accessibilityHint).toBe(`Blocked, High priority, ${owner}, Due Friday, October 30, 2026, 2 of 5 checklist items complete`);
    const text = card.findAllByType(Text);
    const titleNode = text.find(node => node.props.children === title)!;
    const ownerNode = text.find(node => node.props.children === owner)!;
    expect(titleNode.props.numberOfLines).toBeUndefined();
    expect(ownerNode.props.numberOfLines).toBeUndefined();
    expect(StyleSheet.flatten(titleNode.props.style)).toMatchObject({ fontSize: 15, color: theme.ink900, flex: 1 });
    expect(hasText('Due Oct 30')).toBe(true);
    expect(text.some(node => React.Children.toArray(node.props.children).join('') === '2/5 complete')).toBe(true);
    const progress = card.findAllByType(View).find(node => StyleSheet.flatten(node.props.style)?.width === '40%')!;
    expect(StyleSheet.flatten(progress.props.style).height).toBe(3);
    const unassigned = button('Open task Unassigned follow-up');
    expect(unassigned.props.accessibilityHint).toBe('Done, Low priority, Unassigned, No due date');
    expect(unassigned.findAllByType(Text).some(node => node.props.children === 'No due date')).toBe(true);
    expect(unassigned.findAllByType(View).some(node => StyleSheet.flatten(node.props.style)?.height === 3)).toBe(false);
    expect(renderer!.root.findAll(node => node.props.accessibilityRole === 'header' && node.props.accessibilityLabel === 'Blocked, 1 task on this page').length).toBeGreaterThan(0);
    press('Open task Unassigned follow-up');
    expect(mockNavigation.navigate).toHaveBeenCalledWith('TeamTaskDetail', { id: 13 });
    press('Open task filters');
    const choice = filterChoice('Status filter: Blocked');
    expect(choice.props.accessibilityRole).toBe('radio');
    expect(StyleSheet.flatten(choice.props.style({ pressed: false })).minHeight).toBeGreaterThanOrEqual(44);
  });

  it('makes empty results refreshable and separates pull feedback from background fetching', async () => {
    listQuery = { ...listQuery, data: { ...listQuery.data!, data: [] }, isFetching: true, isRefetching: true } as typeof listQuery;
    let resolve!: (value: unknown) => void;
    refetch.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    render(<TeamTaskListScreen scope="team" />);
    expect(hasText('No team tasks yet')).toBe(true);
    expect(renderer!.root.findByType(SectionList).props.refreshing).toBe(false);
    expect(renderer!.root.findAllByType(ActivityIndicator).length).toBeGreaterThan(0);
    const pull = renderer!.root.findByType(SectionList).props.onRefresh;
    act(() => { pull(); pull(); });
    expect(refetch).toHaveBeenCalledTimes(1);
    expect(renderer!.root.findByType(SectionList).props.refreshing).toBe(true);
    await act(async () => resolve({ isError: false }));
    expect(renderer!.root.findByType(SectionList).props.refreshing).toBe(false);
  });

  it('distinguishes empty filters, true empty results and request failures with retry', async () => {
    listQuery = { ...listQuery, data: { ...listQuery.data!, data: [] } };
    render(<TeamTaskListScreen scope="mine" />);
    expect(hasText('No tasks assigned to you')).toBe(true);
    press('Open task filters'); press('Status filter: Done'); press('Done filtering tasks');
    expect(hasText('No matching tasks')).toBe(true);
    await tap('Reset filters');
    expect(filters().status).toBe('all');
    listQuery = { ...listQuery, isError: true, error: new NetworkError() } as typeof listQuery;
    rerender();
    expect(hasText('No tasks assigned to you')).toBe(false);
    await tap('Retry'); expect(refetch).toHaveBeenCalledTimes(1);
    listQuery = { ...listQuery, data: { ...listQuery.data!, data: [task()] } };
    rerender();
    expect(hasText('Check supplies')).toBe(true);
    expect(action('Retry')).toBeDefined();
  });

  it('ignores late refresh completion and old list actions after the account changes', async () => {
    let resolve!: (value: unknown) => void;
    refetch.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    render(<TeamTaskListScreen scope="team" />);
    const refresh = renderer!.root.findByType(SectionList).props.onRefresh;
    const createTask = button('Create task').props.onPress;
    const openTask = button('Open task Check supplies').props.onPress;
    act(() => refresh());
    expect(renderer!.root.findByType(SectionList).props.refreshing).toBe(true);
    mockIdentity = { id: 6, tenant_id: 7 };
    rerender();
    expect(renderer!.root.findByType(SectionList).props.refreshing).toBe(false);
    act(() => { refresh(); createTask(); openTask(); });
    expect(refetch).toHaveBeenCalledTimes(1);
    expect(mockNavigation.navigate).not.toHaveBeenCalled();
    await act(async () => resolve({ isError: false }));
    expect(renderer!.root.findByType(SectionList).props.refreshing).toBe(false);
  });

  it('labels filtered and previous workflow totals and never fabricates counts while loading', () => {
    listQuery = { ...listQuery, data: undefined, isLoading: true, isFetching: true };
    render(<TeamTaskListScreen scope="team" />);
    expect(renderer!.root.findAllByProps({ accessibilityLabel: 'Open tasks: unavailable' }).length).toBeGreaterThan(0);
    expect(renderer!.root.findAllByProps({ accessibilityLabel: 'Loading tasks' }).length).toBeGreaterThan(0);
    listQuery = queryResult({ data: [task()], meta: { counts: { todo: 1, in_progress: 0, blocked: 0, done: 0, total: 1 }, total: 1, page: 1, pages: 1, limit: 100, scope: 'team' } }) as unknown as typeof listQuery;
    rerender();
    change('Search tasks', 'supplies'); press('Search');
    expect(hasText('Search / priority · All statuses')).toBe(true);
    listQuery = { ...listQuery, isPlaceholderData: true, isFetching: true };
    rerender();
    expect(hasText('Previous results')).toBe(true);
    expect(hasText('Showing the previous results until this page finishes loading.')).toBe(true);
  });

  it('hides protected cards, counts and creation controls after access is denied and retires filter callbacks', () => {
    render(<TeamTaskListScreen scope="team" />);
    press('Open task filters');
    const select = button('Priority filter: High').props.onPress;
    listQuery = { ...listQuery, isError: true, error: new ApiError(403, { code: 'forbidden', message: 'No access' }) };
    rerender();
    expect(hasText('Check supplies')).toBe(false);
    expect(button('Create task')).toBeUndefined();
    expect(renderer!.root.findAllByProps({ testID: 'task-workflow-overview' })).toHaveLength(0);
    expect(renderer!.root.findByType(BottomSheet).props.visible).toBe(false);
    expect(button('Open task filters').props.disabled).toBe(true);
    act(() => select());
    expect(filters().priority).toBe('all');
  });

  it('retires sheet callbacks across unit/account/scope changes and across separate presentations', () => {
    render(<TeamTaskListScreen scope="team" />);
    press('Open task filters');
    const oldChoice = button('Priority filter: High').props.onPress;
    const oldClose = renderer!.root.findByType(BottomSheet).props.onClose;
    press('Close task filters');
    finishSheetDismissal();
    press('Open task filters');
    act(() => { oldChoice(); oldClose(); });
    expect(filters().priority).toBe('all');
    expect(renderer!.root.findByType(BottomSheet).props.visible).toBe(true);
    const unitChoice = button('Status filter: Done').props.onPress;
    mockWorkspace.activeUnit = { unit: 'guidance', label: 'Guidance' };
    rerender();
    act(() => unitChoice());
    expect(filters()).toMatchObject({ status: 'all', priority: 'all' });
    expect(renderer!.root.findByType(BottomSheet).props.visible).toBe(false);
    finishSheetDismissal();
    press('Open task filters');
    const accountChoice = button('Priority filter: High').props.onPress;
    mockIdentity = { id: 6, tenant_id: 7 };
    rerender();
    act(() => accountChoice());
    expect(filters().priority).toBe('all');
    expect(renderer!.root.findByType(BottomSheet).props.visible).toBe(false);
    finishSheetDismissal();
    press('Open task filters');
    const scopeChoice = button('Status filter: Blocked').props.onPress;
    element = <TeamTaskListScreen scope="mine" />;
    rerender();
    act(() => scopeChoice());
    expect(filters()).toEqual({ scope: 'mine', q: '', status: 'all', priority: 'all', page: 1 });
    expect(renderer!.root.findByType(BottomSheet).props.visible).toBe(false);
  });

  it('offers unit selection instead of task controls when there is no active unit', () => {
    mockWorkspace.activeUnit = null;
    render(<TeamTaskListScreen scope="team" />);
    expect(hasText('Choose a Team Workspace unit')).toBe(true);
    expect(renderer!.root.findAllByType(SectionList)).toHaveLength(0);
    expect(button('Create task')).toBeUndefined();
    expect(button('Open task filters')).toBeUndefined();
    expect(renderer!.root.findAllByType(TextInput)).toHaveLength(0);
    act(() => action('Choose unit').props.onPress());
    expect(mockWorkspace.openUnitPicker).toHaveBeenCalledTimes(1);
  });

  it('allows members to create from either list without turning read-only cards into editing controls', () => {
    listQuery = { ...listQuery, data: { ...listQuery.data!, data: [task({ can_edit: false })] } };
    render(<TeamTaskListScreen scope="mine" />);
    expect(renderer!.root.findByType(PageHeader).props.title).toBe('My Tasks');
    press('Create task');
    expect(mockNavigation.navigate).toHaveBeenCalledWith('TeamTaskForm', undefined);
    expect(button('Edit task')).toBeUndefined();
    press('Open task Check supplies');
    expect(mockNavigation.navigate).toHaveBeenLastCalledWith('TeamTaskDetail', { id: 12 });
  });

  it('resets local filters when moving between list scopes', () => {
    render(<TeamTaskListScreen scope="team" />);
    change('Search tasks', 'clinic'); press('Search'); press('Open task filters'); press('Priority filter: High');
    element = <TeamTaskListScreen scope="mine" />;
    rerender();
    expect(filters()).toEqual({ scope: 'mine', q: '', status: 'all', priority: 'all', page: 1 });
    expect(field('Search tasks').props.value).toBe('');
  });
});
