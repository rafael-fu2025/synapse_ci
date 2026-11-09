import React from 'react';
import { AccessibilityInfo, Animated, Keyboard, KeyboardAvoidingView, Modal, Platform, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { Action } from '../src/components/Action';
import { BottomSheet } from '../src/components/BottomSheet';
import { ConfirmDialog } from '../src/components/ConfirmDialog';
import { OverlayProvider } from '../src/components/OverlayProvider';
import { BootstrapErrorScreen } from '../src/features/auth/screens/BootstrapErrorScreen';
import { SessionExpiredScreen } from '../src/features/auth/screens/SessionExpiredScreen';
import { SignInScreen } from '../src/features/auth/screens/SignInScreen';
import { SplashScreen } from '../src/features/auth/screens/SplashScreen';
import { ConnectionContext, ConnectionContextValue } from '../src/features/connection/ConnectionContext';
import { CONNECTION_PROFILES } from '../src/features/connection/profiles';
import { ServerConnectionControl } from '../src/features/connection/ServerConnectionControl';
import { ServerProfileChoices } from '../src/features/connection/ServerProfileChoices';
import { SettingsScreen } from '../src/features/profile/screens/SettingsScreen';
import { WorkspaceProvider, useWorkspace } from '../src/features/workspace/WorkspaceContext';
import { WorkspaceDiscardDialog } from '../src/features/workspace/WorkspaceDiscardDialog';
import { useKeyboardVisible } from '../src/lib/useKeyboardVisible';
import { __resetPushSetupStatusForTests, publishPushSetupStatus } from '../src/lib/push/setupStatus';
import { useAuth } from '../src/store/auth';
import { useTheme } from '../src/theme/ThemeProvider';

jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/api/client', () => ({ ApiError: class ApiError extends Error {}, NetworkError: class NetworkError extends Error {} }));
jest.mock('../src/theme/ThemeProvider', () => ({
  useTheme: jest.fn(() => ({ theme: jest.requireActual('../src/theme/tokens')[mockDark ? 'darkTheme' : 'lightTheme'], setPreference: jest.fn() })),
}));
jest.mock('../src/lib/useKeyboardVisible', () => ({ useKeyboardVisible: jest.fn(() => false) }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View, useSafeAreaInsets: () => ({ top: 24, right: 0, bottom: 20, left: 0 }) }));
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));
jest.mock('react-native-linear-gradient', () => jest.requireActual('react-native').View);
jest.mock('@react-navigation/native', () => ({ useNavigation: () => ({ goBack: jest.fn(), navigate: jest.fn() }) }));
jest.mock('../src/features/auth/components/LoginCarousel', () => ({ LoginCarousel: () => null }));

let mockDark = false;
let renderer: ReactTestRenderer | undefined;
let connection: ConnectionContextValue;
let content: React.ReactElement;
let withWorkspace = false;
let workspace: ReturnType<typeof useWorkspace>;
const selectProfile = jest.fn<Promise<void>, ['university' | 'home']>();
const signIn = jest.fn<Promise<void>, [string, string]>();
const retryBootstrap = jest.fn<Promise<void>, []>();
const signOut = jest.fn<Promise<void>, []>();
const motionListeners = new Set<(reduced: boolean) => void>();

