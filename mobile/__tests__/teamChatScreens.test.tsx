import React from 'react';
import { AppState, FlatList, KeyboardAvoidingView, Modal, Platform, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { act, create, type ReactTestInstance, type ReactTestRenderer } from 'react-test-renderer';
import { usePreventRemove } from '@react-navigation/native';
import { QueryClient, QueryClientProvider, focusManager, onlineManager } from '@tanstack/react-query';
import { ApiError, NetworkError } from '../src/api/client';
import * as api from '../src/api/endpoints/teamChat';
import type { TeamUnit, User, WorkUnitEntitlement } from '../src/api/types';
import { BottomSheet } from '../src/components/BottomSheet';
import { OverlayProvider } from '../src/components/OverlayProvider';
import { Screen } from '../src/components/Screen';
import { secureClientNonce } from '../src/lib/secureNonce';
import { useKeyboardVisible } from '../src/lib/useKeyboardVisible';
import { navigateToWorkspaceIntent, navigationRef } from '../src/navigation/navigationRef';
import { WorkspaceHeaderActions } from '../src/features/workspace/WorkspaceHeaderActions';
import { TeamChatMessageBubble } from '../src/features/team-chat/components/TeamChatMessageBubble';
import { TeamChatTaskPicker } from '../src/features/team-chat/components/TeamChatTaskPicker';
import { teamChatKeys } from '../src/features/team-chat/hooks';
import type { TeamChatRow } from '../src/features/team-chat/model';
import { TeamChatScreen } from '../src/features/team-chat/screens/TeamChatScreen';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn(), clearRefreshToken: jest.fn() }));
jest.mock('../src/lib/secureNonce', () => ({ secureClientNonce: jest.fn() }));
jest.mock('../src/api/endpoints/teamChat', () => ({ history: jest.fn(), markRead: jest.fn(), tasks: jest.fn(), members: jest.fn(), send: jest.fn() }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }) }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: () => true }));
jest.mock('../src/lib/useKeyboardVisible', () => ({ useKeyboardVisible: jest.fn(() => false) }));
jest.mock('react-native/Libraries/AppState/AppState', () => ({ __esModule: true, default: { currentState: 'active', addEventListener: jest.fn(() => ({ remove: jest.fn() })) } }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View, useSafeAreaInsets: () => ({ top: 24, right: 0, bottom: 20, left: 0 }) }));
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));
jest.mock('@react-native-community/netinfo', () => ({ useNetInfo: () => ({ isConnected: mockConnected, isInternetReachable: mockConnected }) }));
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => mockNavigation, useRoute: () => ({ key: 'chat-route', name: 'TeamChat' }),
  useIsFocused: () => mockFocused, usePreventRemove: jest.fn(),
}));
jest.mock('../src/navigation/navigationRef', () => ({ navigateToWorkspaceIntent: jest.fn(), navigationRef: { isReady: jest.fn(() => true), navigate: jest.fn() } }));
jest.mock('../src/store/auth', () => ({ useAuth: () => ({ user: mockUser, refreshProfile: mockRefreshProfile }) }));
jest.mock('../src/features/workspace/WorkspaceContext', () => ({ useWorkspace: () => mockWorkspace }));
jest.mock('../src/features/workspace/WorkspaceHeaderActions', () => ({
  WorkspaceHeaderActions: ({ root, disabled }: { root?: boolean; disabled?: boolean }) => {
    const ReactModule = require('react');
    const { View: HeaderView, Pressable } = require('react-native');
    return ReactModule.createElement(HeaderView, null, root ? ReactModule.createElement(Pressable, {
      accessibilityRole: 'button', accessibilityLabel: 'Open account menu', disabled,
      onPress: disabled ? undefined : mockOpenAccount,
    }) : null);
  },
}));

