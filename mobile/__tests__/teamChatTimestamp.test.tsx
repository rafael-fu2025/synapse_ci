import React, { memo } from 'react';
import { AppState, StyleSheet, Text, TextInput, View, type AppStateStatus } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { TeamChatTimestamp, TeamChatTimestampProvider } from '../src/features/team-chat/components/TeamChatTimestamp';
import { useOverlayActivity } from '../src/components/OverlayProvider';
import { darkTheme, lightTheme } from '../src/theme/tokens';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: mockTheme }) }));
jest.mock('../src/components/OverlayProvider', () => ({ useOverlayActivity: jest.fn(() => false) }));
jest.mock('react-native/Libraries/AppState/AppState', () => ({ __esModule: true, default: { currentState: 'active', addEventListener: jest.fn(() => ({ remove: jest.fn() })) } }));

let mockTheme = lightTheme;
let focused = true;
let active = true;
let renderer: ReactTestRenderer | undefined;
let appChanged: (() => void) | undefined;
const listeners = new Map<string, Set<() => void>>();
const probeRenders = jest.fn();
const removeApp = jest.fn();
const navigation = {
  isFocused: () => focused,
  addListener: (event: string, callback: () => void) => {
    const entries = listeners.get(event) ?? new Set(); entries.add(callback); listeners.set(event, entries);
    return () => { entries.delete(callback); };
  },
};
const Probe = memo(function Messages() {
  probeRenders();
  return <View>
    <TeamChatTimestamp createdAt="2026-11-09T13:47:00+08:00" messageId={1} />
    <TeamChatTimestamp createdAt="2026-11-01T09:05:00+08:00" messageId={2} />
    <TextInput value="Retained draft" />
  </View>;
});
function node() {
  return <NavigationContext.Provider value={navigation as never}><TeamChatTimestampProvider active={active}><Probe /></TeamChatTimestampProvider></NavigationContext.Provider>;
}
function mount() { act(() => { renderer = create(node()); }); }
function update() { act(() => renderer!.update(node())); }
function header(id = 1) { return renderer!.root.findAllByType(Text).find(value => value.props.testID === `team-chat-timestamp-${id}`)!; }
function emit(event: 'focus' | 'blur') { act(() => { for (const listener of listeners.get(event) ?? []) { listener(); } }); }

beforeEach(() => {
  jest.useFakeTimers(); jest.setSystemTime(new Date('2026-11-09T23:59:59+08:00')); jest.clearAllMocks();
  mockTheme = lightTheme; focused = true; active = true; listeners.clear();
  jest.replaceProperty(AppState, 'currentState', 'active');
  jest.mocked(useOverlayActivity).mockReturnValue(false);
  jest.spyOn(AppState, 'addEventListener').mockImplementation((_event, listener) => {
    appChanged = () => listener(AppState.currentState as AppStateStatus);
    return { remove: removeApp };
  });
});
afterEach(() => { act(() => renderer?.unmount()); renderer = undefined; jest.restoreAllMocks(); jest.useRealTimers(); });

it('uses one midnight timer and updates only subscribed headings, not the retained list or input', () => {
  mount();
  const input = renderer!.root.findByType(TextInput);
  const title = header();
  expect(title.props.children).toBe('1:47 PM');
  expect(jest.getTimerCount()).toBe(1);
  act(() => jest.advanceTimersByTime(1000));
  expect(header()).toBe(title);
  expect(title.props.children).toBe('Mon 1:47 PM');
  expect(header(2).props.children).toBe('Nov 1, 2026, 9:05 AM');
  expect(probeRenders).toHaveBeenCalledTimes(1);
  expect(renderer!.root.findByType(TextInput)).toBe(input);
  expect(jest.getTimerCount()).toBe(1);
});

it.each(['blur', 'background', 'overlay', 'inactive'] as const)('pauses on %s, catches up on return, and releases its clock/listeners', reason => {
  mount();
  if (reason === 'blur') { focused = false; emit('blur'); }
  else if (reason === 'background') { jest.replaceProperty(AppState, 'currentState', 'background'); act(() => appChanged?.()); }
  else if (reason === 'overlay') { jest.mocked(useOverlayActivity).mockReturnValue(true); update(); }
  else { active = false; update(); }
  expect(jest.getTimerCount()).toBe(0);
  act(() => jest.setSystemTime(new Date('2026-11-10T10:00:00+08:00')));
  expect(header().props.children).toBe('1:47 PM');
  if (reason === 'blur') { focused = true; emit('focus'); }
  else if (reason === 'background') { jest.replaceProperty(AppState, 'currentState', 'active'); act(() => appChanged?.()); }
  else if (reason === 'overlay') { jest.mocked(useOverlayActivity).mockReturnValue(false); update(); }
  else { active = true; update(); }
  expect(header().props.children).toBe('Mon 1:47 PM');
  expect(jest.getTimerCount()).toBe(1);
  expect(probeRenders).toHaveBeenCalledTimes(1);
  act(() => renderer!.unmount()); renderer = undefined;
  expect(jest.getTimerCount()).toBe(0);
  expect(removeApp).toHaveBeenCalledTimes(1);
  expect([...listeners.values()].every(values => values.size === 0)).toBe(true);
});

it.each([lightTheme, darkTheme])('keeps timestamp text centered, noninteractive and untruncated in $mode', theme => {
  mockTheme = theme; mount();
  const title = header(2);
  expect(title.props.accessibilityRole).toBe('header');
  expect(title.props.pointerEvents).toBe('none');
  expect(title.props.numberOfLines).toBeUndefined();
  expect(title.props.allowFontScaling).not.toBe(false);
  expect(StyleSheet.flatten(title.props.style)).toMatchObject({ alignSelf: 'center', maxWidth: '100%', textAlign: 'center', color: theme.ink500 });
});
