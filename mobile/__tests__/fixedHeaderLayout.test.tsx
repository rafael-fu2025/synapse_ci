import React from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { Screen } from '../src/components/Screen';
import { PageHeader } from '../src/components/PageHeader';
import { Skeleton } from '../src/components/Skeleton';
import { EmptyState } from '../src/components/EmptyState';
import { ApiError } from '../src/api/client';
import { ProfileHomeScreen } from '../src/features/profile/screens/ProfileHomeScreen';
import { AppearanceScreen } from '../src/features/profile/screens/AppearanceScreen';
import { PrivacySecurityScreen } from '../src/features/profile/screens/PrivacySecurityScreen';
import { SettingsScreen } from '../src/features/profile/screens/SettingsScreen';
import { HealthProfileScreen } from '../src/features/profile/screens/HealthProfileScreen';
import { useHealthSummary } from '../src/features/profile/hooks';
import { lightTheme } from '../src/theme/tokens';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test', lanTest: false } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: () => true }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme, mode: 'light', preference: 'system', setPreference: jest.fn() }) }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View, useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }));
jest.mock('react-native-linear-gradient', () => jest.requireActual('react-native').View);
jest.mock('@react-navigation/native', () => ({ useNavigation: () => ({ navigate: jest.fn(), goBack: jest.fn() }) }));
jest.mock('@tanstack/react-query', () => ({ useQuery: () => ({ data: { entitlements: { staff_queue_units: [] } } }) }));
jest.mock('../src/api/endpoints/me', () => ({ me: jest.fn() }));
jest.mock('../src/features/profile/hooks', () => ({ useHealthSummary: jest.fn() }));
jest.mock('../src/features/profile/useProfilePhoto', () => ({ useProfilePhoto: () => null }));
jest.mock('../src/store/auth', () => ({ useAuth: () => ({ user: { name: 'Alex', role: 'student', avatar_initials: 'AL' }, signOut: jest.fn() }) }));
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));

let renderer: ReactTestRenderer;
function render(element: React.ReactElement) {
  act(() => { renderer = create(element); });
  return renderer.root;
}
afterEach(() => { act(() => renderer?.unmount()); });
beforeEach(() => { jest.mocked(useHealthSummary).mockReturnValue({ isLoading: true } as ReturnType<typeof useHealthSummary>); });

it('keeps the fixed header inside the safe top but outside the padded scroll body', () => {
  const root = render(<Screen header={<PageHeader edgeToEdge title="Fixed" />}><Text>Body</Text></Screen>);
  const safe = root.findAllByType(View).find(node => node.props.edges?.[0] === 'top')!;
  expect(safe.props.edges).toEqual(['top', 'bottom']);
  const viewport = root.findByType(ScrollView);
  expect(safe.findByType(PageHeader)).toBeTruthy();
  expect(viewport.findAllByType(PageHeader)).toHaveLength(0);
  expect(StyleSheet.flatten(safe.props.style)).toMatchObject({ flex: 1 });
  expect(StyleSheet.flatten(safe.props.style).paddingBottom).toBeUndefined();
  expect(StyleSheet.flatten(viewport.props.style)).toEqual({ flex: 1 });
  const body = viewport.findAllByType(View).find(node => StyleSheet.flatten(node.props.style)?.paddingHorizontal === 18)!;
  expect(StyleSheet.flatten(body.props.style)).toMatchObject({ paddingHorizontal: 18, paddingTop: 18, paddingBottom: 20 });
  expect(StyleSheet.flatten(body.props.style).marginTop).toBeUndefined();
  const header = root.findByType(PageHeader).findAllByType(View)[0];
  expect(StyleSheet.flatten(header.props.style)).toMatchObject({ marginHorizontal: 0, marginBottom: 0, paddingHorizontal: 18 });
});

it('allows an unpadded non-scroll viewport without changing the fixed header', () => {
  const root = render(<Screen scroll={false} bodyStyle={{ paddingHorizontal: 0, paddingBottom: 0 }} header={<PageHeader edgeToEdge title="Queue" />}><View testID="list" /></Screen>);
  expect(root.findAllByType(ScrollView)).toHaveLength(0);
  const body = root.findByProps({ testID: 'list' }).parent!;
  expect(StyleSheet.flatten(body.props.style)).toEqual({ flex: 1, paddingHorizontal: 0, paddingBottom: 0 });
  expect(body.findAllByType(PageHeader)).toHaveLength(0);
});

it('preserves legacy body/header gutters and ignores bodyStyle for scroll shells', () => {
  const root = render(<Screen bodyStyle={{ paddingHorizontal: 0 }}><PageHeader title="Legacy" /><Text>Body</Text></Screen>);
  const viewport = root.findByType(ScrollView);
  expect(viewport.findAllByType(PageHeader)).toHaveLength(1);
  const header = root.findByType(PageHeader).findAllByType(View)[0];
  expect(StyleSheet.flatten(header.props.style)).toMatchObject({ marginHorizontal: -18, marginBottom: 18 });
  const body = viewport.findAllByType(View).find(node => StyleSheet.flatten(node.props.style)?.paddingHorizontal === 18)!;
  expect(StyleSheet.flatten(body.props.style)).toEqual({ flex: 1, paddingHorizontal: 18, paddingBottom: 20 });
});

