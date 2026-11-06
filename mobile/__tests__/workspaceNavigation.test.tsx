import React from 'react';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { SharedContextTabBar } from '../src/navigation/SharedContextTabBar';
import { PersonalTabs } from '../src/navigation/PersonalTabs';
import { WorkspaceTabs } from '../src/navigation/WorkspaceTabs';
import { useWorkspace } from '../src/features/workspace/WorkspaceContext';
import { useTheme } from '../src/theme/ThemeProvider';
import { lightTheme } from '../src/theme/tokens';

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
jest.mock('@react-navigation/bottom-tabs', () => {
  const ReactRuntime = jest.requireActual('react');
  const { View } = jest.requireActual('react-native');
  return {
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

let renderer: ReactTestRenderer | undefined;

beforeEach(() => {
  jest.clearAllMocks();
  jest.mocked(useTheme).mockReturnValue({ theme: lightTheme } as ReturnType<typeof useTheme>);
  jest.mocked(useWorkspace).mockReturnValue(workspace());
  jest.spyOn(require('react-native').AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(true);
  jest.spyOn(require('react-native').AccessibilityInfo, 'addEventListener').mockReturnValue({ remove: jest.fn() });
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
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
  const style = work.props.style({ pressed: false });
  expect(style.flat().some((entry: { minHeight?: number }) => (entry?.minHeight ?? 0) >= 44)).toBe(true);
  act(() => work.props.onPress());
  expect(requestDiscard).toHaveBeenCalledWith(enterWorkspace);
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