let mockFocused = true;
let mockConnected = true;
let mockUser: User | null;
const mockRefreshProfile = jest.fn();
const mockOpenAccount = jest.fn();
const mockNavigation = { setParams: jest.fn(), navigate: jest.fn(), dispatch: jest.fn() };
const mockDrafts = new Map<string, { onDiscard: () => void; warning?: string }>();
const mockSubmissions = new Set<string>();
let mockPendingLeave: (() => void) | undefined;
const mockWorkspace = {
  activeUnit: null as WorkUnitEntitlement | null,
  units: [] as WorkUnitEntitlement[],
  enterPersonal: jest.fn(),
  registerDirtyDraft: jest.fn((key: string, options: { onDiscard: () => void; warning?: string }) => {
    mockDrafts.set(key, options);
    let registered = true;
    return () => { if (registered && mockDrafts.get(key) === options) { mockDrafts.delete(key); } registered = false; };
  }),
  registerSubmission: jest.fn((key: string) => {
    mockSubmissions.add(key);
    return () => { mockSubmissions.delete(key); };
  }),
  requestDiscard: jest.fn((continueAction: () => void, source?: string) => {
    if (mockSubmissions.size > 0) { return; }
    const drafts = [...mockDrafts.entries()].filter(([key]) => source === undefined || key === source);
    if (drafts.length === 0) { continueAction(); return; }
    mockPendingLeave = () => { drafts.forEach(([, draft]) => draft.onDiscard()); continueAction(); };
  }),
};
function unit(name: TeamUnit): WorkUnitEntitlement {
  return { unit: name, label: name, can_manage: false, features: { team_board: true, my_tasks: true, team_chat: true, team_calendar: true } };
}
function message(id: number, changes: Partial<api.TeamChatMessage> = {}): api.TeamChatMessage {
  return { id, body: `Message ${id}`, sender: { id: 2, name: 'Alex' }, created_at: '2026-11-07T08:00:00+08:00', client_nonce: null, tasks: [], mentions: [], reply_to: null, ...changes };
}
function page(messages: api.TeamChatMessage[], changes: Partial<api.TeamChatHistory> = {}): { data: api.TeamChatHistory } {
  return { data: { messages, has_more: false, next_before: null, last_read_id: 0, unread_count: messages.length, latest_id: messages.at(-1)?.id ?? 0, ...changes } };
}
function task(id: number): api.TeamChatTaskReference { return { id, title: `Task ${id}` }; }
function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
let renderer: ReactTestRenderer | undefined;
let client: QueryClient;
let element: React.ReactElement;
const scrollEnd = jest.fn();
const scrollIndex = jest.fn();
const scrollOffset = jest.fn();
function tree(node: React.ReactElement) { return <QueryClientProvider client={client}><OverlayProvider>{node}</OverlayProvider></QueryClientProvider>; }
async function render(node: React.ReactElement = <TeamChatScreen />) {
  element = node;
  await act(async () => {
    renderer = create(tree(element), { createNodeMock: native => native.type === TextInput ? { focus: jest.fn() } : null });
  });
}
async function rerender(node = React.cloneElement(element)) { element = node; await act(async () => { renderer!.update(tree(element)); }); }
async function tick(ms = 0) { await act(async () => { jest.advanceTimersByTime(ms); }); }
function field(label = 'Team message') { return renderer!.root.findAllByType(TextInput).find(node => node.props.accessibilityLabel === label)!; }
function change(value: string, label = 'Team message') { act(() => field(label).props.onChangeText(value)); }
function select(start: number, end = start) { act(() => field().props.onSelectionChange({ nativeEvent: { selection: { start, end } } })); }
function button(label: string) {
  const node = renderer!.root.findAll(item => item.props.accessibilityLabel === label && typeof item.props.onPress === 'function')[0];
  if (!node) { throw new Error(`Missing button: ${label}`); }
  return node;
}
async function press(label: string) { await act(async () => { button(label).props.onPress(); }); }
function allText(): string {
  const text = (node: ReactTestInstance): string => node.children.map(child => typeof child === 'string' ? child : text(child)).join('');
  return renderer!.root.findAllByType(Text).map(text).join('\n');
}
function bubbles() {
  const messageBubbleType = (TeamChatMessageBubble as unknown as { type: React.ComponentType }).type;
  return renderer!.root.findAllByType(messageBubbleType);
}
function history() { return renderer!.root.findByType(FlatList) as ReactTestInstance & { props: { data: TeamChatRow[] } }; }
function visible(info: { kind: 'message' | 'read-marker'; id: number; isViewable?: boolean }[], callback = history().props.onViewableItemsChanged) {
  const rows = history().props.data as TeamChatRow[];
  act(() => callback({ viewableItems: info.map(item => ({
    item: rows.find(row => item.kind === 'message' ? row.kind === 'message' && row.message.id === item.id : row.kind === 'read-marker' && row.messageId === item.id),
    isViewable: item.isViewable ?? true, key: `${item.kind}:${item.id}`, index: 0,
  })) }));
}
function guard() { return jest.mocked(usePreventRemove).mock.calls.at(-1)!; }
async function addTask(id = 4) {
  await press('Add task references'); await tick(0);
  await press(`Task ${id}: Task ${id}`);
  await press('Done choosing task references');
  await tick(40);
}