it('overrides footer spacing and surface without changing the body or shared defaults', () => {
  const tree = (custom: boolean) => <Screen footer={<View testID="pinned-footer" />}
    footerStyle={custom ? { paddingBottom: 34, backgroundColor: lightTheme.canvas } : undefined}><Text>Body</Text></Screen>;
  const root = render(tree(false));
  const footer = root.findByProps({ testID: 'pinned-footer' }).parent!;
  const defaults = { flexShrink: 0, paddingHorizontal: 18, paddingVertical: 12, borderTopWidth: 1, borderTopColor: lightTheme.line, backgroundColor: lightTheme.surface };
  expect(StyleSheet.flatten(footer.props.style)).toEqual(defaults);
  const viewport = root.findByType(ScrollView);
  const body = viewport.findAllByType(View).find(node => StyleSheet.flatten(node.props.style)?.paddingHorizontal === 18)!;
  const bodyStyle = StyleSheet.flatten(body.props.style);
  expect(viewport.findAllByProps({ testID: 'pinned-footer' })).toHaveLength(0);

  act(() => renderer.update(tree(true)));
  expect(root.findByProps({ testID: 'pinned-footer' }).parent).toBe(footer);
  expect(StyleSheet.flatten(footer.props.style)).toEqual({ ...defaults, paddingBottom: 34, backgroundColor: lightTheme.canvas });
  expect(StyleSheet.flatten(body.props.style)).toEqual(bodyStyle);
  act(() => renderer.update(tree(false)));
  expect(StyleSheet.flatten(footer.props.style)).toEqual(defaults);
});

it.each([ProfileHomeScreen, AppearanceScreen, PrivacySecurityScreen, SettingsScreen])('%p fixes only the page header and leaves content scrolling', Component => {
  const root = render(<Component />);
  const screen = root.findByType(Screen);
  expect(screen.props.header.type).toBe(PageHeader);
  expect(screen.props.header.props.edgeToEdge).toBe(true);
  const viewport = root.findByType(ScrollView);
  expect(viewport.findAllByType(PageHeader)).toHaveLength(0);
  expect(viewport.findAllByType(Text).length).toBeGreaterThan(0);
  if (Component === ProfileHomeScreen) {
    expect(viewport.findByProps({ accessibilityLabel: 'Change profile photo' })).toBeTruthy();
  }
});

it.each(['fetching', 'paused', 'idle'] as const)('shows truthful initial health profile %s with its header retained', fetchStatus => {
  jest.mocked(useHealthSummary).mockReturnValue({ fetchStatus } as ReturnType<typeof useHealthSummary>);
  const root = render(<HealthProfileScreen />);
  expect(root.findByType(PageHeader).props.title).toBe('Health profile');
  if (fetchStatus === 'fetching') {
    expect(root.findByType(Skeleton).props.label).toBe('Loading health profile…');
  } else {
    expect(root.findAllByType(Skeleton)).toHaveLength(0);
    expect(root.findByType(EmptyState).props.title).toBe('Waiting to load health profile');
  }
});

it('keeps cached health fields during refresh failure but hides them after access is removed', () => {
  const data = { data: { person: { name: 'Alex', kind: 'student', identifier: '001' }, health: {} } };
  jest.mocked(useHealthSummary).mockReturnValue({ data, isLoading: true } as unknown as ReturnType<typeof useHealthSummary>);
  const root = render(<HealthProfileScreen />);
  expect(root.findAllByType(Skeleton)).toHaveLength(0);
  jest.mocked(useHealthSummary).mockReturnValue({ data, isError: true, error: new Error('Offline') } as unknown as ReturnType<typeof useHealthSummary>);
  act(() => renderer.update(<HealthProfileScreen />));
  expect(root.findAllByType(Text).some(node => node.props.children === 'Alex')).toBe(true);
  jest.mocked(useHealthSummary).mockReturnValue({ data, isError: true, error: new ApiError(403, { code: 'denied', message: 'Unavailable' }) } as unknown as ReturnType<typeof useHealthSummary>);
  act(() => renderer.update(<HealthProfileScreen />));
  expect(root.findAllByType(Text).some(node => node.props.children === 'Alex')).toBe(false);
});

it.each(['loading', 'error', 'loaded'])('keeps the health profile header fixed in the %s branch', state => {
  jest.mocked(useHealthSummary).mockReturnValue({
    isLoading: state === 'loading',
    data: state === 'loaded' ? { data: {
      person: { name: 'Alex', kind: 'student', identifier: '001' },
      health: {},
    } } : undefined,
  } as ReturnType<typeof useHealthSummary>);
  const root = render(<HealthProfileScreen />);
  expect(root.findByType(Screen).props.header.props.edgeToEdge).toBe(true);
  expect(root.findByType(PageHeader).props.title).toBe('Health profile');
  root.findAllByType(ScrollView).forEach(viewport => expect(viewport.findAllByType(PageHeader)).toHaveLength(0));
});
