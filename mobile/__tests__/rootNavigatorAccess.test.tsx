import React, { useEffect, useState } from 'react';
import { Text, TextInput, View } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { CommonActions, NavigationContainer, usePreventRemove, useRoute } from '@react-navigation/native';
import type { NavigationState } from '@react-navigation/native';
import { createNativeStackNavigator, NativeStackView } from '@react-navigation/native-stack';
import type { MeResponse } from '../src/api/types';
import { WorkspaceProvider } from '../src/features/workspace/WorkspaceContext';
import { __resetNavigationIntentForTests } from '../src/features/workspace/navigationIntent';
import { navigationRef } from '../src/navigation/navigationRef';
import { RootNavigator } from '../src/navigation/RootNavigator';
import type { TeamTasksStackParamList } from '../src/navigation/types';
import { useAuth } from '../src/store/auth';
import { useTheme } from '../src/theme/ThemeProvider';
import { lightTheme } from '../src/theme/tokens';

// RootNavigator, NavigationContainer, native-stack views/routers and the Personal
// registration condition are real. AppTabs content is a cheap, real nested Work
// stack: this suite tests root access changes, not the separately tested tab UI.
jest.mock('react-native-safe-area-context', () => jest.requireActual('react-native-safe-area-context/jest/mock').default);
jest.mock('react-native-screens', () => {
  const ReactRuntime = jest.requireActual<typeof import('react')>('react');
  const { View: NativeView } = jest.requireActual<typeof import('react-native')>('react-native');
  const ScreenHost = ReactRuntime.forwardRef<React.ComponentRef<typeof View>, React.ComponentProps<typeof View>>((props, ref) => (
    <NativeView {...props} ref={ref} />
  ));
  return {
    ScreenStack: ScreenHost,
    ScreenStackItem: ScreenHost,
    ScreenStackHeaderBackButtonImage: ScreenHost,
    ScreenStackHeaderCenterView: ScreenHost,
    ScreenStackHeaderLeftView: ScreenHost,
    ScreenStackHeaderRightView: ScreenHost,
    ScreenStackHeaderSearchBarView: ScreenHost,
    compatibilityFlags: {},
  };
});
jest.mock('@react-native-community/netinfo', () => ({ useNetInfo: () => ({ isConnected: true }) }));
jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: jest.fn() }));
jest.mock('../src/navigation/ContextTabs', () => ({ ContextTabs: MockWorkNavigator }));
jest.mock('../src/features/realtime/UserRealtimeGate', () => ({ UserRealtimeGate: () => null }));
jest.mock('../src/components/OfflineBanner', () => ({ OfflineBanner: () => null }));
jest.mock('../src/components/PushBanner', () => ({ PushBanner: () => null }));
jest.mock('../src/features/workspace/WorkspaceNotice', () => ({ WorkspaceNotice: () => null }));
jest.mock('../src/features/auth/screens/SplashScreen', () => ({ SplashScreen: () => null }));
jest.mock('../src/features/auth/screens/BootstrapErrorScreen', () => ({ BootstrapErrorScreen: () => null }));
jest.mock('../src/features/auth/screens/SessionExpiredScreen', () => ({ SessionExpiredScreen: () => null }));
jest.mock('../src/features/auth/screens/SignInScreen', () => ({ SignInScreen: () => null }));
jest.mock('../src/features/announcements/screens/AnnouncementsScreen', () => ({ AnnouncementsScreen: MockFeatureScreen }));
jest.mock('../src/features/announcements/screens/AnnouncementDetailScreen', () => ({ AnnouncementDetailScreen: MockFeatureScreen }));
jest.mock('../src/features/surveys/screens/SurveysScreen', () => ({ SurveysScreen: MockFeatureScreen }));
jest.mock('../src/features/surveys/screens/SurveyFormScreen', () => ({ SurveyFormScreen: MockSurveyForm }));
jest.mock('../src/features/notifications/screens/NotificationsScreen', () => ({ NotificationsScreen: MockFeatureScreen }));
jest.mock('../src/features/profile/screens/SettingsScreen', () => ({ SettingsScreen: MockFeatureScreen }));
jest.mock('../src/features/profile/screens/AppearanceScreen', () => ({ AppearanceScreen: MockFeatureScreen }));
jest.mock('../src/features/profile/screens/PrivacySecurityScreen', () => ({ PrivacySecurityScreen: MockFeatureScreen }));

const mockMounted = jest.fn<void, [string, string]>();
const mockUnmounted = jest.fn<void, [string, string]>();
const mockPreventedRemove = jest.fn();
const Work = createNativeStackNavigator<TeamTasksStackParamList>();

function useMockMountProbe() {
  const route = useRoute();
  useEffect(() => {
    mockMounted(route.name, route.key);
    return () => { mockUnmounted(route.name, route.key); };
  }, [route.key, route.name]);
  return route;
}

