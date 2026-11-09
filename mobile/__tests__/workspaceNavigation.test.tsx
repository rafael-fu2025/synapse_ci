import React from 'react';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { Animated, BackHandler, Dimensions, Keyboard, Platform, StyleSheet } from 'react-native';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { ContextTabs } from '../src/navigation/ContextTabs';
import { useReducedMotion } from '../src/lib/useReducedMotion';
import { useKeyboardVisible } from '../src/lib/useKeyboardVisible';
import { useOverlayActivity } from '../src/components/OverlayProvider';
import { SharedContextTabBar } from '../src/navigation/SharedContextTabBar';
import { PersonalTabs } from '../src/navigation/PersonalTabs';
import { WorkspaceTabs } from '../src/navigation/WorkspaceTabs';
import { useWorkspace } from '../src/features/workspace/WorkspaceContext';
import { useTheme } from '../src/theme/ThemeProvider';
import { lightTheme } from '../src/theme/tokens';

jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: jest.fn(() => true) }));
jest.mock('../src/lib/useKeyboardVisible', () => ({ useKeyboardVisible: jest.fn(() => false) }));
jest.mock('../src/components/OverlayProvider', () => ({ useOverlayActivity: jest.fn(() => false) }));
jest.mock('../src/features/team-tasks/TeamTaskStacks', () => ({ TeamBoardStack: () => null, MyTasksStack: () => null }));
jest.mock('../src/features/team-chat', () => ({ TeamChatRoot: () => null }));
jest.mock('../src/features/team-calendar/TeamCalendarStack', () => ({ TeamCalendarRoot: () => null }));
jest.mock('../src/features/workspace/WorkspaceContext', () => ({ useWorkspace: jest.fn() }));
jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/secureNonce', () => ({ secureClientNonce: () => '11111111-1111-4111-8111-111111111111' }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: jest.fn() }));
jest.mock('../src/navigation/stacks/HomeStack', () => ({ HomeStack: () => null }));
jest.mock('../src/navigation/stacks/VisitsStack', () => ({ VisitsStack: () => null }));
jest.mock('../src/navigation/stacks/QueueStack', () => ({ QueueStack: () => null }));
jest.mock('../src/navigation/stacks/ProfileStack', () => ({ ProfileStack: () => null }));
jest.mock('../src/features/workspace/WorkspacePlaceholderScreen', () => ({
  TeamBoardRoot: () => null,
  MyTasksRoot: () => null,
  TeamChatRoot: () => null,
  TeamCalendarRoot: () => null,
}));
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));
jest.mock('@react-navigation/native', () => ({
  ...jest.requireActual('@react-navigation/native'),
  useFocusEffect: jest.fn(),
  useNavigation: jest.fn(),
}));
jest.mock('@react-navigation/bottom-tabs', () => {
  const ReactRuntime = jest.requireActual('react');
  const { View } = jest.requireActual('react-native');
  return {
    BottomTabBarHeightCallbackContext: ReactRuntime.createContext(undefined),
    createBottomTabNavigator: () => ({
      Navigator: (props: { children: React.ReactNode }) => ReactRuntime.createElement(View, { ...props, testID: 'navigator' }),
      Screen: () => null,
    }),
  };
});

const enterWorkspace = jest.fn();
const enterPersonal = jest.fn();
const requestDiscard = jest.fn((action: () => void) => action());

function workspace(overrides = {}) {
  return {
    mode: 'personal',
    canSwitchContext: true,
    enterWorkspace,
    enterPersonal,
    requestDiscard,
    ...overrides,
  } as unknown as ReturnType<typeof useWorkspace>;
}

function routeProps(): BottomTabBarProps {
  const routes = ['Home', 'Visits', 'Queue', 'Profile'].map((name, index) => ({ key: `${name}-${index}`, name }));
  return {
    state: { index: 0, routes },
    descriptors: Object.fromEntries(routes.map(route => [route.key, { options: { tabBarLabel: route.name, tabBarIcon: () => null } }])),
    navigation: { emit: jest.fn(() => ({ defaultPrevented: false })), navigate: jest.fn() },
    insets: { top: 0, right: 0, bottom: 12, left: 0 },
  } as unknown as BottomTabBarProps;
}

