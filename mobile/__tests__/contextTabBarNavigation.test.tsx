import React, { useEffect, useState } from 'react';
import { Animated, Keyboard, StyleSheet, Text, TextInput, View } from 'react-native';
import { act, create, ReactTestInstance, ReactTestRenderer } from 'react-test-renderer';
import { createNavigationContainerRef, NavigationContainer, useRoute } from '@react-navigation/native';
import type { NavigationState } from '@react-navigation/native';
import { BottomTabView } from '@react-navigation/bottom-tabs';
import { Screen as NavigationScreen } from '@react-navigation/elements';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import type { MeResponse, TeamUnit, WorkUnitEntitlement } from '../src/api/types';
import { WorkspaceProvider, useWorkspace } from '../src/features/workspace/WorkspaceContext';
import { useContextDraftRegistration } from '../src/features/workspace/useContextDraftRegistration';
import { useOverlayActivity } from '../src/components/OverlayProvider';
import { ContextTabs } from '../src/navigation/ContextTabs';
import { ContextTabBarBinding, ContextTabBarHost, ContextTabBarProvider } from '../src/navigation/ContextTabBarBinding';
import { SharedContextTabBar } from '../src/navigation/SharedContextTabBar';
import type { ContextTabsParamList } from '../src/navigation/types';
import { useAuth } from '../src/store/auth';
import { useTheme } from '../src/theme/ThemeProvider';
import { lightTheme } from '../src/theme/tokens';

// Routers, tab views, bindings and WorkspaceProvider are real. Only native hosts
// and expensive feature leaves are replaced, following rootNavigatorAccess.
jest.mock('react-native-safe-area-context', () => jest.requireActual('react-native-safe-area-context/jest/mock').default);
jest.mock('react-native-screens', () => {
  const ReactRuntime = jest.requireActual<typeof import('react')>('react');
  const { View: NativeView } = jest.requireActual<typeof import('react-native')>('react-native');
  const ScreenHost = ReactRuntime.forwardRef<React.ComponentRef<typeof View>, React.ComponentProps<typeof View>>((props, ref) => <NativeView {...props} ref={ref} />);
  return {
    screensEnabled: () => true,
    Screen: ScreenHost, ScreenContainer: ScreenHost,
    ScreenStack: ScreenHost, ScreenStackItem: ScreenHost,
    ScreenStackHeaderBackButtonImage: ScreenHost, ScreenStackHeaderCenterView: ScreenHost,
    ScreenStackHeaderLeftView: ScreenHost, ScreenStackHeaderRightView: ScreenHost,
    ScreenStackHeaderSearchBarView: ScreenHost, compatibilityFlags: {},
  };
});
jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: jest.fn() }));
jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: () => false }));
jest.mock('../src/components/OverlayProvider', () => ({ useOverlayActivity: jest.fn(() => false) }));
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));
jest.mock('../src/navigation/stacks/HomeStack', () => ({ HomeStack: MockLeaf }));
jest.mock('../src/navigation/stacks/VisitsStack', () => ({ VisitsStack: MockVisitsForm }));
jest.mock('../src/navigation/stacks/QueueStack', () => ({ QueueStack: MockLeaf }));
jest.mock('../src/navigation/stacks/ProfileStack', () => ({ ProfileStack: MockProfileStack }));
jest.mock('../src/features/team-tasks/TeamTaskStacks', () => ({ TeamBoardStack: MockTeamBoardStack, MyTasksStack: MockLeaf }));
jest.mock('../src/features/team-chat', () => ({ TeamChatRoot: MockLeaf }));
jest.mock('../src/features/team-calendar/TeamCalendarStack', () => ({ TeamCalendarRoot: MockLeaf }));

const Board = createNativeStackNavigator<{ TeamTaskList: undefined; TeamTaskDetail: { id: number } }>();
const PersonalProfile = createNativeStackNavigator<{ ProfileHome: undefined; HealthProfile: undefined }>();
const mockMounted = jest.fn<void, [string, string]>();
const mockUnmounted = jest.fn<void, [string, string]>();
const mockDiscarded = jest.fn();
const mockUnhandled = jest.fn();
const timings: { value: Animated.Value; config: Parameters<typeof Animated.timing>[1]; stop: jest.Mock }[] = [];
let revealValue: Animated.Value | undefined;
let current: ReturnType<typeof useWorkspace>;
let renderer: ReactTestRenderer | undefined;
let navigation: ReturnType<typeof createNavigationContainerRef<ContextTabsParamList>>;
let showNavigation = true;