function WorkspaceProbe() { workspace = useWorkspace(); return null; }
function tree() {
  const inner = withWorkspace
    ? <WorkspaceProvider><OverlayProvider><WorkspaceProbe /><WorkspaceDiscardDialog />{content}</OverlayProvider></WorkspaceProvider>
    : <OverlayProvider>{content}</OverlayProvider>;
  return <ConnectionContext.Provider value={connection}>{inner}</ConnectionContext.Provider>;
}
async function mount(node = <ServerConnectionControl />, protectDrafts = false) {
  content = node;
  withWorkspace = protectDrafts;
  await act(async () => { renderer = create(tree()); });
}
function refresh(update: Partial<ConnectionContextValue> = {}) {
  connection = { ...connection, ...update };
  act(() => renderer!.update(tree()));
}
function actions(label: string) { return renderer!.root.findAllByType(Action).filter(node => node.props.children === label); }
function press(label: string) { act(() => { actions(label)[0].props.onPress(); }); }
function change() { press('Change'); }
function radio(id: string) { return renderer!.root.findAll(node => node.props.testID === `server-profile-${id}`).find(node => typeof node.props.style === 'function')!; }
function chooseHome() { change(); act(() => radio('home').props.onPress()); }
function visibleModals() { return renderer!.root.findAllByType(Modal).filter(node => node.props.visible); }
function dismissNative() { const callback = renderer!.root.findAllByType(Modal).find(node => !node.props.visible)!.props.onDismiss; act(() => { callback(); callback(); }); }
function hasText(value: string) { return renderer!.root.findAllByType(Text).some(node => node.props.children === value); }
function toConfirmation() { chooseHome(); press('Continue'); dismissNative(); }
function confirmSwitch() { press('Switch server'); dismissNative(); }

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  jest.replaceProperty(Platform, 'OS', 'ios');
  mockDark = false;
  motionListeners.clear();
  __resetPushSetupStatusForTests();
  selectProfile.mockReset().mockImplementation(() => new Promise<void>(() => undefined));
  signIn.mockReset().mockResolvedValue(undefined);
  retryBootstrap.mockReset().mockResolvedValue(undefined);
  signOut.mockReset().mockResolvedValue(undefined);
  jest.mocked(useTheme).mockClear();
  jest.mocked(useKeyboardVisible).mockReturnValue(false);
  jest.spyOn(Keyboard, 'isVisible').mockReturnValue(false);
  jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockImplementation(() => new Promise<boolean>(() => undefined));
  jest.spyOn(AccessibilityInfo, 'addEventListener').mockImplementation((event, listener) => {
    if (event === 'reduceMotionChanged') { motionListeners.add(listener as (value: boolean) => void); }
    return { remove: () => { motionListeners.delete(listener as (value: boolean) => void); } };
  });
  jest.spyOn(require('react-native'), 'useWindowDimensions').mockReturnValue({ width: 390, height: 844, scale: 3, fontScale: 1 });
  connection = { enabled: true, owner: 11, profile: CONNECTION_PROFILES[0], switching: false, error: null, selectProfile };
  jest.mocked(useAuth).mockReturnValue({
    status: 'signedIn',
    profile: { user: { id: 7, tenant_id: 3 }, entitlements: { personal: { enabled: true }, work: { enabled: true, units: [{ unit: 'clinic', label: 'Clinic', features: { team_chat: true } }] } } },
    signIn, retryBootstrap, signOut, acknowledgeExpired: jest.fn(),
  } as unknown as ReturnType<typeof useAuth>);
});
afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  __resetPushSetupStatusForTests();
  jest.runOnlyPendingTimers();
  jest.restoreAllMocks();
  jest.useRealTimers();
});

it('mounts no extra UI or inner hooks without a connection context, disabled LAN, or an initial profile', async () => {
  await act(async () => { renderer = create(<ServerConnectionControl />); });
  expect(renderer!.toJSON()).toBeNull();
  expect(useTheme).not.toHaveBeenCalled();
  act(() => renderer!.unmount()); renderer = undefined;
  connection.enabled = false;
  await mount();
  expect(renderer!.toJSON()).toBeNull();
  expect(useTheme).not.toHaveBeenCalled();
  refresh({ enabled: true, profile: null });
  expect(renderer!.toJSON()).toBeNull();
  expect(selectProfile).not.toHaveBeenCalled();
});

it('keeps a provisional accessible choice and treats the active server as a no-op', async () => {
  await mount();
  expect(hasText(CONNECTION_PROFILES[0].apiBaseUrl)).toBe(true);
  change();
  expect(radio('university').props.accessibilityState).toMatchObject({ checked: true });
  expect(radio('home').props.accessibilityState).toMatchObject({ checked: false });
  expect(radio('home').props.accessibilityLabel).toContain(CONNECTION_PROFILES[1].apiBaseUrl);
  expect(actions('Continue')[0].props.disabled).toBe(true);
  press('Continue');
  expect(visibleModals()).toHaveLength(1);
  expect(selectProfile).not.toHaveBeenCalled();
  act(() => radio('home').props.onPress());
  expect(radio('home').props.accessibilityState.checked).toBe(true);
  expect(radio('university').props.accessibilityState.checked).toBe(false);
  expect(hasText(`Server · ${CONNECTION_PROFILES[0].label}`)).toBe(true);
  expect(selectProfile).not.toHaveBeenCalled();
  act(() => radio('university').props.onPress());
  expect(actions('Continue')[0].props.disabled).toBe(true);
  press('Cancel'); dismissNative();
  expect(visibleModals()).toHaveLength(0);
  expect(selectProfile).not.toHaveBeenCalled();
});

