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
import { CONTEXT_BAR_RAISED_SPACE } from '../src/navigation/contextTabBarMetrics';
import { navigateToWorkspaceIntent, navigationRef } from '../src/navigation/navigationRef';
import { darkTheme, lightTheme } from '../src/theme/tokens';
import { WorkspaceHeaderActions } from '../src/features/workspace/WorkspaceHeaderActions';
import { TeamChatMessageBubble } from '../src/features/team-chat/components/TeamChatMessageBubble';
import { TeamChatTaskPicker } from '../src/features/team-chat/components/TeamChatTaskPicker';
import { teamChatKeys } from '../src/features/team-chat/hooks';
import { formatChatTime, type TeamChatRow } from '../src/features/team-chat/model';
import { TeamChatScreen } from '../src/features/team-chat/screens/TeamChatScreen';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn(), clearRefreshToken: jest.fn() }));
jest.mock('../src/lib/secureNonce', () => ({ secureClientNonce: jest.fn() }));
jest.mock('../src/api/endpoints/teamChat', () => ({ history: jest.fn(), markRead: jest.fn(), tasks: jest.fn(), members: jest.fn(), send: jest.fn() }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: mockTheme }) }));
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

const boundClient = expect.objectContaining({ runtime: expect.any(Object), sessionVersion: expect.any(Number), assertCurrent: expect.any(Function) });
let mockFocused = true;
let mockConnected = true;
let mockTheme = lightTheme;
let mockUser: User | null;
const mockRefreshProfile = jest.fn();
const mockOpenAccount = jest.fn();
const mockNavigationListeners = { blur: new Set<() => void>(), focus: new Set<() => void>() };
const mockNavigation = {
  setParams: jest.fn(), navigate: jest.fn(), dispatch: jest.fn(),
  addListener: (event: 'blur' | 'focus', listener: () => void) => {
    mockNavigationListeners[event].add(listener);
    return () => { mockNavigationListeners[event].delete(listener); };
  },
};
const mockDrafts = new Map<string, { onDiscard: () => void; warning?: string }>();
const mockSubmissions = new Set<string>();
let mockPendingLeave: (() => void) | undefined;
const mockWorkspace = {
  activeUnit: null as WorkUnitEntitlement | null,
  units: [] as WorkUnitEntitlement[],
  canSwitchContext: true,
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
function composerFooter() {
  return renderer!.root.findAllByType(View).find(node => {
    const style = StyleSheet.flatten(node.props.style);
    return style?.paddingHorizontal === 18 && style?.paddingVertical === 12 && style?.flexShrink === 0;
  })!;
}
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
function scrollList(offset: number, contentHeight = 1800, height = 500) {
  act(() => history().props.onScroll({ nativeEvent: { contentOffset: { x: 0, y: offset }, contentSize: { width: 360, height: contentHeight }, layoutMeasurement: { width: 360, height } } }));
}
function layoutList(height: number) { act(() => history().props.onLayout({ nativeEvent: { layout: { x: 0, y: 0, width: 360, height } } })); }
function contentList(height: number) { act(() => history().props.onContentSizeChange(360, height)); }
function pinLatest(contentHeight = 1800, height = 500) {
  layoutList(height); contentList(contentHeight); layoutLatestTail();
  scrollList(Math.max(0, contentHeight - height), contentHeight, height);
}
function dragList(offset: number, contentHeight = 1800, height = 500) {
  act(() => history().props.onScrollBeginDrag());
  scrollList(offset, contentHeight, height);
}
function latestTail() { return history().props.ListFooterComponent; }
function layoutLatestTail(tail = latestTail()) {
  act(() => tail.props.onLayout({ nativeEvent: { layout: { x: 0, y: 0, width: 360, height: 1 } } }));
}
function bottomButton() { return renderer!.root.findAll(node => node.props.testID === 'team-chat-scroll-bottom' && typeof node.props.onPress === 'function')[0]; }
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
  mockFocused = true; mockConnected = true; mockTheme = lightTheme;
  mockNavigationListeners.blur.clear(); mockNavigationListeners.focus.clear();
  mockWorkspace.canSwitchContext = true;
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
  it.each(['unread', 'read', 'own'] as const)('opens %s latest messages at the bottom after usable measurements', async kind => {
    jest.mocked(api.history).mockResolvedValueOnce(page([
      message(10, { sender: { id: kind === 'own' ? 12 : 2, name: 'Fixture' }, body: 'Long line\n'.repeat(30) }), message(11),
    ], { last_read_id: kind === 'read' ? 11 : 0 }));
    await render();
    expect(scrollEnd).not.toHaveBeenCalled();
    contentList(1800); layoutLatestTail(); layoutList(0);
    expect(scrollEnd).not.toHaveBeenCalled();
    layoutList(500);
    expect(scrollEnd).toHaveBeenCalledTimes(1);
    expect(scrollEnd).toHaveBeenCalledWith({ animated: false });
    expect(scrollIndex).not.toHaveBeenCalled();
    contentList(2100);
    expect(scrollOffset).toHaveBeenLastCalledWith({ offset: 1600, animated: false });
    scrollList(1600, 2100, 500);
    expect(bottomButton()).toBeUndefined();
    expect(api.markRead).not.toHaveBeenCalled();
  });

  it('handles an initial native scroll event before the committed tail without cancelling latest entry', async () => {
    await render();
    layoutList(500); contentList(1800);
    scrollList(0, 1800, 500);
    expect(scrollEnd).not.toHaveBeenCalled();
    layoutLatestTail();
    expect(scrollEnd).toHaveBeenCalledTimes(1);
    contentList(1900);
    expect(scrollOffset).toHaveBeenLastCalledWith({ offset: 1400, animated: false });
  });

  it('keeps latest anchored across keyboard, multiline and reply viewport changes without replacing the draft', async () => {
    await render();
    const input = field(); const list = history();
    pinLatest(); scrollEnd.mockClear(); scrollOffset.mockClear();
    change('Keep\nthis\ndraft');
    expect(scrollEnd).not.toHaveBeenCalled();
    jest.mocked(useKeyboardVisible).mockReturnValue(true); await rerender();
    layoutList(320); layoutLatestTail();
    expect(scrollEnd).toHaveBeenCalledTimes(1);
    contentList(1800);
    expect(scrollOffset).toHaveBeenLastCalledWith({ offset: 1480, animated: false });
    scrollList(1480, 1800, 320);
    scrollEnd.mockClear(); scrollOffset.mockClear();
    await press('Reply to Alex');
    layoutList(260); layoutLatestTail(); contentList(1800);
    expect(scrollOffset).toHaveBeenLastCalledWith({ offset: 1540, animated: false });
    scrollList(1540, 1800, 260);
    jest.mocked(useKeyboardVisible).mockReturnValue(false); await rerender();
    layoutList(500); layoutLatestTail(); contentList(1800);
    expect(field()).toBe(input); expect(history()).toBe(list);
    expect(field().props.value).toBe('Keep\nthis\ndraft');
    expect(api.send).not.toHaveBeenCalled(); expect(api.markRead).not.toHaveBeenCalled();
  });

  it('does not follow keyboard or incoming changes while deliberately reading older messages', async () => {
    await render(); pinLatest(); dragList(200);
    scrollEnd.mockClear(); scrollOffset.mockClear();
    jest.mocked(useKeyboardVisible).mockReturnValue(true); await rerender();
    layoutList(300); contentList(1800); layoutLatestTail();
    jest.mocked(api.history).mockResolvedValueOnce(page([message(12)]));
    await act(async () => client.invalidateQueries({ queryKey: teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic') }));
    contentList(2000); layoutLatestTail();
    expect(scrollEnd).not.toHaveBeenCalled(); expect(scrollOffset).not.toHaveBeenCalled();
    expect(bottomButton()).toBeDefined();
  });

  it('follows incoming messages only while pinned and ignores unchanged renders', async () => {
    await render(); pinLatest();
    scrollEnd.mockClear(); scrollOffset.mockClear();
    await rerender(); contentList(1800); layoutList(500);
    expect(scrollEnd).not.toHaveBeenCalled(); expect(scrollOffset).not.toHaveBeenCalled();
    jest.mocked(api.history).mockResolvedValueOnce(page([message(12)]));
    await act(async () => client.invalidateQueries({ queryKey: teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic') }));
    contentList(2000); layoutLatestTail(); contentList(2000);
    expect(scrollEnd).toHaveBeenCalledTimes(1);
    expect(scrollOffset).toHaveBeenLastCalledWith({ offset: 1500, animated: false });
    scrollList(1500, 2000, 500); dragList(200, 2000, 500);
    scrollEnd.mockClear(); scrollOffset.mockClear();
    jest.mocked(api.history).mockResolvedValueOnce(page([message(13)]));
    await act(async () => client.invalidateQueries({ queryKey: teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic') }));
    contentList(2200); layoutLatestTail();
    expect(scrollEnd).not.toHaveBeenCalled(); expect(scrollOffset).not.toHaveBeenCalled();
  });

  it('returns a retained route to latest without replacing the input/list or depending on another size event', async () => {
    await render(); pinLatest(); dragList(200);
    change('Retained draft'); const input = field(); const list = history();
    mockFocused = false; await rerender();
    jest.mocked(api.history).mockResolvedValueOnce(page([message(30), message(31)]));
    scrollEnd.mockClear(); scrollOffset.mockClear();
    mockFocused = true; await rerender();
    expect(api.history).toHaveBeenLastCalledWith('clinic', {}, boundClient);
    layoutLatestTail();
    expect(scrollEnd).toHaveBeenCalledTimes(1);
    scrollList(200, 1800, 500); layoutLatestTail();
    expect(scrollEnd).toHaveBeenCalledTimes(1);
    expect(field()).toBe(input); expect(history()).toBe(list);
    expect(field().props.value).toBe('Retained draft');
    expect(bubbles().map(node => node.props.message.id)).toEqual([30, 31]);
    expect(api.markRead).not.toHaveBeenCalled();
  });

  it('rejects stale scroll/read/send handlers after an actual blur even without a false focused render', async () => {
    await render(); pinLatest();
    change('Do not send while blurred');
    const oldSend = button('Send message').props.onPress;
    const oldLayout = history().props.onLayout; const oldRead = history().props.onViewableItemsChanged;
    scrollEnd.mockClear(); scrollOffset.mockClear();
    act(() => { for (const listener of mockNavigationListeners.blur) { listener(); } });
    act(() => oldLayout({ nativeEvent: { layout: { height: 300 } } }));
    act(() => oldSend());
    visible([{ kind: 'read-marker', id: 11 }], oldRead);
    expect(scrollEnd).not.toHaveBeenCalled(); expect(scrollOffset).not.toHaveBeenCalled();
    expect(api.send).not.toHaveBeenCalled();
    expect(api.markRead).not.toHaveBeenCalled();
    jest.mocked(api.history).mockResolvedValueOnce(page([message(30)]));
    await act(async () => { for (const listener of mockNavigationListeners.focus) { listener(); } });
    layoutList(500); contentList(1800); layoutLatestTail();
    expect(scrollEnd).toHaveBeenCalledTimes(1);
    expect(bubbles().map(node => node.props.message.id)).toEqual([30]);
  });

  it('does not let pre-reentry viewability callbacks acknowledge the replacement window', async () => {
    await render(); pinLatest();
    const oldRead = history().props.onViewableItemsChanged;
    mockFocused = false; await rerender();
    jest.mocked(api.history).mockResolvedValueOnce(page([message(10), message(11)]));
    mockFocused = true; await rerender();
    visible([{ kind: 'read-marker', id: 11 }], oldRead);
    expect(api.markRead).not.toHaveBeenCalled();
    visible([{ kind: 'read-marker', id: 11 }]); await tick();
    expect(api.markRead).toHaveBeenCalledTimes(1);
  });

  it('lets a new explicit target cancel a pending latest jump without clearing its route parameter', async () => {
    jest.mocked(api.history).mockResolvedValueOnce(page([message(4), message(5)]));
    await render(<TeamChatScreen targetMessageId={5} />);
    layoutList(500); contentList(1500);
    const latest = deferred<{ data: api.TeamChatHistory }>();
    jest.mocked(api.history).mockReturnValueOnce(latest.promise);
    await press('Show Latest');
    await rerender(<TeamChatScreen targetMessageId={30} />);
    jest.mocked(api.history).mockResolvedValueOnce(page([message(29), message(30)]));
    scrollEnd.mockClear(); scrollOffset.mockClear();
    await act(async () => latest.resolve(page([message(20), message(21)])));
    layoutList(500); contentList(1800); layoutLatestTail();
    expect(api.history).toHaveBeenLastCalledWith('clinic', { around: 30 }, boundClient);
    expect(bubbles().map(node => node.props.message.id)).toEqual([29, 30]);
    expect(bubbles().find(node => node.props.message.id === 30)?.props.targeted).toBe(true);
    expect(mockNavigation.setParams).not.toHaveBeenCalled();
    expect(scrollEnd).not.toHaveBeenCalled();
    expect(api.markRead).not.toHaveBeenCalled();
  });

  it.each(['inactive', 'returned'] as const)('positions the current latest window when a send succeeds after %s activity', async activity => {
    await render(); pinLatest();
    const request = deferred<{ data: api.TeamChatMessage }>();
    jest.mocked(api.send).mockReturnValueOnce(request.promise);
    change('Exactly once');
    await press('Send message');
    mockFocused = false; await rerender();
    if (activity === 'returned') {
      mockFocused = true; await rerender();
    }
    scrollEnd.mockClear(); scrollOffset.mockClear();
    await act(async () => request.resolve({ data: message(12) }));
    expect(api.send).toHaveBeenCalledTimes(1);
    expect(field().props.value).toBe('');
    if (activity === 'inactive') {
      expect(scrollEnd).not.toHaveBeenCalled();
      mockFocused = true; await rerender();
    }
    layoutList(500); contentList(1800); layoutLatestTail();
    expect(scrollEnd).toHaveBeenCalledWith({ animated: false });
    expect(api.history).toHaveBeenLastCalledWith('clinic', {}, boundClient);
    expect(api.markRead).not.toHaveBeenCalled();
  });

  it('pins the multiline composer above the keyboard outside the list, uses the root header, and handles taps', async () => {
    await render();
    const shell = renderer!.root.findByType(Screen);
    expect(shell.props.keyboardAware).toBe(true); expect(shell.props.scroll).toBe(false);
    expect(shell.props.bottomSafeArea).toBe(false); // The workspace tab bar owns safe bottom.
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

  it('clears the raised CTA and restores compact spacing without replacing the input or draft', async () => {
    await render();
    const input = field();
    change('Keep this draft');
    const list = history();
    const listStyle = StyleSheet.flatten(list.props.contentContainerStyle);
    const footerStyle = () => StyleSheet.flatten(composerFooter().props.style);
    expect(footerStyle().paddingBottom).toBe(CONTEXT_BAR_RAISED_SPACE + 8);
    expect(footerStyle().paddingVertical).toBe(12);

    jest.mocked(useKeyboardVisible).mockReturnValue(true);
    await rerender();
    expect(footerStyle().paddingBottom).toBeUndefined();
    expect(footerStyle().paddingVertical).toBe(12);
    expect(field()).toBe(input);
    expect(field().props.value).toBe('Keep this draft');

    jest.mocked(useKeyboardVisible).mockReturnValue(false);
    await rerender();
    expect(footerStyle().paddingBottom).toBe(CONTEXT_BAR_RAISED_SPACE + 8);
    mockWorkspace.canSwitchContext = false;
    await rerender();
    expect(footerStyle().paddingBottom).toBeUndefined();
    expect(footerStyle().paddingVertical).toBe(12);
    mockWorkspace.canSwitchContext = true;
    await rerender();
    expect(footerStyle().paddingBottom).toBe(CONTEXT_BAR_RAISED_SPACE + 8);
    expect(field()).toBe(input);
    expect(field().props.value).toBe('Keep this draft');
    expect(history()).toBe(list);
    expect(StyleSheet.flatten(history().props.contentContainerStyle)).toEqual(listStyle);
    expect(api.send).not.toHaveBeenCalled();
  });

  it.each([['light', lightTheme], ['dark', darkTheme]] as const)('joins the composer to the rounded bar canvas in %s mode', async (_mode, theme) => {
    mockTheme = theme;
    await render();
    const input = field();
    const footer = composerFooter();
    const surfaces = footer.findAllByType(View).filter(node =>
      StyleSheet.flatten(node.props.style)?.backgroundColor !== undefined && node.findAllByType(TextInput).includes(input));
    const safe = renderer!.root.findAllByType(View).find(node => node.props.edges)!;
    const assertCanvas = () => {
      expect(StyleSheet.flatten(safe.props.style).backgroundColor).toBe(theme.canvas);
      expect(StyleSheet.flatten(footer.props.style)).toMatchObject({ backgroundColor: theme.canvas, borderTopWidth: 0 });
      expect(surfaces.length).toBeGreaterThanOrEqual(2);
      surfaces.forEach(node => expect(StyleSheet.flatten(node.props.style).backgroundColor).toBe(theme.canvas));
      expect(StyleSheet.flatten(input.props.style)).toMatchObject({ minHeight: 44, maxHeight: 104, borderRadius: 14, borderColor: theme.line, backgroundColor: theme.surfaceWarm });
    };
    assertCanvas();
    jest.mocked(useKeyboardVisible).mockReturnValue(true);
    await rerender();
    expect(field()).toBe(input);
    assertCanvas();
  });

  it('acknowledges only visible bottom markers, including tall messages, and rejects stale callbacks under overlays/keyboard/history', async () => {
    jest.mocked(api.history).mockResolvedValue(page([message(10, { body: 'Tall line\n'.repeat(200) }), message(11)], { has_more: true }));
    await render();
    expect(history().props.viewabilityConfig).toEqual({ itemVisiblePercentThreshold: 100, minimumViewTime: 500 });
    visible([{ kind: 'message', id: 10 }]); expect(api.markRead).not.toHaveBeenCalled();
    visible([{ kind: 'read-marker', id: 10, isViewable: false }]); expect(api.markRead).not.toHaveBeenCalled();
    visible([{ kind: 'read-marker', id: 10 }]); await tick(0); expect(api.markRead).toHaveBeenCalledWith('clinic', 10, boundClient);
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
    expect(api.history).toHaveBeenLastCalledWith('clinic', { around: 5 }, boundClient);
    expect(allText()).toContain('Viewing history.');
    const bubble = bubbles().find(node => node.props.message.id === 5)!;
    expect(bubble.props.targeted).toBe(true); expect(api.markRead).not.toHaveBeenCalled();
    contentList(1200);
    const index = history().props.data.findIndex((row: TeamChatRow) => row.kind === 'message' && row.message.id === 5);
    expect(scrollIndex).toHaveBeenLastCalledWith({ index, viewPosition: 0.5, animated: false });
    jest.mocked(api.history).mockResolvedValueOnce(page([message(20), message(21)]));
    await press('Show Latest');
    expect(mockNavigation.setParams).toHaveBeenCalledWith({ targetMessageId: undefined });
    expect(allText()).not.toContain('Viewing history.');
    expect(bubbles().every(node => node.props.targeted === false)).toBe(true);
    jest.mocked(api.history).mockResolvedValueOnce(page([message(22)]));
    await act(async () => { await client.invalidateQueries({ queryKey: teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic') }); });
    expect(api.history).toHaveBeenLastCalledWith('clinic', { after: 21 }, boundClient);
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
    expect(api.history).toHaveBeenLastCalledWith('clinic', { around: 5 }, boundClient);
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

  it('keeps read markers invisible and groups nearby bubbles without horizontal separators', async () => {
    jest.mocked(api.history).mockResolvedValueOnce(page([message(10), message(11), message(12)], { last_read_id: 10 }));
    await render();
    const items = bubbles();
    expect(items.map(node => [node.props.startsGroup, node.props.endsGroup])).toEqual([[true, true], [true, false], [false, true]]);
    const markers = renderer!.root.findAllByType(View).filter(node => /^team-chat-read-marker-/.test(node.props.testID ?? ''));
    expect(markers).toHaveLength(3);
    markers.forEach(node => {
      expect(StyleSheet.flatten(node.props.style)).toEqual({ height: 2 });
      expect(node.props.accessible).toBe(false);
    });
    expect(allText()).toContain('Unread messages');
    expect(StyleSheet.flatten(composerFooter().props.style).borderTopWidth).toBe(0);
    expect(api.markRead).not.toHaveBeenCalled();
    visible([{ kind: 'read-marker', id: 11 }]);
    await tick(0);
    expect(api.markRead).toHaveBeenCalledWith('clinic', 11, boundClient);
  });

  it.each([lightTheme, darkTheme])('positions a shadow-free circular bottom arrow above the composer in $mode mode', async theme => {
    mockTheme = theme;
    await render();
    const input = field(); change('Draft stays');
    expect(bottomButton()).toBeUndefined();
    scrollList(1300); expect(bottomButton()).toBeUndefined();
    scrollList(1219); expect(bottomButton()).toBeDefined();
    const arrow = bottomButton();
    expect(StyleSheet.flatten(arrow.props.style({ pressed: false }))).toMatchObject({ width: 48, height: 48, borderRadius: 24, backgroundColor: theme.surface });
    expect(StyleSheet.flatten(arrow.parent!.props.style)).toMatchObject({ right: 16, bottom: 12, position: 'absolute' });
    expect(StyleSheet.flatten(arrow.props.style({ pressed: false })).elevation).toBeUndefined();
    expect(history().parent!.findAll(node => node.props.testID === 'team-chat-scroll-bottom').length).toBeGreaterThan(0);
    const requests = jest.mocked(api.history).mock.calls.length;
    scrollEnd.mockClear();
    await press('Scroll to latest messages');
    expect(scrollEnd).toHaveBeenCalledTimes(1);
    expect(scrollEnd).toHaveBeenCalledWith({ animated: false });
    expect(api.history).toHaveBeenCalledTimes(requests);
    expect(api.markRead).not.toHaveBeenCalled();
    scrollList(1300); expect(bottomButton()).toBeUndefined();
    scrollList(0, 240, 500); expect(bottomButton()).toBeUndefined();
    scrollList(-30, 240, 500); expect(bottomButton()).toBeUndefined();
    scrollList(1400); expect(bottomButton()).toBeUndefined();
    scrollList(1100); expect(bottomButton()).toBeDefined();
    layoutList(700); expect(bottomButton()).toBeUndefined();
    jest.mocked(useKeyboardVisible).mockReturnValue(true); await rerender();
    layoutList(320); expect(bottomButton()).toBeDefined();
    expect(field()).toBe(input); expect(field().props.value).toBe('Draft stays');
    scrollList(1480, 1800, 320); expect(bottomButton()).toBeUndefined();
  });

  it('queues a pull behind a background poll, deduplicates pulls and holds the manual spinner until completion', async () => {
    await render();
    layoutList(500); contentList(1800); scrollList(200);
    scrollEnd.mockClear(); scrollIndex.mockClear();
    const poll = deferred<{ data: api.TeamChatHistory }>();
    jest.mocked(api.history).mockReturnValueOnce(poll.promise);
    await tick(5_000);
    const refresh = () => renderer!.root.findByType(RefreshControl);
    expect(refresh().props.enabled).toBe(true);
    expect(refresh().props.refreshing).toBe(false);
    const manual = deferred<{ data: api.TeamChatHistory }>();
    jest.mocked(api.history).mockReturnValueOnce(manual.promise);
    act(() => { refresh().props.onRefresh(); refresh().props.onRefresh(); });
    expect(refresh().props.refreshing).toBe(true);
    expect(api.history).toHaveBeenCalledTimes(2);
    await act(async () => poll.resolve(page([message(12)])));
    expect(api.history).toHaveBeenCalledTimes(3);
    expect(api.history).toHaveBeenLastCalledWith('clinic', { after: 11 }, boundClient);
    expect(refresh().props.refreshing).toBe(true);
    expect(bubbles().map(node => node.props.message.id)).toEqual([10, 11]);
    await act(async () => manual.resolve(page([message(12), message(13)])));
    expect(refresh().props.refreshing).toBe(false);
    contentList(2000);
    expect(scrollEnd).not.toHaveBeenCalled(); expect(scrollIndex).not.toHaveBeenCalled();
    expect(api.markRead).not.toHaveBeenCalled();
    expect(bottomButton()).toBeDefined();
  });

  it('preserves a historical target and position when pull refresh fails or succeeds', async () => {
    jest.mocked(api.history).mockResolvedValueOnce(page([message(4), message(5)], { has_more: true }));
    await render(<TeamChatScreen targetMessageId={5} />);
    layoutList(500); contentList(1800); scrollList(1200);
    scrollEnd.mockClear(); scrollIndex.mockClear();
    const manual = deferred<{ data: api.TeamChatHistory }>();
    jest.mocked(api.history).mockReturnValueOnce(manual.promise);
    act(() => renderer!.root.findByType(RefreshControl).props.onRefresh());
    expect(renderer!.root.findByType(RefreshControl).props.refreshing).toBe(true);
    await act(async () => manual.reject(new NetworkError()));
    expect(bubbles().find(node => node.props.message.id === 5)!.props.targeted).toBe(true);
    expect(allText()).toContain('Viewing history.');
    jest.mocked(api.history).mockResolvedValueOnce(page([message(5, { body: 'Revalidated' }), message(6)]));
    await act(async () => renderer!.root.findByType(RefreshControl).props.onRefresh());
    expect(api.history).toHaveBeenLastCalledWith('clinic', { around: 5 }, boundClient);
    expect(bubbles().map(node => node.props.message.id)).toEqual([4, 5, 6]);
    contentList(1900);
    expect(scrollEnd).not.toHaveBeenCalled(); expect(scrollIndex).not.toHaveBeenCalled();
    expect(mockNavigation.setParams).not.toHaveBeenCalled(); expect(api.markRead).not.toHaveBeenCalled();
    expect(bottomButton()).toBeDefined();
  });

  it('waits for the latest replacement to commit and layout before scrolling from history', async () => {
    jest.mocked(api.history).mockResolvedValueOnce(page([message(4), message(5)]));
    await render(<TeamChatScreen targetMessageId={5} />);
    layoutList(500); contentList(1500);
    const oldTail = latestTail();
    scrollEnd.mockClear();
    const latest = deferred<{ data: api.TeamChatHistory }>();
    jest.mocked(api.history).mockReturnValueOnce(latest.promise);
    await press('Scroll to latest messages');
    expect(scrollEnd).not.toHaveBeenCalled();
    contentList(1500); layoutLatestTail(oldTail); expect(scrollEnd).not.toHaveBeenCalled();
    await act(async () => latest.resolve(page([message(20), message(21)])));
    expect(scrollEnd).not.toHaveBeenCalled();
    expect(mockNavigation.setParams).toHaveBeenCalledWith({ targetMessageId: undefined });
    expect(bubbles().map(node => node.props.message.id)).toEqual([20, 21]);
    expect(latestTail().key).not.toBe(oldTail.key);
    contentList(1800); layoutLatestTail(oldTail);
    expect(scrollEnd).not.toHaveBeenCalled();
    layoutLatestTail();
    expect(scrollEnd).toHaveBeenCalledTimes(1);
    expect(scrollEnd).toHaveBeenCalledWith({ animated: false });
    layoutLatestTail(); await tick(40); expect(scrollEnd).toHaveBeenCalledTimes(1);
    expect(api.markRead).not.toHaveBeenCalled();
    scrollList(1300); expect(bottomButton()).toBeUndefined();
  });

  it('finishes a return-to-latest after commit even when replacement content has the same native size', async () => {
    jest.mocked(api.history).mockResolvedValueOnce(page([message(4), message(5)]));
    await render(<TeamChatScreen targetMessageId={5} />);
    layoutList(500); contentList(1500);
    const oldTail = latestTail();
    scrollEnd.mockClear();
    jest.mocked(api.history).mockResolvedValueOnce(page([message(20), message(21)]));
    await press('Scroll to latest messages');
    expect(scrollEnd).not.toHaveBeenCalled();
    await tick(40); layoutLatestTail(oldTail);
    expect(scrollEnd).not.toHaveBeenCalled();
    expect(latestTail().key).not.toBe(oldTail.key);
    layoutLatestTail();
    expect(scrollEnd).toHaveBeenCalledTimes(1);
    expect(scrollEnd).toHaveBeenCalledWith({ animated: false });
  });

  it('keeps the history window and return arrow available after latest loading fails', async () => {
    jest.mocked(api.history).mockResolvedValueOnce(page([message(4), message(5)]));
    await render(<TeamChatScreen targetMessageId={5} />);
    layoutList(500); contentList(1500); scrollList(1000, 1500);
    scrollEnd.mockClear();
    jest.mocked(api.history).mockRejectedValueOnce(new NetworkError());
    await press('Scroll to latest messages'); await tick(40);
    expect(bubbles().map(node => node.props.message.id)).toEqual([4, 5]);
    expect(allText()).toContain('Viewing history.');
    expect(bottomButton().props.disabled).toBe(false);
    expect(scrollEnd).not.toHaveBeenCalled();
    expect(mockNavigation.setParams).not.toHaveBeenCalled();
  });

  it.each(['scope', 'overlay', 'blur'] as const)('rejects a pending latest-scroll completion after %s changes', async changeKind => {
    jest.mocked(api.history).mockResolvedValueOnce(page([message(4), message(5)]));
    await render(<TeamChatScreen targetMessageId={5} />);
    layoutList(500); contentList(1500);
    const latest = deferred<{ data: api.TeamChatHistory }>();
    jest.mocked(api.history).mockReturnValueOnce(latest.promise);
    await press('Scroll to latest messages');
    if (changeKind === 'scope') { mockWorkspace.activeUnit = mockWorkspace.units[1]; await rerender(); }
    else if (changeKind === 'overlay') { await press('Add task references'); await tick(0); }
    else { mockFocused = false; await rerender(); }
    scrollEnd.mockClear();
    await act(async () => latest.resolve(page([message(20), message(21)])));
    await tick(40);
    expect(scrollEnd).not.toHaveBeenCalled();
    expect(mockNavigation.setParams).not.toHaveBeenCalled();
  });

  it('does not yank readers downward on a hint or a manual refresh while they are above the bottom', async () => {
    await render();
    layoutList(500); contentList(1500); scrollList(100);
    scrollEnd.mockClear(); scrollIndex.mockClear();
    jest.mocked(api.history).mockResolvedValueOnce(page([message(12)]));
    await act(async () => client.invalidateQueries({ queryKey: teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic') }));
    contentList(1700);
    expect(bottomButton()).toBeDefined();
    expect(scrollEnd).not.toHaveBeenCalled(); expect(scrollIndex).not.toHaveBeenCalled();
    jest.mocked(api.history).mockResolvedValueOnce(page([message(13)]));
    await act(async () => renderer!.root.findByType(RefreshControl).props.onRefresh());
    contentList(1800);
    expect(scrollEnd).not.toHaveBeenCalled(); expect(scrollIndex).not.toHaveBeenCalled();
  });

  it('rejects old arrow, geometry and pull callbacks after the chat scope changes', async () => {
    await render();
    layoutList(500); contentList(1800); scrollList(0);
    const arrow = bottomButton().props.onPress;
    const scroll = history().props.onScroll;
    const pull = renderer!.root.findByType(RefreshControl).props.onRefresh;
    mockWorkspace.activeUnit = mockWorkspace.units[1]; await rerender();
    layoutList(500); contentList(200); scrollList(0, 200);
    scrollEnd.mockClear();
    const calls = jest.mocked(api.history).mock.calls.length;
    act(() => { arrow(); scroll({ nativeEvent: { contentOffset: { y: 0 }, contentSize: { height: 1800 }, layoutMeasurement: { height: 500 } } }); pull(); });
    expect(scrollEnd).not.toHaveBeenCalled(); expect(api.history).toHaveBeenCalledTimes(calls);
    expect(bottomButton()).toBeUndefined();
  });

  it('cancels a pending return-to-latest scroll if the user starts dragging or opens an overlay', async () => {
    jest.mocked(api.history).mockResolvedValueOnce(page([message(4), message(5)]));
    await render(<TeamChatScreen targetMessageId={5} />);
    layoutList(500); contentList(1500);
    const latest = deferred<{ data: api.TeamChatHistory }>();
    jest.mocked(api.history).mockReturnValueOnce(latest.promise);
    await press('Scroll to latest messages');
    act(() => history().props.onScrollBeginDrag());
    scrollEnd.mockClear();
    await act(async () => latest.resolve(page([message(20), message(21)])));
    contentList(1800); await tick(40);
    expect(scrollEnd).not.toHaveBeenCalled();
    scrollList(0);
    await press('Add task references'); await tick(0);
    expect(bottomButton()).toBeUndefined();
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
    await render(picker()); await tick(0); expect(api.tasks).toHaveBeenLastCalledWith('clinic', '', boundClient);
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

  it.each([false, true])('joins consecutive bubble corners and metadata while keeping each message accessible (own=%s)', async own => {
    const value = message(11);
    const reply = jest.fn();
    const bubble = (startsGroup: boolean, endsGroup: boolean) => <TeamChatMessageBubble message={value} own={own} targeted
      startsGroup={startsGroup} endsGroup={endsGroup} replyDisabled={false} onReply={reply} onOpenSource={jest.fn()} onOpenTask={jest.fn()} />;
    await render(bubble(true, false));
    const has = (label: string) => renderer!.root.findAllByType(Text).some(node => node.props.children === label);
    const sender = own ? 'You' : 'Alex';
    expect(has(sender)).toBe(true); expect(has(formatChatTime(value.created_at))).toBe(false);
    await rerender(bubble(false, false));
    expect(has(sender)).toBe(false); expect(has(formatChatTime(value.created_at))).toBe(false);
    const row = renderer!.root.findByProps({ testID: 'team-chat-message-11' });
    expect(row.props.accessibilityLabel).toBe(`Alex, ${formatChatTime(value.created_at)}. Message 11`);
    expect(StyleSheet.flatten(row.props.style)).toMatchObject({ paddingTop: 1, paddingBottom: 1, borderColor: '#d1972f' });
    const connected = row.findAllByType(View).find(node => StyleSheet.flatten(node.props.style)?.maxWidth === '84%')!;
    expect(StyleSheet.flatten(connected.props.style)).toMatchObject(own
      ? { borderTopRightRadius: 3, borderBottomRightRadius: 3 }
      : { borderTopLeftRadius: 3, borderBottomLeftRadius: 3 });
    await press('Reply to Alex'); expect(reply).toHaveBeenCalledWith(value);
    expect(StyleSheet.flatten(button('Reply to Alex').props.style({ pressed: false })).height).toBe(44);
    await rerender(bubble(false, true));
    expect(has(sender)).toBe(false); expect(has(formatChatTime(value.created_at))).toBe(true);
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