function useMountProbe() {
  const route = useRoute();
  useEffect(() => {
    mockMounted(route.name, route.key);
    return () => { mockUnmounted(route.name, route.key); };
  }, [route.key, route.name]);
  return route;
}

function MockLeaf() {
  const route = useMountProbe();
  const [value, setValue] = useState('');
  return <View testID={`${route.name}-screen`}><Text>{route.name}</Text><TextInput testID={`${route.name}-value`} value={value} onChangeText={setValue} /></View>;
}

function MockVisitsForm() {
  const route = useMountProbe();
  const [draft, setDraft] = useState('');
  const [submitting, setSubmitting] = useState(false);
  useContextDraftRegistration({ dirty: draft !== '', submitting, onDiscard: () => { mockDiscarded(); setDraft(''); } });
  return <View testID={`${route.name}-screen`}>
    <TextInput testID="Visits-draft" value={draft} onChangeText={setDraft} />
    <TextInput testID="Visits-submitting" value={String(submitting)} onChangeText={(value: string) => setSubmitting(value === 'true')} />
  </View>;
}

function MockTeamBoardStack() {
  return <Board.Navigator screenOptions={{ headerShown: false, animation: 'none' }}>
    <Board.Screen name="TeamTaskList" component={MockLeaf} />
    <Board.Screen name="TeamTaskDetail" component={MockLeaf} />
  </Board.Navigator>;
}

function MockProfileStack() {
  return <PersonalProfile.Navigator screenOptions={{ headerShown: false, animation: 'none' }}>
    <PersonalProfile.Screen name="ProfileHome" component={MockLeaf} />
    <PersonalProfile.Screen name="HealthProfile" component={MockLeaf} />
  </PersonalProfile.Navigator>;
}

function Probe() { current = useWorkspace(); return null; }

function unit(name: TeamUnit = 'clinic', features: Partial<WorkUnitEntitlement['features']> = {}): WorkUnitEntitlement {
  return { unit: name, label: name, can_manage: true, features: { team_board: true, my_tasks: true, team_chat: true, team_calendar: true, ...features } };
}

function profile(options: { personal?: boolean; work?: boolean; user?: number; units?: WorkUnitEntitlement[] } = {}): MeResponse {
  return {
    user: { id: options.user ?? 1, person_id: 1, tenant_id: 7, username: 'staff', email: null, name: 'Staff member', avatar_initials: 'S', identifier: 'staff', role: 'staff', is_teaching: false, permissions: ['team.read'] },
    entitlements: { portal: true, staff_queue_units: [], personal: { enabled: options.personal ?? true }, work: { enabled: options.work ?? true, units: options.units ?? [unit()] } },
    photo_url: '/api/v1/me/photo',
  };
}

function tree() {
  return <WorkspaceProvider><Probe />{showNavigation ? <NavigationContainer ref={navigation} onUnhandledAction={mockUnhandled}><ContextTabs /></NavigationContainer> : null}</WorkspaceProvider>;
}

async function mount(value: MeResponse | null = profile()) {
  jest.mocked(useAuth).mockReturnValue({ status: value ? 'signedIn' : 'signedOut', profile: value, user: value?.user ?? null } as ReturnType<typeof useAuth>);
  await act(async () => { renderer = create(tree()); });
  if (current.personalEnabled || current.workEnabled) { expect(navigation.isReady()).toBe(true); }
  expect(renderer!.root.findAllByType(NavigationContainer)).toHaveLength(1);
}

async function refresh(value: MeResponse | null) {
  jest.mocked(useAuth).mockReturnValue({ status: value ? 'signedIn' : 'signedOut', profile: value, user: value?.user ?? null } as ReturnType<typeof useAuth>);
  await act(async () => renderer!.update(tree()));
}

function rootState(): NavigationState {
  const state = navigation.getRootState();
  if (!state) { throw new Error('Expected a mounted real context tab router'); }
  return state;
}