beforeEach(() => {
  jest.useFakeTimers(); jest.setSystemTime(new Date('2026-11-07T18:00:00Z')); jest.clearAllMocks();
  jest.replaceProperty(Platform, 'OS', 'android'); jest.replaceProperty(AppState, 'currentState', 'active');
  jest.mocked(useKeyboardVisible).mockReturnValue(false);
  mockFocused = true; mockConnected = true;
  focusManager.setFocused(true); onlineManager.setOnline(true);
  mockUser = { id: 12, tenant_id: 9, person_id: null, username: 'member', email: null, name: 'Member', avatar_initials: 'M', identifier: 'member', role: 'staff', is_teaching: false, permissions: [] };
  mockWorkspace.units = [unit('clinic'), unit('guidance')]; mockWorkspace.activeUnit = mockWorkspace.units[0];
  mockDrafts.clear(); mockSubmissions.clear(); mockPendingLeave = undefined;
  mockRefreshProfile.mockReset().mockResolvedValue(undefined);
  jest.mocked(navigationRef.isReady).mockReturnValue(true);
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } } });
  jest.mocked(api.history).mockReset().mockResolvedValue(page([message(10), message(11)], { has_more: true }));
  jest.mocked(api.markRead).mockReset().mockImplementation(async (_name, id) => ({ data: { last_read_id: id, unread_count: 0, latest_id: 11 } }));
  jest.mocked(api.tasks).mockReset().mockResolvedValue({ data: [task(4), task(5)], meta: { unit: 'clinic', total: 2, limit: 40 } });
  jest.mocked(api.members).mockReset().mockResolvedValue({ data: [{ id: 2, name: 'Alex', role: 'clinic_staff' }], meta: { unit: 'clinic', q: '', total: 1, page: 1, pages: 1, limit: 20 } });
  jest.mocked(api.send).mockReset().mockResolvedValue({ data: message(12) });
  let nonce = 0;
  jest.mocked(secureClientNonce).mockImplementation(() => `11111111-1111-4111-8111-${String(++nonce).padStart(12, '0')}`);
  jest.spyOn(FlatList.prototype, 'scrollToEnd').mockImplementation(scrollEnd);
  jest.spyOn(FlatList.prototype, 'scrollToIndex').mockImplementation(scrollIndex);
  jest.spyOn(FlatList.prototype, 'scrollToOffset').mockImplementation(scrollOffset);
});
afterEach(async () => {
  await act(async () => { renderer?.unmount(); }); renderer = undefined;
  client?.clear(); focusManager.setFocused(undefined); onlineManager.setOnline(true);
  jest.useRealTimers(); jest.restoreAllMocks();
});

