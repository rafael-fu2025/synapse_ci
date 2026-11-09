import React from 'react';
import { Animated, AppState, StyleSheet, View, type AppStateStatus } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { Skeleton } from '../src/components/Skeleton';
import { useOverlayActivity } from '../src/components/OverlayProvider';
import { useReducedMotion } from '../src/lib/useReducedMotion';
import { darkTheme, lightTheme } from '../src/theme/tokens';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: mockTheme }) }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: jest.fn(() => true) }));
jest.mock('react-native/Libraries/AppState/AppState', () => ({ __esModule: true, default: { currentState: 'active', addEventListener: jest.fn(() => ({ remove: jest.fn() })) } }));
jest.mock('../src/components/OverlayProvider', () => ({ useOverlayActivity: jest.fn(() => false) }));

let mockTheme = lightTheme;
let focused = true;
let renderer: ReactTestRenderer | undefined;
let appChanged: (() => void) | undefined;
const focusListeners = new Map<string, Set<() => void>>();
const start = jest.fn(); const stop = jest.fn();
const navigation = {
  isFocused: () => focused,
  addListener: (event: string, listener: () => void) => {
    const listeners = focusListeners.get(event) ?? new Set();
    listeners.add(listener); focusListeners.set(event, listeners);
    return () => { listeners.delete(listener); };
  },
};
function mount(label = 'Loading visits', active = true) {
  act(() => { renderer = create(<NavigationContext.Provider value={navigation as never}><Skeleton label={label} active={active} /></NavigationContext.Provider>); });
}
function update(active = true) {
  act(() => { renderer!.update(<NavigationContext.Provider value={navigation as never}><Skeleton label="Loading visits" active={active} /></NavigationContext.Provider>); });
}

beforeEach(() => {
  jest.clearAllMocks(); mockTheme = lightTheme; focused = true; focusListeners.clear();
  jest.replaceProperty(AppState, 'currentState', 'active');
  jest.mocked(useReducedMotion).mockReturnValue(true);
  jest.mocked(useOverlayActivity).mockReturnValue(false);
  jest.spyOn(AppState, 'addEventListener').mockImplementation((_event, listener) => {
    appChanged = () => listener(AppState.currentState as AppStateStatus);
    return { remove: jest.fn() };
  });
  jest.spyOn(Animated, 'loop').mockReturnValue({ start, stop, reset: jest.fn() });
  jest.spyOn(Animated, 'timing');
});
afterEach(() => { act(() => { renderer?.unmount(); }); renderer = undefined; jest.restoreAllMocks(); });

it.each([lightTheme, darkTheme])('has one busy announcement, decorative noninteractive shapes and no shadows in $mode', theme => {
  mockTheme = theme;
  mount();
  const statuses = renderer!.root.findAll(node => node.props.accessibilityRole === 'progressbar' && typeof node.type === 'string');
  expect(statuses).toHaveLength(1);
  expect(statuses[0].props.accessibilityLabel).toBe('Loading visits');
  expect(statuses[0].props.accessibilityState.busy).toBe(true);
  expect(statuses[0].props.pointerEvents).toBe('none');
  const decorative = renderer!.root.findByType(Animated.View);
  expect(decorative.props.accessibilityElementsHidden).toBe(true);
  expect(decorative.props.importantForAccessibility).toBe('no-hide-descendants');
  for (const node of renderer!.root.findAllByType(View)) {
    const style = StyleSheet.flatten(node.props.style) ?? {};
    expect(style.elevation).toBeUndefined(); expect(style.shadowOpacity).toBeUndefined();
  }
  expect(start).not.toHaveBeenCalled();
});

it('runs only native non-interaction pulse and stops on focus, app, overlay, reduction and unmount', () => {
  jest.mocked(useReducedMotion).mockReturnValue(false);
  mount();
  expect(start).toHaveBeenCalledTimes(1);
  expect(Animated.timing).toHaveBeenCalledWith(expect.anything(), expect.objectContaining({ useNativeDriver: true, isInteraction: false }));
  focused = false; act(() => { for (const listener of focusListeners.get('blur') ?? []) { listener(); } });
  expect(stop).toHaveBeenCalledTimes(1);
  focused = true; act(() => { for (const listener of focusListeners.get('focus') ?? []) { listener(); } });
  expect(start).toHaveBeenCalledTimes(2);
  jest.replaceProperty(AppState, 'currentState', 'background'); act(() => appChanged?.());
  expect(stop).toHaveBeenCalledTimes(2);
  jest.replaceProperty(AppState, 'currentState', 'active'); act(() => appChanged?.());
  jest.mocked(useOverlayActivity).mockReturnValue(true); update();
  expect(stop).toHaveBeenCalledTimes(3);
  jest.mocked(useOverlayActivity).mockReturnValue(false); update();
  jest.mocked(useReducedMotion).mockReturnValue(true); update();
  expect(stop).toHaveBeenCalledTimes(4);
  jest.mocked(useReducedMotion).mockReturnValue(false); update();
  update(false); expect(stop).toHaveBeenCalledTimes(5);
  update(true); act(() => { renderer!.unmount(); }); renderer = undefined;
  expect(stop).toHaveBeenCalledTimes(6);
  expect([...focusListeners.values()].every(listeners => listeners.size === 0)).toBe(true);
});