function tabs(context: 'PersonalContext' | 'WorkspaceContext'): NavigationState {
  const state = rootState().routes.find(route => route.name === context)?.state;
  if (!state) { throw new Error(`Expected real inner tabs for ${context}`); }
  return state as NavigationState;
}

function tabView(context: 'PersonalContext' | 'WorkspaceContext') {
  return renderer!.root.findAllByType(BottomTabView).find(view => view.props.state.key === tabs(context).key)!;
}
function boardState() { return tabs('WorkspaceContext').routes.find(route => route.name === 'TeamBoard')!.state as NavigationState; }
function bar() { return renderer!.root.findByType(SharedContextTabBar); }
function byId(id: string) { return renderer!.root.findByProps({ testID: id }); }
function input(id: string) { return renderer!.root.findAllByType(TextInput).find(node => node.props.testID === id)!; }
function tab(label: string) { return bar().findByProps({ accessibilityLabel: label }); }
function contextTimings() { return timings.filter(timing => timing.value === revealValue); }
function style(node: ReactTestInstance) { return StyleSheet.flatten(node.props.style) as Record<string, any>; }

async function pressTab(label: string) { await act(async () => tab(label).props.onPress()); }
async function pressContext() { await act(async () => byId('context-switcher').props.onPress()); }
async function changeInput(id: string, value: string) { await act(async () => input(id).props.onChangeText(value)); }
async function advanceDebounce() { await act(async () => jest.advanceTimersByTime(240)); }

function expectOneAnchoredBar(previous?: ReactTestInstance) {
  expect(renderer!.root.findAllByType(SharedContextTabBar)).toHaveLength(1);
  expect(renderer!.root.findAll(node => typeof node.type === 'string' && node.props.testID === 'context-tab-bar')).toHaveLength(1);
  expect(renderer!.root.findAllByType(ContextTabBarProvider)).toHaveLength(1);
  expect(renderer!.root.findAllByType(ContextTabBarHost)).toHaveLength(1);
  if (previous) { expect(bar()).toBe(previous); }
  let ancestor = bar().parent;
  while (ancestor) {
    expect(ancestor.type).not.toBe(NavigationScreen);
    expect(ancestor.props.activityState).toBeUndefined();
    expect(style(ancestor)?.transform).toBeUndefined();
    ancestor = ancestor.parent;
  }
  expect(style(byId('context-tab-bar'))).toMatchObject({ paddingTop: 26, marginTop: -26 });
  expect(style(byId('context-switcher')).position).toBe('absolute');
  expect(style(byId('context-cta-disc'))).toMatchObject({ width: 56, height: 56, borderRadius: 28 });
  for (const view of renderer!.root.findAllByType(BottomTabView).filter(node => !node.props.state.routeNames.includes('PersonalContext') && !node.props.state.routeNames.includes('WorkspaceContext'))) {
    expect(Object.values(view.props.descriptors).every((descriptor: any) => descriptor.options.tabBarStyle.height === 0)).toBe(true);
  }
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers();
  navigation = createNavigationContainerRef<ContextTabsParamList>();
  showNavigation = true;
  revealValue = undefined;
  timings.length = 0;
  jest.mocked(useTheme).mockReturnValue({ theme: lightTheme } as ReturnType<typeof useTheme>);
  jest.mocked(useOverlayActivity).mockReturnValue(false);
  jest.spyOn(Keyboard, 'isVisible').mockReturnValue(false);
  jest.spyOn(Keyboard, 'dismiss').mockImplementation(() => undefined);
  // Hold the reveal; complete real router scene animations immediately. Native
  // animated Values/interpolators and the tab/native-stack views remain real.
  jest.spyOn(Animated, 'timing').mockImplementation((value, config) => {
    const animatedValue = value as Animated.Value;
    if (config.duration === 400 && revealValue === undefined) { revealValue = animatedValue; }
    const stop = jest.fn();
    timings.push({ value: animatedValue, config, stop });
    return {
      start: (complete?: (result: { finished: boolean }) => void) => {
        if (animatedValue !== revealValue) { animatedValue.setValue(config.toValue as number); complete?.({ finished: true }); }
      }, stop, reset: jest.fn(),
    } as Animated.CompositeAnimation;
  });
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  jest.useRealTimers();
  jest.restoreAllMocks();
});