function MockWorkNavigator() {
  useMockMountProbe();
  return (
    <Work.Navigator screenOptions={{ headerShown: false, animation: 'none' }}>
      <Work.Screen name="TeamTaskList" component={MockFeatureScreen} />
      <Work.Screen name="TeamTaskDetail" component={MockFeatureScreen} />
      <Work.Screen name="TeamTaskForm" component={MockFeatureScreen} />
    </Work.Navigator>
  );
}

function MockFeatureScreen() {
  const route = useMockMountProbe();
  const [draft, setDraft] = useState('');
  return (
    <View testID={`${route.name}-screen`}>
      <Text>{route.name}</Text>
      <TextInput testID={`${route.name}-draft`} value={draft} onChangeText={setDraft} />
    </View>
  );
}

function MockSurveyForm() {
  // First prove this real guard blocks ordinary Back, then revoke access.
  usePreventRemove(true, mockPreventedRemove);
  return <MockFeatureScreen />;
}

function profile(personalEnabled: boolean): MeResponse {
  return {
    user: {
      id: 1, person_id: 1, tenant_id: 7, username: 'staff', email: null,
      name: 'Staff member', avatar_initials: 'S', identifier: 'staff', role: 'staff',
      is_teaching: false, permissions: ['team.read'],
    },
    entitlements: {
      portal: true,
      staff_queue_units: [],
      personal: { enabled: personalEnabled },
      work: {
        enabled: true,
        units: [{
          unit: 'clinic', label: 'Clinic', can_manage: true,
          features: { team_board: true, my_tasks: true, team_chat: true, team_calendar: true },
        }],
      },
    },
    photo_url: '/api/v1/me/photo',
  };
}

function authValue(next: MeResponse): ReturnType<typeof useAuth> {
  return {
    status: 'signedIn', user: next.user, profile: next,
    signIn: jest.fn(), retryBootstrap: jest.fn(), refreshProfile: jest.fn(),
    signOut: jest.fn(), acknowledgeExpired: jest.fn(),
  };
}

const personalRoutes = [
  { name: 'Announcements', params: undefined },
  { name: 'AnnouncementDetail', params: { id: 11 } },
  { name: 'Surveys', params: undefined },
  { name: 'SurveyForm', params: { id: 12 } },
];
const sharedRoutes = ['AppTabs', 'Notifications', 'AccountSettings'];
let renderer: ReactTestRenderer | undefined;

function tree() {
  return <WorkspaceProvider><RootNavigator /></WorkspaceProvider>;
}

function rootState(): NavigationState {
  const state = navigationRef.getRootState();
  if (!state) { throw new Error('Expected a mounted root navigator'); }
  return state;
}

async function mount(personalEnabled = true) {
  jest.mocked(useAuth).mockReturnValue(authValue(profile(personalEnabled)));
  await act(async () => { renderer = create(tree()); });
  expect(navigationRef.isReady()).toBe(true);
  expect(renderer!.root.findAllByType(NavigationContainer)).toHaveLength(1);
  expect(renderer!.root.findAllByType(NativeStackView).some(view => view.props.state.routeNames.includes('AppTabs'))).toBe(true);
  expect(rootState().type).toBe('stack');
}

async function changePersonalAccess(enabled: boolean) {
  jest.mocked(useAuth).mockReturnValue(authValue(profile(enabled)));
  // The provider/container/navigator are never replaced or keyed by access.
  await act(async () => { renderer!.update(tree()); });
}

async function navigate(name: string, params?: object) {
  await act(async () => { navigationRef.dispatch(CommonActions.navigate(name, params)); });
}

async function openWorkDetail() {
  await act(async () => {
    navigationRef.navigate('AppTabs', {
      screen: 'TeamTaskDetail', params: { id: 101 }, initial: false,
    } as never);
  });
  expect(navigationRef.getCurrentRoute()).toMatchObject({ name: 'TeamTaskDetail', params: { id: 101 } });
}

function appTabsRoute() {
  const route = rootState().routes.find(item => item.name === 'AppTabs');
  if (!route) { throw new Error('Expected retained AppTabs route'); }
  return route;
}

function workStack(): NavigationState {
  const state = appTabsRoute().state;
  if (!state) { throw new Error('Expected a mounted nested Work stack'); }
  return state as NavigationState;
}

function draftInput() {
  return renderer!.root.findAllByType(TextInput).find(input => input.props.testID === 'TeamTaskDetail-draft')!;
}

beforeEach(() => {
  jest.clearAllMocks();
  __resetNavigationIntentForTests();
  jest.mocked(useTheme).mockReturnValue({ theme: lightTheme } as ReturnType<typeof useTheme>);
});

afterEach(() => {
  act(() => { renderer?.unmount(); });
  renderer = undefined;
  __resetNavigationIntentForTests();
  jest.restoreAllMocks();
});