it.each(['ios', 'android'] as const)('waits real chooser and confirmation dismissal before selecting exactly once on %s', async os => {
  jest.replaceProperty(Platform, 'OS', os);
  await mount(<ServerConnectionControl requiresSignIn />);
  chooseHome();
  const chooser = visibleModals()[0].props.onDismiss;
  const apply = actions('Continue')[0].props.onPress;
  act(() => { apply(); apply(); });
  expect(renderer!.root.findByType(ConfirmDialog).props.visible).toBe(false);
  expect(selectProfile).not.toHaveBeenCalled();
  if (os === 'ios') {
    act(() => jest.advanceTimersByTime(200));
    expect(renderer!.root.findByType(ConfirmDialog).props.visible).toBe(false);
    act(() => { chooser(); chooser(); });
  } else { act(() => jest.advanceTimersByTime(40)); }
  expect(renderer!.root.findByType(ConfirmDialog).props.visible).toBe(true);
  expect(renderer!.root.findByType(ConfirmDialog).props.message).toContain('need to sign in again');
  expect(renderer!.root.findByType(ConfirmDialog).props.message).toContain(CONNECTION_PROFILES[1].apiBaseUrl);
  const dialog = visibleModals()[0].props.onDismiss;
  const accept = actions('Switch server')[0].props.onPress;
  act(() => { accept(); accept(); chooser(); });
  expect(selectProfile).not.toHaveBeenCalled();
  if (os === 'ios') { act(() => { dialog(); dialog(); }); }
  else { act(() => jest.advanceTimersByTime(40)); }
  expect(selectProfile).toHaveBeenCalledTimes(1);
  expect(selectProfile).toHaveBeenCalledWith('home');
  act(() => { accept(); dialog(); chooser(); apply(); });
  expect(selectProfile).toHaveBeenCalledTimes(1);
});

it('protects all retained personal/workspace drafts and selects only after discard dialog dismissal', async () => {
  await mount(<ServerConnectionControl requiresSignIn />, true);
  const discardPersonal = jest.fn(); const discardChat = jest.fn();
  act(() => {
    workspace.registerDirtyDraft('personal', { scope: { context: 'personal' }, onDiscard: discardPersonal });
    workspace.registerDirtyDraft('chat', { scope: { context: 'workspace', unit: 'clinic' }, onDiscard: discardChat });
  });
  const requestDiscard = jest.spyOn(workspace, 'requestDiscard');
  toConfirmation();
  expect(requestDiscard).not.toHaveBeenCalled();
  press('Switch server');
  expect(requestDiscard).not.toHaveBeenCalled();
  dismissNative();
  expect(requestDiscard).toHaveBeenCalledTimes(1);
  expect(requestDiscard.mock.calls[0]).toHaveLength(1);
  expect(hasText('Discard unsaved changes?')).toBe(true);
  expect(selectProfile).not.toHaveBeenCalled();
  expect(discardPersonal).not.toHaveBeenCalled(); expect(discardChat).not.toHaveBeenCalled();
  press('Discard and continue');
  expect(discardPersonal).toHaveBeenCalledTimes(1); expect(discardChat).toHaveBeenCalledTimes(1);
  expect(selectProfile).not.toHaveBeenCalled();
  dismissNative();
  expect(selectProfile).toHaveBeenCalledTimes(1);
  expect(signOut).not.toHaveBeenCalled();
});

it('keeps drafts/account on discard cancellation and rejects that canceled continuation after a new choice', async () => {
  await mount(<ServerConnectionControl requiresSignIn />, true);
  const discard = jest.fn();
  act(() => { workspace.registerDirtyDraft('retained-chat', { onDiscard: discard }); });
  const requestDiscard = jest.spyOn(workspace, 'requestDiscard');
  toConfirmation(); confirmSwitch();
  const oldContinuation = requestDiscard.mock.calls[0][0];
  press('Keep editing');
  expect(selectProfile).not.toHaveBeenCalled();
  expect(actions('Change')[0].props.disabled).toBe(true);
  dismissNative();
  expect(workspace.hasDirtyDrafts).toBe(true);
  expect(discard).not.toHaveBeenCalled();
  expect(signOut).not.toHaveBeenCalled();
  change();
  act(() => oldContinuation());
  expect(selectProfile).not.toHaveBeenCalled();
  expect(renderer!.root.findByType(BottomSheet).props.visible).toBe(true);
});