it('keeps ONE persistent host outside real animated scenes while routing and retaining tab/stack history', async () => {
  await mount();
  const persistent = bar();
  expectOneAnchoredBar();
  expect(renderer!.root.findAllByType(BottomTabView)).toHaveLength(3);
  expect(rootState().routeNames).toEqual(['PersonalContext', 'WorkspaceContext']);
  expect(tabs('PersonalContext').routeNames).toEqual(['Home', 'Visits', 'Queue', 'Profile']);
  expect(tabs('WorkspaceContext').routeNames).toEqual(['TeamBoard', 'MyTasks', 'TeamChat', 'TeamCalendar']);
  expect(renderer!.root.findAllByType(ContextTabBarBinding).map(binding => binding.props.mode).sort()).toEqual(['personal', 'workspace']);
  expect(renderer!.root.findAllByType(NavigationScreen).some(scene => style(scene)?.transform?.[0]?.translateX !== undefined)).toBe(true);
  await pressTab('Visits');
  const personalHistory = tabs('PersonalContext');
  expect(navigation.getCurrentRoute()?.name).toBe('Visits');
  await pressContext();
  expect(current.mode).toBe('workspace');
  expect(rootState().routes[rootState().index].name).toBe('WorkspaceContext');
  expect(contextTimings()).toHaveLength(1);
  expect(contextTimings()[0].config).toMatchObject({ toValue: 1, duration: 400, useNativeDriver: true });
  await act(async () => revealValue!.setValue(0.6));
  await pressTab('Team Chat');
  expect(navigation.getCurrentRoute()?.name).toBe('TeamChat');
  await pressTab('Team Board');
  await act(async () => navigation.navigate('WorkspaceContext', { screen: 'TeamBoard', params: { screen: 'TeamTaskDetail', params: { id: 101 }, initial: false }, initial: false }));
  expect(navigation.getCurrentRoute()).toMatchObject({ name: 'TeamTaskDetail', params: { id: 101 } });
  await changeInput('TeamTaskDetail-value', 'retained Work view state');
  const workHistory = tabs('WorkspaceContext');
  const stackHistory = boardState();
  const detailKey = navigation.getCurrentRoute()!.key;
  const workAction = byId('context-switcher').props.onPress;
  await pressContext(); // Accepted mode commit permits reversal before 400ms.
  expect(current.mode).toBe('personal');
  expect(navigation.getCurrentRoute()?.name).toBe('Visits');
  expect(tabs('PersonalContext')).toEqual(personalHistory);
  expect(tabs('WorkspaceContext')).toEqual(workHistory);
  expect(boardState()).toEqual(stackHistory);
  expect(contextTimings()[1].config).toMatchObject({ toValue: 0, duration: 240, useNativeDriver: true });
  await act(async () => workAction()); // An old-context handler cannot switch again.
  expect(current.mode).toBe('personal');
  await pressContext();
  expect(navigation.getCurrentRoute()?.key).toBe(detailKey);
  expect(input('TeamTaskDetail-value').props.value).toBe('retained Work view state');
  expect(mockMounted.mock.calls.filter(([name]) => name === 'TeamTaskDetail')).toHaveLength(1);
  expect(mockUnmounted).not.toHaveBeenCalledWith('TeamTaskDetail', detailKey);
  expectOneAnchoredBar(persistent);
  expect(mockUnhandled).not.toHaveBeenCalled();
});