describe('mounted Team Chat screen interactions', () => {
  it('pins the multiline composer above the keyboard outside the list, uses the root header, and handles taps', async () => {
    await render();
    const shell = renderer!.root.findByType(Screen);
    expect(shell.props.keyboardAware).toBe(true); expect(shell.props.scroll).toBe(false);
    expect(shell.props.bottomSafeArea).toBe(false); // The non-overlapping workspace tab bar owns safe bottom.
    expect(renderer!.root.findByType(KeyboardAvoidingView).props.enabled).toBe(false);
    expect(renderer!.root.findByType(WorkspaceHeaderActions).props.root).toBe(true);
    expect(history().props.keyboardShouldPersistTaps).toBe('handled');
    expect(field().props.multiline).toBe(true); expect(field().props.submitBehavior).toBe('newline');
    expect(field().props.onSubmitEditing).toBeUndefined();
    let parent = field().parent;
    while (parent && parent.type !== Screen) { expect(parent.type).not.toBe(FlatList); expect(parent.type).not.toBe(ScrollView); parent = parent.parent; }
    expect(parent?.type).toBe(Screen);
    const input = field(); change('one\ntwo'); expect(field()).toBe(input); expect(field().props.value).toBe('one\ntwo');
    jest.mocked(useKeyboardVisible).mockReturnValue(true); await rerender(); expect(field()).toBe(input);
    expect(renderer!.root.findAllByType(View).find(node => node.props.edges)!.props.edges).toEqual(['top']);
  });

  it('acknowledges only visible bottom markers, including tall messages, and rejects stale callbacks under overlays/keyboard/history', async () => {
    jest.mocked(api.history).mockResolvedValue(page([message(10, { body: 'Tall line\n'.repeat(200) }), message(11)], { has_more: true }));
    await render();
    expect(history().props.viewabilityConfig).toEqual({ itemVisiblePercentThreshold: 100, minimumViewTime: 500 });
    visible([{ kind: 'message', id: 10 }]); expect(api.markRead).not.toHaveBeenCalled();
    visible([{ kind: 'read-marker', id: 10, isViewable: false }]); expect(api.markRead).not.toHaveBeenCalled();
    visible([{ kind: 'read-marker', id: 10 }]); await tick(0); expect(api.markRead).toHaveBeenCalledWith('clinic', 10);
    const staleCallback = history().props.onViewableItemsChanged;
    await press('Add task references'); await tick(0);
    visible([{ kind: 'read-marker', id: 11 }], staleCallback); expect(api.markRead).toHaveBeenCalledTimes(1);
    await press('Done choosing task references'); await tick(40);
    visible([{ kind: 'read-marker', id: 11 }], staleCallback); expect(api.markRead).toHaveBeenCalledTimes(1);
    const beforeKeyboard = history().props.onViewableItemsChanged;
    jest.mocked(useKeyboardVisible).mockReturnValue(true); await rerender();
    visible([{ kind: 'read-marker', id: 11 }], beforeKeyboard); expect(api.markRead).toHaveBeenCalledTimes(1);
    visible([{ kind: 'read-marker', id: 11 }]); await tick(0); expect(api.markRead).toHaveBeenCalledTimes(2);
    jest.mocked(api.history).mockResolvedValueOnce(page([message(8), message(9)]));
    await press('Load older messages');
    visible([{ kind: 'read-marker', id: 9 }]); expect(api.markRead).toHaveBeenCalledTimes(2);
    expect(allText()).toContain('Viewing history.');
  });

  it('opens the quotation source with around, highlights that target, and Show Latest clears an old route target', async () => {
    const quote = message(11, { reply_to: { id: 5, sender_name: 'Blair', body: 'Original' } });
    jest.mocked(api.history).mockResolvedValueOnce(page([quote]));
    await render(<TeamChatScreen targetMessageId={11} />);
    jest.mocked(api.history).mockResolvedValueOnce(page([message(4), message(5)]));
    await press('Open original message from Blair');
    expect(api.history).toHaveBeenLastCalledWith('clinic', { around: 5 });
    expect(allText()).toContain('Viewing history.');
    const bubble = bubbles().find(node => node.props.message.id === 5)!;
    expect(bubble.props.targeted).toBe(true); expect(api.markRead).not.toHaveBeenCalled();
    act(() => history().props.onContentSizeChange());
    const index = history().props.data.findIndex((row: TeamChatRow) => row.kind === 'message' && row.message.id === 5);
    expect(scrollIndex).toHaveBeenLastCalledWith({ index, viewPosition: 0.5, animated: false });
    jest.mocked(api.history).mockResolvedValueOnce(page([message(20), message(21)]));
    await press('Show Latest');
    expect(mockNavigation.setParams).toHaveBeenCalledWith({ targetMessageId: undefined });
    expect(allText()).not.toContain('Viewing history.');
    expect(bubbles().every(node => node.props.targeted === false)).toBe(true);
    jest.mocked(api.history).mockResolvedValueOnce(page([message(22)]));
    await act(async () => { await client.invalidateQueries({ queryKey: teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic') }); });
    expect(api.history).toHaveBeenLastCalledWith('clinic', { after: 21 });
    expect(history().props.data.filter((row: TeamChatRow) => row.kind === 'message').map((row: { message: api.TeamChatMessage }) => row.message.id)).toEqual([20, 21, 22]);
  });

  it('keeps the window and gives retry feedback when a quotation cannot be opened', async () => {
    const quote = message(11, { reply_to: { id: 5, sender_name: 'Blair', body: 'Original' } });
    jest.mocked(api.history).mockResolvedValueOnce(page([quote]));
    await render();
    jest.mocked(api.history).mockRejectedValueOnce(new Error('missing'));
    await press('Open original message from Blair');
    expect(allText()).toContain('Could not open the original message.');
    expect(bubbles().map(node => node.props.message.id)).toEqual([11]);
    jest.mocked(api.history).mockResolvedValueOnce(page([message(5)]));
    await press('Open original message from Blair');
    expect(api.history).toHaveBeenLastCalledWith('clinic', { around: 5 });
    expect(allText()).not.toContain('Could not open the original message.');
  });

  it('navigates authorized read-only task references across tabs without discarding the same-scope composer', async () => {
    jest.mocked(api.history).mockResolvedValue(page([message(11, { tasks: [task(4)] })]));
    await render(); change('Keep this draft'); await press('Reply to Alex');
    expect(mockDrafts.size).toBe(1); expect(guard()[0]).toBe(true);
    await press('Open task 4: Task 4');
    expect(navigateToWorkspaceIntent).toHaveBeenCalledWith({ kind: 'team_task', unit: 'clinic', id: 4 });
    expect(mockWorkspace.requestDiscard).not.toHaveBeenCalled(); expect(field().props.value).toBe('Keep this draft');
    mockFocused = false; await rerender();
    expect(mockDrafts.size).toBe(1); expect(field().props.value).toBe('Keep this draft');
    mockFocused = true; await rerender();
    expect(field().props.value).toBe('Keep this draft'); expect(allText()).toContain('Replying to Alex');
    jest.mocked(navigationRef.isReady).mockReturnValue(false);
    await press('Open task 4: Task 4'); expect(allText()).toContain('Task navigation is not ready.');
    jest.mocked(navigationRef.isReady).mockReturnValue(true); mockWorkspace.units = []; await rerender();
    await press('Open task 4: Task 4'); expect(allText()).toContain('This task is not available in your current Team Workspace.');
    expect(navigateToWorkspaceIntent).toHaveBeenCalledTimes(1);
  });

  it('falls back to the authorized My Tasks tab and shows unavailable feedback when no task tab is enabled', async () => {
    mockWorkspace.units[0] = { ...mockWorkspace.units[0], features: { ...mockWorkspace.units[0].features, team_board: false } };
    mockWorkspace.activeUnit = mockWorkspace.units[0];
    jest.mocked(api.history).mockResolvedValue(page([message(11, { tasks: [task(4)] })]));
    await render(); change('Retained draft');
    await press('Open task 4: Task 4');
    expect(navigateToWorkspaceIntent).not.toHaveBeenCalled();
    expect(navigationRef.navigate).toHaveBeenCalledWith('AppTabs', {
      screen: 'WorkspaceContext', initial: false,
      params: { screen: 'MyTasks', initial: false, params: { screen: 'TeamTaskDetail', params: { id: 4 }, initial: false } },
    });
    expect(field().props.value).toBe('Retained draft'); expect(mockWorkspace.requestDiscard).not.toHaveBeenCalled();
    mockWorkspace.units[0] = { ...mockWorkspace.units[0], features: { ...mockWorkspace.units[0].features, my_tasks: false } };
    mockWorkspace.activeUnit = mockWorkspace.units[0]; await rerender();
    await press('Open task 4: Task 4');
    expect(allText()).toContain('Task details are not available in your current Team Workspace.');
    expect(navigationRef.navigate).toHaveBeenCalledTimes(1);
  });

  it('locks the full exact payload on ambiguous delivery, deduplicates same-tick send/retry, and rearms the next composer', async () => {
    await render(); await addTask(4); await press('Reply to Alex');
    change('😀 @Al'); select('😀 @Al'.length); await tick(200); await tick(0);
    const staleMention = button('Mention Alex').props.onPress;
    await press('Mention Alex'); change('😀 @Alex \nnext line'); select('😀 @Alex \nnext line'.length);
    const pendingSend = deferred<{ data: api.TeamChatMessage }>(); jest.mocked(api.send).mockReturnValueOnce(pendingSend.promise);
    const sendHandler = button('Send message').props.onPress;
    act(() => { sendHandler(); sendHandler(); }); await tick(0);
    expect(api.send).toHaveBeenCalledTimes(1);
    const payload = jest.mocked(api.send).mock.calls[0][1];
    expect(payload).toEqual({ body: '😀 @Alex \nnext line', client_nonce: '11111111-1111-4111-8111-000000000001', task_ids: [4], mentions: [{ user_id: 2, start: 3, length: 5 }], reply_to_id: 10 });
    expect(field().props.editable).toBe(false); expect(button('Add task references').props.disabled).toBe(true);
    expect(button('Cancel reply').props.disabled).toBe(true); expect(button('Remove task 4: Task 4').props.disabled).toBe(true);
    expect(renderer!.root.findByType(WorkspaceHeaderActions).props.disabled).toBe(true); expect(mockSubmissions.size).toBe(1);
    expect(allText()).toContain('Sending this exact message.'); expect(allText()).not.toContain('Delivery is unconfirmed.');
    change('cannot edit'); expect(field().props.value).toBe(payload.body);
    await act(async () => pendingSend.reject(new NetworkError())); await tick(0);
    expect(allText()).toContain('Delivery is unconfirmed and may have succeeded.');
    expect([...mockDrafts.values()][0].warning).toContain('cannot undo delivery');
    act(() => staleMention()); expect(field().props.value).toBe(payload.body);
    mockConnected = false; await act(async () => onlineManager.setOnline(false)); await rerender();
    expect(button('Retry send').props.disabled).toBe(true);
    mockConnected = true; await act(async () => onlineManager.setOnline(true)); await rerender(); await tick(5_000);
    expect(api.send).toHaveBeenCalledTimes(1);
    const retryHandler = button('Retry send').props.onPress;
    act(() => { retryHandler(); retryHandler(); }); await tick(0);
    expect(api.send).toHaveBeenCalledTimes(2); expect(jest.mocked(api.send).mock.calls[1][1]).toBe(payload);
    expect(secureClientNonce).toHaveBeenCalledTimes(1); expect(field().props.value).toBe(''); expect(field().props.editable).toBe(true);
    expect(mockDrafts.size).toBe(0); expect(mockSubmissions.size).toBe(0); expect(guard()[0]).toBe(false);
    change('A second message'); expect(mockDrafts.size).toBe(1); expect(guard()[0]).toBe(true);
    await press('Send message'); await tick(0);
    expect(api.send).toHaveBeenCalledTimes(3); expect(jest.mocked(api.send).mock.calls[2][1].client_nonce).not.toBe(payload.client_nonce);
  });

  it('actually discards all composer fields and retry copy without claiming to undo an ambiguous send', async () => {
    await render(); await addTask(); await press('Reply to Alex'); change('Pending message');
    jest.mocked(api.send).mockRejectedValueOnce(new NetworkError());
    await press('Send message'); await tick(0);
    expect(mockDrafts.size).toBe(1);
    act(() => guard()[1]({ data: { action: { type: 'POP' } } }));
    expect(mockNavigation.dispatch).not.toHaveBeenCalled(); expect(mockPendingLeave).toBeDefined();
    act(() => { mockPendingLeave!(); mockPendingLeave = undefined; });
    expect(field().props.value).toBe(''); expect(field().props.editable).toBe(true); expect(mockDrafts.size).toBe(0);
    expect(allText()).not.toContain('Replying to Alex'); expect(allText()).not.toContain('Delivery is unconfirmed');
    expect(mockNavigation.dispatch).toHaveBeenCalledWith({ type: 'POP' });
    change('New draft after discard'); expect(mockDrafts.size).toBe(1);
    await press('Send message'); await tick(0);
    expect(secureClientNonce).toHaveBeenCalledTimes(2); expect(jest.mocked(api.send).mock.calls[1][1].task_ids).toEqual([]);
    expect(jest.mocked(api.send).mock.calls[1][1].reply_to_id).toBeNull();
  });

  it('unlocks confirmed validation failures and uses a new nonce only after an explicit corrected send', async () => {
    await render(); change('Original');
    jest.mocked(api.send).mockRejectedValueOnce(new ApiError(422, { code: 'validation_failed', message: 'invalid' }));
    await press('Send message'); await tick(0);
    expect(field().props.editable).toBe(true); expect(allText()).toContain('Not sent. Correct');
    change('Corrected'); await press('Send message'); await tick(0);
    expect(api.send).toHaveBeenCalledTimes(2); expect(jest.mocked(api.send).mock.calls[1][1].client_nonce).not.toBe(jest.mocked(api.send).mock.calls[0][1].client_nonce);
  });

  it('bounds mention search/insertion and keeps UTF-16 ranges valid through cursor changes and edits', async () => {
    await render(); change('😀 @Al'); select(5, 6); await tick(200); expect(api.members).not.toHaveBeenCalled();
    select(6); await tick(200); await tick(0); await press('Mention Alex');
    expect(field().props.value).toBe('😀 @Alex '); expect(field().props.selection).toEqual({ start: 9, end: 9 });
    change('x😀 @Alex '); select(10); await press('Send message'); await tick(0);
    expect(jest.mocked(api.send).mock.calls[0][1].mentions).toEqual([{ user_id: 2, start: 4, length: 5 }]);
    change('@Al'); select(3); await tick(200); await tick(0); await press('Mention Alex');
    change('@A!lex '); select(7); await press('Send message'); await tick(0);
    expect(jest.mocked(api.send).mock.calls[1][1].mentions).toEqual([]);
  });

  it('shows mention-search failure with bounded explicit retry while retaining typed text', async () => {
    jest.mocked(api.members).mockRejectedValueOnce(new NetworkError());
    await render(); change('@Al'); select(3); await tick(200); await tick(0);
    expect(allText()).toContain('Could not search team members.'); expect(field().props.value).toBe('@Al');
    const retry = deferred<Awaited<ReturnType<typeof api.members>>>(); jest.mocked(api.members).mockReturnValueOnce(retry.promise);
    const handler = button('Retry member search').props.onPress;
    act(() => { handler(); handler(); }); await tick(0); expect(api.members).toHaveBeenCalledTimes(2);
    await act(async () => retry.resolve({ data: [{ id: 2, name: 'Alex', role: 'clinic_staff' }], meta: { unit: 'clinic', q: 'Al', total: 30, page: 1, pages: 2, limit: 20 } })); await tick(0);
    expect(allText()).toContain('Showing the first 20 members.');
    await press('Mention Alex'); expect(field().props.value).toBe('@Alex ');
  });

  it('reports loading/empty/initial errors truthfully and never shows pull-refresh spinner for background polling', async () => {
    const initial = deferred<{ data: api.TeamChatHistory }>(); jest.mocked(api.history).mockReturnValueOnce(initial.promise);
    await render(); expect(allText()).not.toContain('No messages yet'); expect(renderer!.root.findAllByType(FlatList)).toHaveLength(0);
    await act(async () => initial.reject(new NetworkError()));
    expect(allText()).toContain('You appear to be offline.'); expect(allText()).not.toContain('No messages yet');
    jest.mocked(api.history).mockResolvedValueOnce(page([])); await press('Retry loading Team Chat');
    expect(allText()).toContain('No messages yet');
    const poll = deferred<{ data: api.TeamChatHistory }>(); jest.mocked(api.history).mockReturnValueOnce(poll.promise);
    await tick(5_000); expect(renderer!.root.findByType(RefreshControl).props.refreshing).toBe(false);
    await act(async () => poll.reject(new NetworkError()));
    expect(allText()).toContain('Updates interrupted.');
    const manual = deferred<{ data: api.TeamChatHistory }>(); jest.mocked(api.history).mockReturnValueOnce(manual.promise);
    act(() => renderer!.root.findByType(RefreshControl).props.onRefresh());
    expect(renderer!.root.findByType(RefreshControl).props.refreshing).toBe(true);
    await act(async () => manual.resolve(page([]))); expect(renderer!.root.findByType(RefreshControl).props.refreshing).toBe(false);
    expect(allText()).not.toContain('Updates interrupted.');
  });

  it('clears protected drafts/data and ignores late sends across unit/session transitions', async () => {
    await render(); await addTask(); await press('Reply to Alex'); change('Old unit');
    const request = deferred<{ data: api.TeamChatMessage }>(); jest.mocked(api.send).mockReturnValueOnce(request.promise);
    await press('Send message'); await tick(0);
    mockWorkspace.activeUnit = mockWorkspace.units[1]; await rerender();
    expect(field().props.value).toBe(''); expect(allText()).not.toContain('Replying to Alex'); expect(mockDrafts.size).toBe(0);
    await act(async () => request.resolve({ data: message(999) })); await tick(0);
    expect(field().props.value).toBe(''); expect(mockNavigation.setParams).not.toHaveBeenCalled();
    expect(bubbles().some(node => node.props.message.id === 999)).toBe(false);
    change('Current unit'); mockUser = null; await rerender();
    expect(field().props.value).toBe(''); expect(mockDrafts.size).toBe(0);
  });

  it('removes protected data on a current denial and refreshes profile without remount bootstrap', async () => {
    await render(); change('No longer authorized');
    jest.mocked(api.history).mockRejectedValueOnce(new ApiError(403, { code: 'forbidden', message: 'Removed' }));
    await tick(5_000);
    expect(field().props.value).toBe(''); expect(bubbles()).toHaveLength(0);
    expect(mockWorkspace.enterPersonal).toHaveBeenCalledTimes(1); expect(mockRefreshProfile).toHaveBeenCalledTimes(1);
    expect(allText()).toContain('Protected chat data was removed.');
  });
});

describe('mounted Team Chat task picker and memo message', () => {
  it('uses the shared keyboard-aware scroll/footer, caps results, and enforces selection cap with removals allowed', async () => {
    const many = Array.from({ length: 50 }, (_, index) => task(index + 1));
    jest.mocked(api.tasks).mockResolvedValue({ data: many, meta: { unit: 'clinic', total: 70, limit: 40 } });
    let selected: api.TeamChatTaskReference[] = many.slice(0, 20);
    const changed = jest.fn((next: api.TeamChatTaskReference[]) => { selected = next; });
    const picker = () => <TeamChatTaskPicker unit="clinic" visible selected={selected} onChange={changed} onClose={jest.fn()} />;
    await render(picker()); await tick(0);
    expect(renderer!.root.findByType(BottomSheet).props.title).toBe('Task References');
    expect(renderer!.root.findAllByType(FlatList)).toHaveLength(0); expect(renderer!.root.findAllByType(ScrollView)).toHaveLength(1);
    expect(renderer!.root.findByType(ScrollView).props.keyboardShouldPersistTaps).toBe('handled');
    expect(renderer!.root.findByType(ScrollView).findAll(node => node.props.accessibilityLabel === 'Done choosing task references')).toHaveLength(0);
    expect(renderer!.root.findAll(node => node.props.accessibilityRole === 'checkbox' && typeof node.props.onPress === 'function')).toHaveLength(40);
    expect(allText()).toContain('Showing the first 40 of 70 matches.');
    expect(button('Task 21: Task 21').props.disabled).toBe(true); expect(button('Task 1: Task 1').props.disabled).toBe(false);
    await press('Task 21: Task 21'); expect(changed).not.toHaveBeenCalled();
    await press('Task 1: Task 1'); await rerender(picker()); expect(selected).toHaveLength(19);
    await press('Task 21: Task 21'); await rerender(picker()); expect(selected).toHaveLength(20);
    expect(selected.some(item => item.id === 21)).toBe(true);
  });

  it('debounces and clears task searches, hides mismatched old results, resets closed search, and retries failures once', async () => {
    let shown = true;
    const picker = () => <TeamChatTaskPicker unit="clinic" visible={shown} selected={[]} onChange={jest.fn()} onClose={jest.fn()} />;
    await render(picker()); await tick(0); expect(api.tasks).toHaveBeenLastCalledWith('clinic', '');
    change('new query', 'Search active tasks'); expect(allText()).not.toContain('#4: Task 4');
    await tick(249); expect(api.tasks).toHaveBeenCalledTimes(1);
    jest.mocked(api.tasks).mockRejectedValueOnce(new NetworkError()); await tick(1); await tick(0);
    expect(allText()).toContain('Could not search task references.');
    const search = deferred<Awaited<ReturnType<typeof api.tasks>>>(); jest.mocked(api.tasks).mockReturnValueOnce(search.promise);
    const handler = button('Retry task search').props.onPress;
    act(() => { handler(); handler(); }); await tick(0); expect(api.tasks).toHaveBeenCalledTimes(3);
    await act(async () => search.resolve({ data: [], meta: { unit: 'clinic', total: 0, limit: 40 } })); await tick(0);
    expect(allText()).toContain('No active tasks match this search.');
    await press('Clear task search'); expect(field('Search active tasks').props.value).toBe('');
    change('forgotten', 'Search active tasks'); shown = false; await rerender(picker()); await tick(40);
    shown = true; await rerender(picker()); await tick(0); expect(field('Search active tasks').props.value).toBe('');
  });

  it('exposes reference buttons and quotation, blocks disabled handlers, and memoizes an unchanged message', async () => {
    const onReply = jest.fn(); const onSource = jest.fn(); const onTask = jest.fn();
    const value = message(11, { body: '😀 @Alex ', mentions: [{ user_id: 2, name: 'Alex', start: 3, length: 5 }], tasks: [task(4)], reply_to: { id: 5, sender_name: 'Blair', body: 'Quoted' } });
    const bubble = (disabled = false) => <TeamChatMessageBubble message={value} own={false} targeted={false} replyDisabled={disabled} navigationDisabled={disabled} onReply={onReply} onOpenSource={onSource} onOpenTask={onTask} />;
    await render(bubble());
    const body = renderer!.root.findAllByType(Text).find(node => node.props.selectable)!;
    const sender = renderer!.root.findAllByType(Text).find(node => node.props.children === 'Alex')!;
    const senderStyle = StyleSheet.flatten(sender.props.style);
    expect(senderStyle.flex).toBeUndefined();
    expect(senderStyle.flexShrink).toBe(1);
    await press('Open task 4: Task 4'); await press('Open original message from Blair'); await press('Reply to Alex');
    expect(onTask).toHaveBeenCalledWith(task(4)); expect(onSource).toHaveBeenCalledWith(5); expect(onReply).toHaveBeenCalledWith(value);
    await rerender(bubble()); expect(renderer!.root.findAllByType(Text).find(node => node.props.selectable)).toBe(body);
    await rerender(bubble(true)); await press('Open task 4: Task 4'); await press('Open original message from Blair'); await press('Reply to Alex');
    expect(onTask).toHaveBeenCalledTimes(1); expect(onSource).toHaveBeenCalledTimes(1); expect(onReply).toHaveBeenCalledTimes(1);
    expect(renderer!.root.findAllByType(Modal)).toHaveLength(0);
    expect(StyleSheet.flatten(button('Open task 4: Task 4').props.style({ pressed: false })).minHeight).toBe(44);
  });
});