it('blocks all protected submission scopes including stale chooser handlers, then permits a fresh choice after completion', async () => {
  await mount(<ServerConnectionControl requiresSignIn />, true);
  chooseHome();
  const apply = actions('Continue')[0].props.onPress;
  let unlock!: () => void;
  act(() => { unlock = workspace.registerSubmission('retained-work-write', { scope: { context: 'workspace', unit: 'clinic' } }); });
  expect(actions('Continue')[0].props.disabled).toBe(true);
  act(() => { apply(); apply(); });
  expect(renderer!.root.findByType(BottomSheet).props.visible).toBe(true);
  expect(selectProfile).not.toHaveBeenCalled();
  press('Cancel'); dismissNative();
  expect(actions('Change')[0].props.disabled).toBe(true);
  change();
  expect(visibleModals()).toHaveLength(0);
  act(() => unlock());
  toConfirmation(); confirmSwitch();
  expect(selectProfile).toHaveBeenCalledTimes(1);
});

it.each(['chooser', 'confirmation', 'continuation'] as const)('rejects owner/source-stale callbacks while waiting at %s', async stage => {
  await mount(<ServerConnectionControl requiresSignIn />, stage === 'continuation');
  let deferred: (() => void) | undefined;
  if (stage === 'continuation') { act(() => { workspace.registerDirtyDraft('editor'); }); }
  chooseHome();
  if (stage === 'chooser') {
    press('Continue');
    deferred = renderer!.root.findAllByType(Modal)[0].props.onDismiss;
  } else {
    press('Continue'); dismissNative();
    if (stage === 'confirmation') {
      press('Switch server');
      deferred = renderer!.root.findAllByType(Modal)[0].props.onDismiss;
    } else {
      const discard = jest.spyOn(workspace, 'requestDiscard');
      confirmSwitch();
      deferred = discard.mock.calls[0][0];
    }
  }
  refresh({ owner: 12, profile: CONNECTION_PROFILES[1] });
  act(() => { deferred?.(); deferred?.(); });
  expect(selectProfile).not.toHaveBeenCalled();
  expect(hasText(`Server · ${CONNECTION_PROFILES[1].label}`)).toBe(true);
});

it('does not let callbacks from a canceled presentation apply to a new selection or an unmounted control', async () => {
  await mount();
  chooseHome();
  const oldApply = actions('Continue')[0].props.onPress;
  const oldDismiss = visibleModals()[0].props.onDismiss;
  press('Cancel'); dismissNative();
  chooseHome();
  act(() => { oldApply(); oldDismiss(); });
  expect(renderer!.root.findByType(BottomSheet).props.visible).toBe(true);
  expect(renderer!.root.findByType(ConfirmDialog).props.visible).toBe(false);
  const apply = actions('Continue')[0].props.onPress;
  act(() => renderer!.unmount()); renderer = undefined;
  act(() => { apply(); oldApply(); oldDismiss(); });
  expect(selectProfile).not.toHaveBeenCalled();
});

it('surfaces only the setup warning for the current owner and hides warnings in disabled mode', async () => {
  await mount();
  const warning = 'Notifications are unavailable on this server. Your other features are still available.';
  act(() => publishPushSetupStatus({ state: 'warning', warning, code: 'push_unavailable', runtimeId: 10 }));
  expect(hasText(warning)).toBe(false);
  act(() => publishPushSetupStatus({ state: 'warning', warning, code: 'push_unavailable', runtimeId: 11 }));
  expect(hasText(warning)).toBe(true);
  expect(hasText('push_unavailable')).toBe(false);
  refresh({ owner: 12 });
  expect(hasText(warning)).toBe(false);
  refresh({ enabled: false });
  expect(renderer!.toJSON()).toBeNull();
});