it('does not recolor or navigate for cancelled discard and switches only after accepted dialog completion', async () => {
  await mount();
  await pressTab('Visits');
  await changeInput('Visits-draft', 'unsaved visit');
  const persistent = bar();
  const before = rootState();
  const action = byId('context-switcher').props.onPress;
  await act(async () => { action(); action(); });
  expect(current.pendingDiscard).toBe(true);
  expect(current.mode).toBe('personal');
  expect(rootState()).toEqual(before);
  expect(contextTimings()).toHaveLength(0);
  await act(async () => current.cancelDiscard());
  await advanceDebounce();
  await pressContext();
  expect(current.pendingDiscard).toBe(false);
  expect(rootState()).toEqual(before);
  expect(current.hasDirtyDrafts).toBe(true);
  expect(input('Visits-draft').props.value).toBe('unsaved visit');
  expect(mockDiscarded).not.toHaveBeenCalled();
  jest.mocked(useOverlayActivity).mockReturnValue(true);
  await act(async () => renderer!.update(tree()));
  expect(byId('context-switcher').props.disabled).toBe(true);
  await pressTab('Queue');
  expect(rootState()).toEqual(before);
  await act(async () => current.completeDiscard());
  jest.mocked(useOverlayActivity).mockReturnValue(false);
  await act(async () => renderer!.update(tree()));
  await advanceDebounce();
  await pressContext();
  await act(async () => current.confirmDiscard());
  expect(mockDiscarded).toHaveBeenCalledTimes(1);
  expect(current.mode).toBe('personal');
  expect(rootState()).toEqual(before);
  expect(contextTimings()).toHaveLength(0);
  await act(async () => { current.completeDiscard(); current.completeDiscard(); });
  expect(current.mode).toBe('workspace');
  expect(contextTimings()).toHaveLength(1);
  expect(byId('context-switcher').props.accessibilityLabel).toBe('Switch to Personal Portal');
  expectOneAnchoredBar(persistent);
});

it('keeps picker opening/cancellation on Personal and binds only the accepted selected unit', async () => {
  await mount(profile({ units: [unit('clinic'), unit('guidance')] }));
  const persistent = bar();
  const before = rootState();
  await pressContext();
  expect(current.unitPickerVisible).toBe(true);
  expect(current.activeUnit).toBeNull();
  expect(current.mode).toBe('personal');
  expect(rootState()).toEqual(before);
  expect(contextTimings()).toHaveLength(0);
  expect(byId('context-switcher').props.disabled).toBe(true);
  await act(async () => current.closeUnitPicker());
  expect(current.mode).toBe('personal');
  expect(current.pendingDiscard).toBe(false);
  await advanceDebounce();
  await pressContext();
  await act(async () => current.selectUnit('guidance'));
  expect(current.activeUnit?.unit).toBe('guidance');
  expect(current.mode).toBe('workspace');
  expect(current.unitPickerVisible).toBe(false);
  expect(contextTimings()).toHaveLength(1);
  expect(bar().props.state).toBe(tabView('WorkspaceContext').props.state);
  expectOneAnchoredBar(persistent);
});

it('locks real tab/context presses during a Personal submission and restores routing when its lease releases', async () => {
  await mount();
  await pressTab('Visits');
  const heldTab = tab('Queue').props;
  const heldAction = byId('context-switcher').props.onPress;
  const before = rootState();
  await changeInput('Visits-submitting', 'true');
  expect(current.hasPendingSubmission).toBe(true);
  expect(tab('Queue').props.accessibilityState.disabled).toBe(true);
  expect(byId('context-switcher').props.accessibilityState.disabled).toBe(true);
  await act(async () => { heldTab.onPress(); heldTab.onLongPress(); heldAction(); });
  expect(rootState()).toEqual(before);
  expect(current.pendingDiscard).toBe(false);
  expect(contextTimings()).toHaveLength(0);
  await changeInput('Visits-submitting', 'false');
  expect(current.hasPendingSubmission).toBe(false);
  await pressTab('Queue');
  expect(navigation.getCurrentRoute()?.name).toBe('Queue');
  await pressContext();
  expect(current.mode).toBe('workspace');
  expect(contextTimings()).toHaveLength(1);
});

it('gates actual Work routes and invalidates a held handler when its feature is revoked', async () => {
  const enabled = { team_board: true, my_tasks: false, team_chat: false, team_calendar: true };
  await mount(profile({ units: [unit('clinic', enabled)] }));
  expect(tabs('WorkspaceContext').routeNames).toEqual(['TeamBoard', 'TeamCalendar']);
  await pressContext();
  const persistent = bar();
  expect(bar().findAll(node => node.props.accessibilityRole === 'tab' && typeof node.props.onPress === 'function').map(node => node.props.accessibilityLabel)).toEqual(['Team Board', 'Team Calendar']);
  await pressTab('Team Calendar');
  const held = tab('Team Calendar').props;
  await refresh(profile({ units: [unit('clinic', { ...enabled, team_calendar: false })] }));
  const before = rootState();
  expect(tabs('WorkspaceContext').routeNames).toEqual(['TeamBoard']);
  await act(async () => { held.onPress(); held.onLongPress(); });
  expect(rootState()).toEqual(before);
  expect(navigation.getCurrentRoute()?.name).toBe('TeamTaskList');
  expectOneAnchoredBar(persistent);
  expect(mockUnhandled).not.toHaveBeenCalled();
});