function mountWorkspaceContext() {
  act(() => { renderer = create(<ContextTabs />); });
  const screens = React.Children.toArray(renderer!.root.findByProps({ testID: 'navigator' }).props.children) as React.ReactElement<{
    name: string;
    component: React.ComponentType;
  }>[];
  const Component = screens.find(screen => screen.props.name === 'WorkspaceContext')!.props.component;
  act(() => renderer!.unmount());
  act(() => { renderer = create(<Component />); });
}

const originalWindow = Dimensions.get('window');
let renderer: ReactTestRenderer | undefined;

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers();
  jest.mocked(useReducedMotion).mockReturnValue(true);
  jest.mocked(useKeyboardVisible).mockReturnValue(false);
  jest.mocked(useOverlayActivity).mockReturnValue(false);
  jest.mocked(useTheme).mockReturnValue({ theme: lightTheme } as ReturnType<typeof useTheme>);
  jest.mocked(useWorkspace).mockReturnValue(workspace());
  jest.mocked(useFocusEffect).mockImplementation(callback => React.useEffect(callback, [callback]));
  jest.mocked(useNavigation).mockReturnValue({ getState: jest.fn(() => ({
    index: 1,
    routes: [{ name: 'PersonalContext' }, { name: 'WorkspaceContext', state: {
      type: 'tab', index: 0, routes: [{ state: { type: 'stack', index: 0, routes: [{ name: 'TeamBoardHome' }] } }],
    } }],
  })) } as never);
  jest.spyOn(Keyboard, 'isVisible').mockReturnValue(false);
  jest.spyOn(Keyboard, 'dismiss').mockImplementation(() => undefined);
  jest.spyOn(require('react-native').AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(true);
  jest.spyOn(require('react-native').AccessibilityInfo, 'addEventListener').mockReturnValue({ remove: jest.fn() });
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  Dimensions.set({ window: originalWindow });
  jest.useRealTimers();
  jest.restoreAllMocks();
});

it('registers exactly four real routes in each context and no fake center route', () => {
  act(() => { renderer = create(<PersonalTabs />); });
  let screens = React.Children.toArray(renderer!.root.findByProps({ testID: 'navigator' }).props.children) as React.ReactElement<{ name: string }>[];
  expect(screens.map(screen => screen.props.name)).toEqual(['Home', 'Visits', 'Queue', 'Profile']);
  act(() => renderer!.unmount());
  act(() => { renderer = create(<WorkspaceTabs />); });
  screens = React.Children.toArray(renderer!.root.findByProps({ testID: 'navigator' }).props.children) as React.ReactElement<{ name: string }>[];
  expect(screens.map(screen => screen.props.name)).toEqual(['TeamBoard', 'MyTasks', 'TeamChat', 'TeamCalendar']);
});

it('inserts a standalone center action with approved labels', () => {
  const props = routeProps();
  act(() => { renderer = create(<SharedContextTabBar {...props} />); });
  const work = renderer!.root.findByProps({ accessibilityLabel: 'Switch to Team Workspace' });
  expect(work.props.accessibilityRole).toBe('button');
  const style = typeof work.props.style === 'function' ? work.props.style({ pressed: false }) : work.props.style;
  expect(StyleSheet.flatten(style).minHeight).toBeGreaterThanOrEqual(44);
  act(() => work.props.onPress());
  expect(requestDiscard).not.toHaveBeenCalled(); // Entry guards actual switching, not picker opening.
  expect(enterWorkspace).toHaveBeenCalledTimes(1);

  jest.mocked(useWorkspace).mockReturnValue(workspace({ mode: 'workspace' }));
  act(() => renderer!.update(<SharedContextTabBar {...routeProps()} />));
  expect(renderer!.root.findByProps({ accessibilityLabel: 'Switch to Personal Portal' })).toBeTruthy();
});

it('keeps unauthorized users on an ordinary four-tab bar without a center CTA', () => {
  jest.mocked(useWorkspace).mockReturnValue(workspace({ canSwitchContext: false }));
  const props = routeProps();
  act(() => { renderer = create(<SharedContextTabBar {...props} />); });
  expect(renderer!.root.findAllByProps({ accessibilityLabel: 'Switch to Team Workspace' })).toHaveLength(0);
  expect(renderer!.root.findAll(node => node.props.accessibilityRole === 'tab' && typeof node.props.onPress === 'function')).toHaveLength(4);
});