it.each(['ios', 'android'] as const)('wraps radio addresses with 44-point targets, dark theme, large fonts, and keyboard-safe sheet layout on %s', async os => {
  jest.replaceProperty(Platform, 'OS', os);
  mockDark = true;
  jest.spyOn(require('react-native'), 'useWindowDimensions').mockReturnValue({ width: 320, height: 640, scale: 2, fontScale: 2 });
  jest.mocked(useKeyboardVisible).mockReturnValue(true);
  await mount(); chooseHome();
  const styles = StyleSheet.flatten(radio('home').props.style({ pressed: false }));
  expect(styles.minHeight).toBeGreaterThanOrEqual(44);
  const addresses = renderer!.root.findByType(ServerProfileChoices).findAllByType(Text).filter(node => CONNECTION_PROFILES.some(profile => profile.apiBaseUrl === node.props.children));
  expect(addresses).toHaveLength(2);
  addresses.forEach(node => { expect(node.props.numberOfLines).toBeUndefined(); expect(node.props.ellipsizeMode).toBeUndefined(); expect(StyleSheet.flatten(node.props.style).flexShrink).toBe(1); });
  const row = renderer!.root.findAllByType(View).find(node => StyleSheet.flatten(node.props.style)?.minHeight === 61)!;
  expect(StyleSheet.flatten(row.props.style).flexDirection).toBe('column');
  expect(renderer!.root.findByType(KeyboardAvoidingView).props.enabled).toBe(os === 'ios');
  const scroll = renderer!.root.findByType(ScrollView);
  expect(scroll.props.keyboardShouldPersistTaps).toBe('handled');
  expect(scroll.props.automaticallyAdjustKeyboardInsets).toBe(false);
  expect(scroll.findAllByType(Action)).toHaveLength(0);
  const sheet = renderer!.root.findAllByType(Animated.View).find(node => StyleSheet.flatten(node.props.style)?.borderTopLeftRadius === 22)!;
  expect(StyleSheet.flatten(sheet.props.style).paddingBottom).toBe(8);
  expect(StyleSheet.flatten(sheet.props.style).backgroundColor).toBe(jest.requireActual('../src/theme/tokens').darkTheme.surface);
});

it('reacts to live reduced-motion changes without moving hit targets or enabling a premature dismissal', async () => {
  await mount();
  const timing = jest.spyOn(Animated, 'timing');
  change();
  act(() => { for (const listener of motionListeners) { listener(false); } });
  const button = radio('home');
  const before = StyleSheet.flatten(button.props.style({ pressed: false }));
  act(() => button.props.onPressIn());
  expect(timing).toHaveBeenCalledWith(expect.anything(), expect.objectContaining({ useNativeDriver: true, isInteraction: false }));
  act(() => { for (const listener of motionListeners) { listener(true); } });
  timing.mockClear();
  act(() => button.props.onPressOut());
  expect(timing).not.toHaveBeenCalled();
  expect(StyleSheet.flatten(button.props.style({ pressed: false })).minHeight).toBe(before.minHeight);
  press('Cancel');
  expect(actions('Change')[0].props.disabled).toBe(true);
  dismissNative();
  expect(actions('Change')[0].props.disabled).toBe(false);
  expect(selectProfile).not.toHaveBeenCalled();
});

it.each([SettingsScreen, SignInScreen, BootstrapErrorScreen, SessionExpiredScreen, SplashScreen])('makes %p connection selection reachable offline without automatically retrying/authenticating', async Component => {
  await mount(<Component />);
  expect(hasText('Server · University')).toBe(true);
  toConfirmation();
  expect(selectProfile).not.toHaveBeenCalled();
  expect(signIn).not.toHaveBeenCalled(); expect(retryBootstrap).not.toHaveBeenCalled(); expect(signOut).not.toHaveBeenCalled();
  press('Keep current server'); dismissNative();
  expect(selectProfile).not.toHaveBeenCalled();
});

it('preserves the existing screen controls and layout when LAN context is disabled', async () => {
  connection.enabled = false;
  await mount(<SignInScreen />);
  expect(actions('Sign in')).toHaveLength(1);
  expect(actions('Change')).toHaveLength(0);
  expect(renderer!.root.findAllByType(TextInput)).toHaveLength(2);
  content = <SplashScreen />;
  refresh();
  expect(renderer!.root.findAllByType(ScrollView)).toHaveLength(0);
  expect(renderer!.root.findAllByType(Modal)).toHaveLength(0);
  content = <SettingsScreen />;
  refresh();
  expect(hasText('CONNECTION')).toBe(false);
  expect(hasText('PREFERENCES')).toBe(true); expect(hasText('PRIVACY')).toBe(true);
  expect(renderer!.root.findAllByType(ConfirmDialog)).toHaveLength(1);
});