it.each(['selection', 'revocation'] as const)('resets only Work history after same-feature clinic-to-guidance %s', async transition => {
  await mount(profile({ units: [unit('clinic'), unit('guidance')] }));
  const persistent = bar();
  await pressTab('Profile');
  await act(async () => navigation.navigate('PersonalContext', { screen: 'Profile', params: { screen: 'HealthProfile', initial: false }, initial: false }));
  expect(navigation.getCurrentRoute()?.name).toBe('HealthProfile');
  await changeInput('HealthProfile-value', 'retained Personal detail');
  const personalDetailKey = navigation.getCurrentRoute()!.key;
  const personalHistory = tabs('PersonalContext');
  const personalRouteKey = rootState().routes.find(route => route.name === 'PersonalContext')!.key;

  await pressContext();
  await act(async () => current.selectUnit('clinic'));
  await act(async () => navigation.navigate('WorkspaceContext', { screen: 'TeamBoard', params: { screen: 'TeamTaskDetail', params: { id: 101 }, initial: false }, initial: false }));
  expect(navigation.getCurrentRoute()).toMatchObject({ name: 'TeamTaskDetail', params: { id: 101 } });
  await changeInput('TeamTaskDetail-value', 'clinic record 101');
  const oldDetailKey = navigation.getCurrentRoute()!.key;
  const oldRoot = rootState();
  const oldWorkRouteKey = oldRoot.routes.find(route => route.name === 'WorkspaceContext')!.key;
  const oldBinding = bar().props;
  const oldStack = boardState();
  const held = tab('My Tasks').props;
  const animationCount = contextTimings().length;

  if (transition === 'selection') { await act(async () => current.selectUnit('guidance')); }
  else { await refresh(profile({ units: [unit('guidance')] })); }

  expect(current.activeUnit?.unit).toBe('guidance');
  expect(current.mode).toBe('workspace');
  expect(boardState().routes.map(route => route.name)).toEqual(['TeamTaskList']);
  expect(boardState().index).toBe(0);
  expect(boardState().key).not.toBe(oldStack.key);
  expect(navigation.getCurrentRoute()?.name).toBe('TeamTaskList');
  expect(renderer!.root.findAllByProps({ testID: 'TeamTaskDetail-screen' })).toHaveLength(0);
  expect(mockUnmounted).toHaveBeenCalledWith('TeamTaskDetail', oldDetailKey);
  expect(rootState().key).toBe(oldRoot.key);
  expect(rootState().routeNames).toEqual(oldRoot.routeNames);
  expect(rootState().routes[rootState().index].name).toBe('WorkspaceContext');
  expect(rootState().routes.find(route => route.name === 'WorkspaceContext')!.key).not.toBe(oldWorkRouteKey);
  expect(tabs('WorkspaceContext').key).not.toBe(oldBinding.state.key);
  expect(tabs('WorkspaceContext').index).toBe(0);
  expect(tabs('WorkspaceContext').routeNames).toEqual(['TeamBoard', 'MyTasks', 'TeamChat', 'TeamCalendar']);
  expect(tabs('WorkspaceContext').routes.some(route => oldBinding.state.routes.some((oldRoute: { key: string }) => oldRoute.key === route.key))).toBe(false);
  expect(rootState().routes.find(route => route.name === 'PersonalContext')!.key).toBe(personalRouteKey);
  expect(tabs('PersonalContext')).toEqual(personalHistory);
  expect(bar().props.available).toBe(true);
  expect(bar().props.state).toBe(tabView('WorkspaceContext').props.state);
  expect(bar().props.navigation).not.toBe(oldBinding.navigation);
  expect(bar().props.contextBindings.workspace.state).toBe(bar().props.state);
  expect(contextTimings()).toHaveLength(animationCount);
  expectOneAnchoredBar(persistent);

  const afterReset = rootState();
  await act(async () => { held.onPress(); held.onLongPress(); });
  expect(rootState()).toEqual(afterReset);
  await pressTab('My Tasks');
  expect(navigation.getCurrentRoute()?.name).toBe('MyTasks');
  await pressContext();
  expect(navigation.getCurrentRoute()?.key).toBe(personalDetailKey);
  expect(input('HealthProfile-value').props.value).toBe('retained Personal detail');
  expect(tabs('PersonalContext')).toEqual(personalHistory);
  expect(mockUnmounted).not.toHaveBeenCalledWith('HealthProfile', personalDetailKey);
  expectOneAnchoredBar(persistent);
  expect(mockUnhandled).not.toHaveBeenCalled();
});