it.each([PersonalTabs, WorkspaceTabs, ContextTabs].flatMap(Component =>
  (['android', 'ios'] as const).map(os => ({ Component, os })),
))('separates opaque Android peers from context motion and iOS fades ($os, $Component)', ({ Component, os }) => {
  jest.replaceProperty(Platform, 'OS', os);
  jest.mocked(useWorkspace).mockReturnValue(workspace({ personalEnabled: true, workEnabled: true }));
  jest.mocked(useReducedMotion).mockReturnValue(false);
  Dimensions.set({ window: { width: 360, height: 800, scale: 3, fontScale: 1 } });
  act(() => { renderer = create(<Component />); });
  const getOptions = () => {
    const raw = renderer!.root.findByProps({ testID: 'navigator' }).props.screenOptions;
    return typeof raw === 'function' ? raw({ route: { name: 'Home' } }) : raw;
  };
  const context = Component === ContextTabs;
  const fades = !context && os === 'ios';
  expect(getOptions()).toMatchObject({ animation: context ? 'shift' : fades ? 'fade' : 'none', lazy: !context, freezeOnBlur: true,
    transitionSpec: { config: { duration: context ? 240 : fades ? 160 : 0 } } });
  expect(getOptions().sceneStyle).toMatchObject({ backgroundColor: lightTheme.canvas, overflow: 'hidden' });
  if (context) {
    const scene = getOptions().sceneStyleInterpolator({ current: { progress: new Animated.Value(0.5) } }).sceneStyle;
    expect(scene.opacity).toBeUndefined();
    expect(scene.transform[0].translateX.__getValue()).toBe(180);
    act(() => Dimensions.set({ window: { width: 800, height: 360, scale: 3, fontScale: 1 } }));
    expect(getOptions().sceneStyleInterpolator({ current: { progress: new Animated.Value(1) } }).sceneStyle.transform[0].translateX.__getValue()).toBe(800);
  } else if (fades) {
    for (const value of [-1, -0.5, 0, 0.5, 1]) {
      const scene = getOptions().sceneStyleInterpolator({ current: { progress: new Animated.Value(value) } }).sceneStyle;
      expect(scene.transform).toBeUndefined();
      expect(scene.opacity.__getValue()).toBe(1 - Math.abs(value));
    }
  } else { expect(getOptions().sceneStyleInterpolator).toBeUndefined(); }
  jest.mocked(useReducedMotion).mockReturnValue(true);
  act(() => { renderer!.update(<Component />); });
  expect(getOptions()).toMatchObject({ animation: 'none', transitionSpec: { config: { duration: 0 } } });
  expect(getOptions().sceneStyleInterpolator).toBeUndefined();
});

it('does not leave the tab bar or an empty bottom gap while the keyboard is open', () => {
  jest.mocked(useKeyboardVisible).mockReturnValue(true);
  act(() => { renderer = create(<SharedContextTabBar {...routeProps()} />); });
  expect(renderer!.toJSON()).toBeNull();
  jest.mocked(useKeyboardVisible).mockReturnValue(false);
  act(() => renderer!.update(<SharedContextTabBar {...routeProps()} />));
  expect(renderer!.root.findByProps({ accessibilityRole: 'tablist' })).toBeTruthy();
});

it('suppresses same-tick context taps and allows another accepted action after the transition window', () => {
  act(() => { renderer = create(<SharedContextTabBar {...routeProps()} />); });
  const press = renderer!.root.findByProps({ accessibilityLabel: 'Switch to Team Workspace' }).props.onPress;
  act(() => { press(); press(); });
  expect(enterWorkspace).toHaveBeenCalledTimes(1);
  act(() => jest.advanceTimersByTime(240));
  act(() => press());
  expect(enterWorkspace).toHaveBeenCalledTimes(2);
});

it('blocks tab and context handlers while a shared overlay is exiting', () => {
  jest.mocked(useOverlayActivity).mockReturnValue(true);
  const props = routeProps();
  act(() => { renderer = create(<SharedContextTabBar {...props} />); });
  const tab = renderer!.root.findByProps({ accessibilityLabel: 'Visits' });
  const context = renderer!.root.findByProps({ accessibilityLabel: 'Switch to Team Workspace' });
  expect(tab.props.accessibilityState.disabled).toBe(true);
  act(() => { tab.props.onPress(); context.props.onPress(); });
  expect(props.navigation.navigate).not.toHaveBeenCalled();
  expect(enterWorkspace).not.toHaveBeenCalled();
});