it('uses real native dismissal without an OverlayProvider in standalone fixtures', async () => {
  await act(async () => { renderer = create(<ConnectionContext.Provider value={connection}><ServerConnectionControl /></ConnectionContext.Provider>); });
  toConfirmation();
  press('Keep current server'); dismissNative();
  expect(selectProfile).not.toHaveBeenCalled();
  toConfirmation(); confirmSwitch();
  expect(selectProfile).toHaveBeenCalledTimes(1);
});

it('retains credential fields across confirmation cancellation and native Back dismissal', async () => {
  await mount(<SignInScreen />);
  const original = renderer!.root.findAllByType(TextInput);
  act(() => { original[0].props.onChangeText('A retained ID'); original[1].props.onChangeText('A retained password'); });
  toConfirmation();
  const cancel = renderer!.root.findByType(ConfirmDialog).props.onCancel;
  act(() => { visibleModals()[0].props.onRequestClose(); cancel(); });
  dismissNative();
  expect(renderer!.root.findAllByType(TextInput)[0]).toBe(original[0]);
  expect(original[0].props.value).toBe('A retained ID'); expect(original[1].props.value).toBe('A retained password');
  expect(selectProfile).not.toHaveBeenCalled(); expect(signOut).not.toHaveBeenCalled();
});

it('waits for unrelated overlay dismissal after the switch confirmation has dismissed', async () => {
  let extraVisible = false;
  const extra = () => <ConfirmDialog visible={extraVisible} title="Other dialog" message="Another overlay" confirmLabel="Close other" onConfirm={() => { extraVisible = false; content = all(); refresh(); }} onCancel={() => undefined} />;
  const all = () => <><ServerConnectionControl requiresSignIn />{extra()}</>;
  await mount(all());
  toConfirmation();
  extraVisible = true; content = all(); refresh();
  press('Switch server'); dismissNative();
  expect(hasText('Other dialog')).toBe(true);
  expect(selectProfile).not.toHaveBeenCalled();
  press('Close other');
  expect(selectProfile).not.toHaveBeenCalled();
  dismissNative();
  expect(selectProfile).toHaveBeenCalledTimes(1);
});

it('releases a failed selection for explicit retry without displaying the rejected error', async () => {
  let reject!: (error: Error) => void;
  selectProfile.mockImplementationOnce(() => new Promise<void>((_, rejectPromise) => { reject = rejectPromise; }));
  await mount(); toConfirmation(); confirmSwitch();
  expect(actions('Change')[0].props.disabled).toBe(true);
  await act(async () => reject(new Error('raw backend secret details')));
  expect(hasText('Could not change the server. Please try again.')).toBe(true);
  expect(hasText('raw backend secret details')).toBe(false);
  expect(actions('Change')[0].props.disabled).toBe(false);
  toConfirmation(); confirmSwitch();
  expect(selectProfile).toHaveBeenCalledTimes(2);
});

it('invalidates deferred changes when the active profile changes even if an owner number is reused', async () => {
  await mount(); toConfirmation();
  const accept = actions('Switch server')[0].props.onPress;
  refresh({ profile: CONNECTION_PROFILES[1] });
  act(() => accept());
  expect(selectProfile).not.toHaveBeenCalled();
});

it('preserves sign-in fields and allows changing server during a pending sign-in', async () => {
  signIn.mockImplementation(() => new Promise<void>(() => undefined));
  await mount(<SignInScreen />);
  act(() => {
    const inputs = renderer!.root.findAllByType(TextInput);
    inputs[0].props.onChangeText('Keep identifier'); inputs[1].props.onChangeText('Keep password');
  });
  press('Sign in');
  expect(actions('Sign in')[0].props.loading).toBe(true);
  expect(actions('Change')[0].props.disabled).toBe(false);
  chooseHome(); press('Cancel'); dismissNative();
  const inputs = renderer!.root.findAllByType(TextInput);
  expect(inputs[0].props.value).toBe('Keep identifier'); expect(inputs[1].props.value).toBe('Keep password');
  expect(signIn).toHaveBeenCalledTimes(1);
  expect(signOut).not.toHaveBeenCalled();
  toConfirmation(); confirmSwitch();
  expect(selectProfile).toHaveBeenCalledWith('home');
});