it('releases old-unit bindings without deleting the new lease or retaining their navigation callbacks', async () => {
  await mount(profile({ units: [unit('clinic'), unit('guidance', { team_chat: false })] }));
  await pressContext();
  await act(async () => current.selectUnit('clinic'));
  const persistent = bar();
  await pressTab('Team Chat');
  const oldBinding = bar().props;
  const held = tab('My Tasks').props;
  await act(async () => current.selectUnit('guidance'));
  expect(current.activeUnit?.unit).toBe('guidance');
  expect(bar().props.available).toBe(true);
  expect(bar().props.state).toBe(tabView('WorkspaceContext').props.state);
  // The unit-scoped parent route must own fresh state as well as a new binding lease.
  expect(bar().props.state.key).not.toBe(oldBinding.state.key);
  expect(bar().props.state).not.toBe(oldBinding.state);
  expect(bar().props.navigation).not.toBe(oldBinding.navigation);
  expect(bar().props.contextBindings.workspace.state).toBe(tabView('WorkspaceContext').props.state);
  expect(tabs('WorkspaceContext').routeNames).toEqual(['TeamBoard', 'MyTasks', 'TeamCalendar']);
  const before = rootState();
  await act(async () => { held.onPress(); held.onLongPress(); });
  expect(rootState()).toEqual(before);
  await pressTab('My Tasks');
  expect(navigation.getCurrentRoute()?.name).toBe('MyTasks');
  expect(contextTimings()).toHaveLength(1); // A same-context unit switch cannot restart the reveal.
  expectOneAnchoredBar(persistent);
});

it.each([
  { label: 'Personal only / Work rollout disabled', value: profile({ work: false }), routes: ['PersonalContext'], tabs: ['Home', 'Visits', 'Queue', 'Profile'] },
  { label: 'Work only', value: profile({ personal: false }), routes: ['WorkspaceContext'], tabs: ['TeamBoard', 'MyTasks', 'TeamChat', 'TeamCalendar'] },
])('omits the CTA and fake slot for $label while keeping real authorized tab routing', async scenario => {
  await mount(scenario.value);
  expect(rootState().routeNames).toEqual(scenario.routes);
  expect(tabs(scenario.routes[0] as 'PersonalContext' | 'WorkspaceContext').routeNames).toEqual(scenario.tabs);
  expect(renderer!.root.findAllByType(SharedContextTabBar)).toHaveLength(1);
  expect(renderer!.root.findAllByProps({ testID: 'context-switcher' })).toHaveLength(0);
  expect(renderer!.root.findAllByProps({ testID: 'context-cta-slot' })).toHaveLength(0);
  expect(style(byId('context-tab-bar')).paddingTop).toBe(0);
  const lastTab = scenario.routes[0] === 'PersonalContext' ? 'Profile' : 'Team Calendar';
  await pressTab(lastTab);
  const lastRoute = scenario.routes[0] === 'PersonalContext' ? 'ProfileHome' : 'TeamCalendar';
  expect(navigation.getCurrentRoute()?.name).toBe(lastRoute);
  expect(contextTimings()).toHaveLength(0);
});

it.each([
  { label: 'no portal entitlement', value: profile({ personal: false, work: false }) },
  { label: 'signed-out account', value: null },
])('does not mount tab bindings or protected feature leaves for $label', async scenario => {
  await mount(scenario.value);
  expect(renderer!.root.findAllByType(BottomTabView)).toHaveLength(0);
  expect(renderer!.root.findAllByType(ContextTabBarBinding)).toHaveLength(0);
  expect(renderer!.root.findAllByType(SharedContextTabBar)).toHaveLength(0);
  expect(renderer!.root.findByProps({ accessibilityRole: 'alert' }).props.children).toBe('No mobile portal access is available for this account.');
  expect(mockMounted).not.toHaveBeenCalled();
});