it('respects prevented real-tab presses and preserves the tab long-press event', () => {
  const props = routeProps();
  act(() => { renderer = create(<SharedContextTabBar {...props} />); });
  const tab = renderer!.root.findByProps({ accessibilityLabel: 'Visits' });
  jest.mocked(props.navigation.emit).mockReturnValue({ defaultPrevented: true } as never);
  act(() => tab.props.onPress());
  expect(props.navigation.navigate).not.toHaveBeenCalled();
  act(() => tab.props.onLongPress());
  expect(props.navigation.emit).toHaveBeenLastCalledWith({ type: 'tabLongPress', target: 'Visits-1' });
});

it('dismisses the keyboard on Workspace Back without inspecting or changing the route', () => {
  jest.mocked(useWorkspace).mockReturnValue(workspace({ personalEnabled: true, workEnabled: true, mode: 'workspace' }));
  jest.mocked(Keyboard.isVisible).mockReturnValue(true);
  const listener = jest.spyOn(BackHandler, 'addEventListener');
  mountWorkspaceContext();
  const back = listener.mock.calls.find(call => call[0] === 'hardwareBackPress')![1];
  let handled: boolean | null | undefined;
  act(() => { handled = back({ type: 'hardwareBackPress', timeStamp: 0 }); });
  expect(handled).toBe(true);
  expect(Keyboard.dismiss).toHaveBeenCalledTimes(1);
  expect(enterPersonal).not.toHaveBeenCalled();
  expect(jest.mocked(useNavigation).mock.results[0].value.getState).not.toHaveBeenCalled();
});

it('leaves nested Workspace Back to the focused native stack', () => {
  jest.mocked(useWorkspace).mockReturnValue(workspace({ personalEnabled: true, workEnabled: true, mode: 'workspace' }));
  jest.mocked(useNavigation).mockReturnValue({ getState: jest.fn(() => ({ index: 0, routes: [{ state: {
    type: 'tab', index: 0, routes: [{ state: { type: 'stack', index: 1, routes: [{ name: 'Home' }, { name: 'Detail' }] } }],
  } }] })) } as never);
  const listener = jest.spyOn(BackHandler, 'addEventListener');
  mountWorkspaceContext();
  const back = listener.mock.calls.find(call => call[0] === 'hardwareBackPress')![1];
  let handled: boolean | null | undefined;
  act(() => { handled = back({ type: 'hardwareBackPress', timeStamp: 0 }); });
  expect(handled).toBe(false);
  expect(enterPersonal).not.toHaveBeenCalled();
  expect(Keyboard.dismiss).not.toHaveBeenCalled();
});

it('guards root Workspace Back and removes its listener on leave', () => {
  jest.mocked(useWorkspace).mockReturnValue(workspace({ personalEnabled: true, workEnabled: true, mode: 'workspace' }));
  const remove = jest.fn();
  const listener = jest.spyOn(BackHandler, 'addEventListener').mockReturnValue({ remove });
  mountWorkspaceContext();
  const back = listener.mock.calls.find(call => call[0] === 'hardwareBackPress')![1];
  let handled: boolean | null | undefined;
  act(() => { handled = back({ type: 'hardwareBackPress', timeStamp: 0 }); });
  expect(handled).toBe(true);
  expect(enterPersonal).toHaveBeenCalledTimes(1);
  act(() => renderer!.unmount());
  renderer = undefined;
  expect(remove).toHaveBeenCalledTimes(1);
});

it('never installs a Personal return handler for a work-only account', () => {
  jest.mocked(useWorkspace).mockReturnValue(workspace({ personalEnabled: false, workEnabled: true, mode: 'workspace' }));
  const listener = jest.spyOn(BackHandler, 'addEventListener');
  mountWorkspaceContext();
  expect(listener).not.toHaveBeenCalled();
});

it('does not mount disabled Workspace feature screens', () => {
  jest.mocked(useWorkspace).mockReturnValue(workspace({ activeUnit: { unit: 'clinic', features: { team_board: true, my_tasks: false, team_chat: false, team_calendar: true } } }));
  act(() => { renderer = create(<WorkspaceTabs />); });
  const screens = React.Children.toArray(renderer!.root.findByProps({ testID: 'navigator' }).props.children) as React.ReactElement<{ name: string }>[];
  expect(screens.map(screen => screen.props.name)).toEqual(['TeamBoard', 'TeamCalendar']);
});