it('registers only shared root routes for a work-only account and rejects Personal navigation', async () => {
  await mount(false);
  expect(rootState().routeNames).toEqual(sharedRoutes);
  expect(workStack().routeNames).toEqual(['TeamTaskList', 'TeamTaskDetail', 'TeamTaskForm']);
  await openWorkDetail();

  const before = rootState();
  const warnings = jest.spyOn(console, 'error').mockImplementation(() => undefined);
  for (const route of personalRoutes) {
    await navigate(route.name, route.params);
    expect(rootState()).toEqual(before);
    expect(mockMounted.mock.calls.some(([name]) => name === route.name)).toBe(false);
  }
  expect(warnings).toHaveBeenCalledTimes(personalRoutes.length);
  expect(warnings.mock.calls.every(([message]) => String(message).includes('was not handled by any navigator'))).toBe(true);
  warnings.mockRestore();

  await navigate('Notifications');
  expect(navigationRef.getCurrentRoute()?.name).toBe('Notifications');
  await navigate('AccountSettings');
  expect(navigationRef.getCurrentRoute()?.name).toBe('Settings');
  expect(mockMounted.mock.calls.some(([name]) => name === 'Settings')).toBe(true);
});

it.each(personalRoutes)('removes an already-open $name on same-identity Personal revocation without remounting Work', async route => {
  await mount();
  await openWorkDetail();
  act(() => { draftInput().props.onChangeText('retained Work draft'); });
  const rootKey = rootState().key;
  const appTabsKey = appTabsRoute().key;
  const retainedWork = workStack();
  const workDetailKey = navigationRef.getCurrentRoute()!.key;
  expect(mockMounted.mock.calls.filter(([name]) => name === 'TeamTaskDetail')).toHaveLength(1);

  await navigate(route.name, route.params);
  const personalKey = navigationRef.getCurrentRoute()!.key;
  expect(navigationRef.getCurrentRoute()?.name).toBe(route.name);
  expect(mockMounted).toHaveBeenCalledWith(route.name, personalKey);

  if (route.name === 'SurveyForm') {
    await act(async () => { navigationRef.goBack(); });
    expect(mockPreventedRemove).toHaveBeenCalledTimes(1);
    expect(navigationRef.getCurrentRoute()?.key).toBe(personalKey);
    expect(mockUnmounted).not.toHaveBeenCalledWith(route.name, personalKey);
  }

  await changePersonalAccess(false);

  expect(rootState().key).toBe(rootKey);
  expect(rootState().routeNames).toEqual(sharedRoutes);
  expect(rootState().routes.map(item => item.name)).toEqual(['AppTabs']);
  expect(appTabsRoute().key).toBe(appTabsKey);
  expect(navigationRef.getCurrentRoute()).toMatchObject({ name: 'TeamTaskDetail', key: workDetailKey });
  expect(workStack()).toEqual(retainedWork);
  expect(draftInput().props.value).toBe('retained Work draft');
  expect(mockMounted.mock.calls.filter(([name]) => name === 'TeamTaskDetail')).toHaveLength(1);
  expect(mockMounted.mock.calls.filter(([name]) => name === 'AppTabs')).toHaveLength(1);
  expect(mockUnmounted).not.toHaveBeenCalledWith('TeamTaskDetail', workDetailKey);
  expect(mockUnmounted).not.toHaveBeenCalledWith('AppTabs', appTabsKey);
  expect(mockUnmounted).toHaveBeenCalledWith(route.name, personalKey);
  expect(renderer!.root.findAllByType(MockFeatureScreen).some(screen => screen.findAllByProps({ testID: `${route.name}-screen` }).length > 0)).toBe(false);
  expect(jest.mocked(useAuth)().status).toBe('signedIn');
  expect(jest.mocked(useAuth)().profile?.user).toMatchObject({ id: 1, tenant_id: 7 });
  if (route.name === 'SurveyForm') { expect(mockPreventedRemove).toHaveBeenCalledTimes(1); }

  await changePersonalAccess(true);
  expect(rootState().routeNames).toEqual([
    'AppTabs', ...personalRoutes.map(item => item.name), 'Notifications', 'AccountSettings',
  ]);
  expect(rootState().routes.map(item => item.name)).toEqual(['AppTabs']);
  expect(navigationRef.getCurrentRoute()?.key).toBe(workDetailKey);
  expect(workStack()).toEqual(retainedWork);
  expect(draftInput().props.value).toBe('retained Work draft');
});

it('retains open shared Notifications and AccountSettings routes when Personal access is revoked', async () => {
  await mount();
  await openWorkDetail();
  const retainedWork = workStack();
  await navigate('Notifications');
  await navigate('AccountSettings');
  const before = rootState();
  const settingsKey = navigationRef.getCurrentRoute()!.key;

  await changePersonalAccess(false);

  expect(rootState().key).toBe(before.key);
  expect(rootState().routes).toEqual(before.routes);
  expect(navigationRef.getCurrentRoute()).toMatchObject({ name: 'Settings', key: settingsKey });
  expect(mockUnmounted).not.toHaveBeenCalledWith('Settings', settingsKey);
  expect(workStack()).toEqual(retainedWork);
  await act(async () => { navigationRef.goBack(); });
  expect(navigationRef.getCurrentRoute()?.name).toBe('Notifications');
});