it('reconciles account/rollout loss and rejects callbacks captured from the previous authorized Work tree', async () => {
  await mount();
  await pressContext();
  const persistent = bar();
  const heldTab = tab('Team Chat').props;
  const heldAction = byId('context-switcher').props.onPress;
  await refresh(profile({ user: 99, units: [unit('guidance', { team_chat: false })] }));
  expect(current.mode).toBe('personal');
  expect(current.activeUnit?.unit).toBe('guidance');
  const afterAccount = rootState();
  await act(async () => { heldTab.onPress(); heldTab.onLongPress(); heldAction(); });
  expect(rootState()).toEqual(afterAccount);
  expect(current.pendingDiscard).toBe(false);
  expectOneAnchoredBar(persistent);
  await refresh(profile({ user: 99, work: false }));
  expect(rootState().routeNames).toEqual(['PersonalContext']);
  expect(renderer!.root.findAllByProps({ testID: 'context-switcher' })).toHaveLength(0);
  expect(bar().props.contextBindings.workspace).toBeUndefined();
  await refresh(null);
  expect(renderer!.root.findAllByType(SharedContextTabBar)).toHaveLength(0);
  await act(async () => { heldTab.onPress(); heldAction(); });
  expect(current.mode).toBe('personal');
  expect(current.pendingDiscard).toBe(false);
});

it('mounts peer tabs only on first visit while keeping route bindings and visited state', async () => {
  await mount();
  expect(mockMounted.mock.calls.map(([name]) => name)).toEqual(expect.arrayContaining(['Home', 'TeamTaskList']));
  expect(mockMounted.mock.calls.map(([name]) => name)).not.toEqual(expect.arrayContaining(['Visits', 'Queue', 'TeamChat', 'TeamCalendar']));
  expect(tabs('PersonalContext').routeNames).toEqual(['Home', 'Visits', 'Queue', 'Profile']);
  expect(bar().props.contextBindings.workspace!.state.routeNames).toEqual(['TeamBoard', 'MyTasks', 'TeamChat', 'TeamCalendar']);
  await pressTab('Queue');
  await changeInput('Queue-value', 'retained value');
  await pressTab('Home');
  await pressTab('Queue');
  expect(input('Queue-value').props.value).toBe('retained value');
  expect(mockMounted.mock.calls.filter(([name]) => name === 'Queue')).toHaveLength(1);
  expect(mockUnmounted.mock.calls.filter(([name]) => name === 'Queue')).toHaveLength(0);
  expectOneAnchoredBar();
});

it('cleans up bar/binding and form submission/draft leases on navigation unmount without discarding the form', async () => {
  await mount();
  await pressTab('Visits');
  await changeInput('Visits-draft', 'retained until leave');
  await changeInput('Visits-submitting', 'true');
  expect(current.hasDirtyDrafts).toBe(true);
  expect(current.hasPendingSubmission).toBe(true);
  const heldTab = tab('Queue').props;
  const heldAction = byId('context-switcher').props.onPress;
  showNavigation = false;
  await act(async () => renderer!.update(tree()));
  expect(current.hasDirtyDrafts).toBe(false);
  expect(current.hasPendingSubmission).toBe(false);
  expect(current.pendingDiscard).toBe(false);
  expect(renderer!.root.findAllByType(ContextTabBarBinding)).toHaveLength(0);
  expect(renderer!.root.findAllByType(SharedContextTabBar)).toHaveLength(0);
  expect(mockDiscarded).not.toHaveBeenCalled();
  await act(async () => { heldTab.onPress(); heldTab.onLongPress(); heldAction(); });
  expect(current.mode).toBe('personal');
  const accepted = jest.fn();
  await act(async () => { expect(current.requestDiscard(accepted)).toBe(true); });
  expect(accepted).toHaveBeenCalledTimes(1);
  showNavigation = true;
  await act(async () => renderer!.update(tree()));
  expect(input('Visits-draft')).toBeUndefined();
  await pressTab('Visits');
  expect(input('Visits-draft').props.value).toBe('');
  await pressContext();
  expect(current.mode).toBe('workspace');
  expect(current.pendingDiscard).toBe(false);
  expectOneAnchoredBar();
});
