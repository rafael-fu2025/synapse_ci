import React from 'react';
import { AppState, Platform, StyleSheet, Text, View } from 'react-native';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { useApiClient, type ApiClient } from '../src/api/runtime';
import { Action } from '../src/components/Action';
import { PhoneNotificationControl } from '../src/components/PhoneNotificationControl';
import { openPushSettings, type PushPermission } from '../src/lib/push/fcm';
import { enableNotificationPermission, refreshNotificationPermission, useNotificationPermission, type NotificationPermissionStatus } from '../src/lib/push/permission';
import { retryPushSetup } from '../src/lib/push/registration';
import { usePushSetupStatus, type PushSetupStatus } from '../src/lib/push/setupStatus';
import { useAuth, type AuthContextValue } from '../src/store/auth';
import { useTheme } from '../src/theme/ThemeProvider';
import { darkTheme, lightTheme } from '../src/theme/tokens';

// Every account, runtime, permission, registration, and native boundary is synthetic.
// Importing this suite must not initialize Firebase, keychain, connection config, or HTTP.
jest.mock('react-native/Libraries/AppState/AppState', () => ({
  __esModule: true, default: { currentState: 'active', addEventListener: jest.fn(() => ({ remove: jest.fn() })) },
}));
jest.mock('../src/api/runtime', () => ({ useApiClient: jest.fn() }));
jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: jest.fn() }));
jest.mock('../src/lib/push/fcm', () => ({ openPushSettings: jest.fn() }));
jest.mock('../src/lib/push/permission', () => ({
  enableNotificationPermission: jest.fn(), refreshNotificationPermission: jest.fn(), useNotificationPermission: jest.fn(),
}));
jest.mock('../src/lib/push/registration', () => ({ retryPushSetup: jest.fn() }));
jest.mock('../src/lib/push/setupStatus', () => ({ usePushSetupStatus: jest.fn() }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: () => true }));

let renderer: ReactTestRenderer | undefined;
let auth: AuthContextValue;
let client: ApiClient;
let permission: NotificationPermissionStatus;
let setup: PushSetupStatus;

function makeClient(id = 11, lanTest = false): ApiClient {
  return {
    runtime: {
      id, apiBaseUrl: `https://server-${id}.example.test`, profileId: lanTest ? 'home' : null,
      credentialBinding: null, lanTest, active: true, sessionVersion: 1,
    },
    sessionVersion: 1,
    isCurrent: jest.fn(() => true), assertCurrent: jest.fn(), request: jest.fn(), authorizedFetch: jest.fn(), run: jest.fn(),
  };
}
function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
async function mount() { await act(async () => { renderer = create(<PhoneNotificationControl />); }); }
async function refresh() { await act(async () => { renderer!.update(<PhoneNotificationControl />); }); }
function action() { return renderer!.root.findByType(Action); }
function button() {
  return action().findAll(node => node.props.accessibilityRole === 'button' && typeof node.props.style === 'function')[0];
}
async function press() { await act(async () => { action().props.onPress(); }); }
function texts() { return renderer!.root.findAllByType(Text).map(node => node.props.children).join('\n'); }
function alerts() { return renderer!.root.findAllByType(Text).filter(node => node.props.accessibilityRole === 'alert'); }

async function changeOwner(change: 'signout' | 'server switch' | 'retired runtime' | 'unmount') {
  if (change === 'unmount') {
    await act(async () => { renderer!.unmount(); });
    renderer = undefined;
    return;
  }
  if (change === 'signout') { auth = { ...auth, status: 'signedOut', user: null }; }
  if (change === 'server switch') { client = makeClient(22, true); }
  if (change === 'retired runtime') { jest.mocked(client.isCurrent).mockReturnValue(false); }
  await refresh();
}

beforeEach(() => {
  jest.resetAllMocks();
  jest.replaceProperty(Platform, 'OS', 'android');
  jest.replaceProperty(AppState, 'currentState', 'active');
  client = makeClient();
  auth = {
    status: 'signedIn', user: { id: 7, tenant_id: 3, username: 'synthetic-user' } as NonNullable<AuthContextValue['user']>,
    profile: null, signIn: jest.fn(), retryBootstrap: jest.fn(), refreshProfile: jest.fn(), signOut: jest.fn(), acknowledgeExpired: jest.fn(),
  };
  permission = { permission: 'denied', requesting: false, systemEnabled: null };
  setup = { state: 'idle', warning: null, code: null, runtimeId: client.runtime.id };
  jest.mocked(useAuth).mockImplementation(() => auth);
  jest.mocked(useApiClient).mockImplementation(() => client);
  jest.mocked(useNotificationPermission).mockImplementation(() => permission);
  jest.mocked(usePushSetupStatus).mockImplementation(() => setup);
  jest.mocked(enableNotificationPermission).mockResolvedValue('granted');
  jest.mocked(refreshNotificationPermission).mockImplementation(async () => permission.permission === 'unknown' ? 'denied' : permission.permission);
  jest.mocked(openPushSettings).mockResolvedValue(true);
  jest.mocked(retryPushSetup).mockResolvedValue(undefined);
});
afterEach(async () => {
  await act(async () => { renderer?.unmount(); });
  renderer = undefined;
  jest.restoreAllMocks();
});

const permissionCases: Array<{
  name: string; permission: NotificationPermissionStatus['permission']; systemEnabled: boolean | null; label: string;
}> = [
  { name: 'unknown permission', permission: 'unknown', systemEnabled: null, label: 'Enable notifications' },
  { name: 'denied permission', permission: 'denied', systemEnabled: true, label: 'Enable notifications' },
  { name: 'fresh denial also reports system disabled', permission: 'denied', systemEnabled: false, label: 'Enable notifications' },
  { name: 'blocked permission', permission: 'blocked', systemEnabled: false, label: 'Notification settings' },
  { name: 'granted permission', permission: 'granted', systemEnabled: true, label: 'Retry notification setup' },
  { name: 'granted permission with disabled channel', permission: 'granted', systemEnabled: false, label: 'Notification settings' },
  { name: 'unavailable permission bridge', permission: 'unavailable', systemEnabled: null, label: 'Enable notifications' },
];

describe.each(['light', 'dark'] as const)('PhoneNotificationControl in %s mode', mode => {
  const theme = mode === 'dark' ? darkTheme : lightTheme;
  beforeEach(() => {
    jest.mocked(useTheme).mockReturnValue({ theme, mode, preference: mode, setPreference: jest.fn() });
  });

  it.each(permissionCases)('renders an accessible themed action for $name without requesting automatically', async state => {
    permission = { permission: state.permission, systemEnabled: state.systemEnabled, requesting: false };
    await mount();
    expect(texts()).toContain('Phone notifications');
    expect(action().props.children).toBe(state.label);
    expect(button().props.accessibilityRole).toBe('button');
    expect(button().props.accessibilityLabel).toBe(state.label);
    expect(button().props.accessibilityState).toEqual({ disabled: false, busy: false });
    const group = renderer!.root.findAllByType(View).find(node => node.props.testID === 'phone-notification-control')!;
    expect(StyleSheet.flatten(group.props.style)).toMatchObject({ backgroundColor: theme.surface, borderColor: theme.line });
    const title = renderer!.root.findAllByType(Text).find(node => node.props.children === 'Phone notifications')!;
    expect(StyleSheet.flatten(title.props.style).color).toBe(theme.ink900);
    expect(StyleSheet.flatten(button().props.style({ pressed: false })).minHeight).toBeGreaterThanOrEqual(44);
    expect(refreshNotificationPermission).toHaveBeenCalledTimes(1);
    expect(enableNotificationPermission).not.toHaveBeenCalled();
    expect(openPushSettings).not.toHaveBeenCalled();
    expect(retryPushSetup).not.toHaveBeenCalled();
  });

  it.each([false, true])('explicitly requests after fresh denial and retries setup for the captured account/runtime (LAN=%s), coalescing taps', async lanTest => {
    client = makeClient(11, lanTest);
    permission = { permission: 'denied', systemEnabled: false, requesting: false };
    const allowed = deferred<PushPermission>();
    const registered = deferred<void>();
    jest.mocked(enableNotificationPermission).mockReturnValue(allowed.promise);
    jest.mocked(retryPushSetup).mockReturnValue(registered.promise);
    await mount();
    const capturedClient = client;
    const capturedUser = auth.user;
    const activate = action().props.onPress;
    await act(async () => { activate(); activate(); });
    expect(action().props.children).toBe('Enable notifications');
    expect(enableNotificationPermission).toHaveBeenCalledTimes(1);
    const isActive = jest.mocked(enableNotificationPermission).mock.calls[0][0];
    expect(isActive()).toBe(true);
    expect(openPushSettings).not.toHaveBeenCalled();
    expect(retryPushSetup).not.toHaveBeenCalled();
    expect(button().props.accessibilityState).toEqual({ disabled: true, busy: true });
    expect(button().props.onPress).toBeUndefined();

    await act(async () => { allowed.resolve('granted'); });
    expect(retryPushSetup).toHaveBeenCalledTimes(1);
    const [runtime, user, isCurrent] = jest.mocked(retryPushSetup).mock.calls[0];
    expect(retryPushSetup).toHaveBeenCalledWith(capturedClient.runtime, capturedUser, expect.any(Function));
    expect(runtime).toBe(capturedClient.runtime);
    expect(user).toBe(capturedUser);
    expect(isCurrent()).toBe(true);
    await act(async () => { activate(); activate(); });
    expect(enableNotificationPermission).toHaveBeenCalledTimes(1);
    expect(retryPushSetup).toHaveBeenCalledTimes(1);
    await act(async () => { registered.resolve(undefined); });
    expect(button().props.accessibilityState).toEqual({ disabled: false, busy: false });
    expect(client.request).not.toHaveBeenCalled();
  });

  it('uses a check-only permission refresh for retry when already granted', async () => {
    permission = { permission: 'granted', systemEnabled: true, requesting: false };
    await mount();
    expect(action().props.children).toBe('Retry notification setup');
    await press();
    expect(refreshNotificationPermission).toHaveBeenCalledTimes(2); // Mount, then deliberate retry.
    expect(enableNotificationPermission).not.toHaveBeenCalled();
    expect(openPushSettings).not.toHaveBeenCalled();
    expect(retryPushSetup).toHaveBeenCalledTimes(1);
    expect(retryPushSetup).toHaveBeenCalledWith(client.runtime, auth.user, expect.any(Function));
  });

  it.each(['blocked', 'granted'] as const)('opens settings directly for %s permission with disabled system notifications', async value => {
    permission = { permission: value, systemEnabled: false, requesting: false };
    const opened = deferred<boolean>();
    jest.mocked(openPushSettings).mockReturnValue(opened.promise);
    await mount();
    expect(texts()).toContain('Allow SYNAPSE notifications and its notification channel in Android settings.');
    const activate = action().props.onPress;
    await act(async () => { activate(); activate(); });
    expect(openPushSettings).toHaveBeenCalledTimes(1);
    expect(button().props.accessibilityState).toEqual({ disabled: true, busy: true });
    expect(enableNotificationPermission).not.toHaveBeenCalled();
    expect(retryPushSetup).not.toHaveBeenCalled();
    await act(async () => { opened.resolve(true); });
    expect(button().props.accessibilityState).toEqual({ disabled: false, busy: false });
    expect(alerts()).toHaveLength(0);
  });

  it.each(['denied', 'blocked'] as const)('offers settings after an explicit request remains %s and never registers', async result => {
    jest.mocked(enableNotificationPermission).mockResolvedValue(result);
    await mount();
    await press();
    expect(enableNotificationPermission).toHaveBeenCalledTimes(1);
    expect(openPushSettings).toHaveBeenCalledTimes(1);
    expect(jest.mocked(enableNotificationPermission).mock.invocationCallOrder[0]).toBeLessThan(jest.mocked(openPushSettings).mock.invocationCallOrder[0]);
    expect(retryPushSetup).not.toHaveBeenCalled();
    await refresh();
    expect(enableNotificationPermission).toHaveBeenCalledTimes(1);
  });

  it('describes ready as registration, not proof of delivery, and keeps the settings action', async () => {
    permission = { permission: 'granted', systemEnabled: true, requesting: false };
    setup = { ...setup, state: 'ready' };
    await mount();
    expect(texts()).toContain('This phone is registered for supported updates. Delivery also depends on the server and connection.');
    expect(texts()).not.toMatch(/guaranteed|delivered|will receive|delivery confirmed/i);
    expect(action().props.children).toBe('Notification settings');
    await press();
    expect(openPushSettings).toHaveBeenCalledTimes(1);
    expect(enableNotificationPermission).not.toHaveBeenCalled();
    expect(retryPushSetup).not.toHaveBeenCalled();
  });

  it.each(['ready', 'warning', 'checking'] as const)('ignores %s setup state belonging to an old server', async state => {
    permission = { permission: 'granted', systemEnabled: true, requesting: false };
    setup = { state, warning: 'Synthetic old-server warning.', code: 'old_server_code', runtimeId: 99 };
    await mount();
    expect(action().props.children).toBe('Retry notification setup');
    expect(button().props.accessibilityState).toEqual({ disabled: false, busy: false });
    expect(texts()).not.toContain('Synthetic old-server warning.');
    expect(texts()).not.toContain('old_server_code');
    expect(texts()).not.toContain('This phone is registered');
    await press();
    expect(retryPushSetup).toHaveBeenCalledTimes(1);
    expect(jest.mocked(retryPushSetup).mock.calls[0][0]).toBe(client.runtime);
  });

  it('shows only the current sanitized build warning, not its diagnostic code', async () => {
    permission = { permission: 'granted', systemEnabled: null, requesting: false };
    setup = { ...setup, state: 'warning', code: 'token_unavailable', warning: 'Phone push is unavailable in this build or on this device. The in-app inbox remains available.' };
    await mount();
    expect(texts()).toContain(setup.warning);
    expect(texts()).not.toContain('token_unavailable');
    expect(action().props.children).toBe('Retry notification setup');
  });

  it.each(['permission', 'registration'] as const)('disables activation while %s is already checking', async source => {
    if (source === 'permission') { permission = { ...permission, requesting: true }; }
    else { setup = { ...setup, state: 'checking' }; }
    await mount();
    expect(button().props.accessibilityState).toEqual({ disabled: true, busy: true });
    await press(); // Exercise the guard even when a retained handler bypasses native disabled state.
    expect(enableNotificationPermission).not.toHaveBeenCalled();
    expect(openPushSettings).not.toHaveBeenCalled();
    expect(retryPushSetup).not.toHaveBeenCalled();
  });

  it('neither checks on a background mount nor activates until the app is active', async () => {
    jest.replaceProperty(AppState, 'currentState', 'background');
    await mount();
    await press();
    expect(refreshNotificationPermission).not.toHaveBeenCalled();
    expect(enableNotificationPermission).not.toHaveBeenCalled();
    expect(openPushSettings).not.toHaveBeenCalled();
    expect(retryPushSetup).not.toHaveBeenCalled();
    jest.replaceProperty(AppState, 'currentState', 'active');
    await press();
    expect(enableNotificationPermission).toHaveBeenCalledTimes(1);
    expect(retryPushSetup).toHaveBeenCalledTimes(1);
  });

  it.each(['signout', 'server switch', 'retired runtime', 'unmount'] as const)('discards a late permission grant after %s', async boundary => {
    const allowed = deferred<PushPermission>();
    jest.mocked(enableNotificationPermission).mockReturnValue(allowed.promise);
    await mount();
    await press();
    const isActive = jest.mocked(enableNotificationPermission).mock.calls[0][0];
    expect(isActive()).toBe(true);
    await changeOwner(boundary);
    expect(isActive()).toBe(false);
    await act(async () => { allowed.resolve('granted'); });
    expect(retryPushSetup).not.toHaveBeenCalled();
    expect(openPushSettings).not.toHaveBeenCalled();
    if (renderer) { expect(alerts()).toHaveLength(0); }
  });

  it('does not register if the phone backgrounds while an explicit prompt is pending', async () => {
    const allowed = deferred<PushPermission>();
    jest.mocked(enableNotificationPermission).mockReturnValue(allowed.promise);
    await mount();
    await press();
    jest.replaceProperty(AppState, 'currentState', 'background');
    expect(jest.mocked(enableNotificationPermission).mock.calls[0][0]()).toBe(false);
    await act(async () => { allowed.resolve('granted'); });
    expect(retryPushSetup).not.toHaveBeenCalled();
    expect(openPushSettings).not.toHaveBeenCalled();
  });

  it.each(['signout', 'server switch'] as const)('invalidates the UI retry guard and ignores its late failure after %s', async boundary => {
    permission = { permission: 'granted', systemEnabled: true, requesting: false };
    const registered = deferred<void>();
    jest.mocked(retryPushSetup).mockReturnValue(registered.promise);
    await mount();
    await press();
    const [runtime, user, isCurrent] = jest.mocked(retryPushSetup).mock.calls[0];
    const capturedRuntime = client.runtime;
    const capturedUser = auth.user;
    expect(isCurrent()).toBe(true);
    await changeOwner(boundary);
    expect(isCurrent()).toBe(false);
    expect(runtime).toBe(capturedRuntime);
    expect(user).toBe(capturedUser);
    await act(async () => { registered.reject(new Error('Synthetic stale server details must not reach the next session.')); });
    expect(retryPushSetup).toHaveBeenCalledTimes(1);
    expect(alerts()).toHaveLength(0);
    expect(texts()).not.toContain('Synthetic stale');
    expect(texts()).not.toContain('Phone notification setup could not finish.');
  });

  it.each(['signout', 'server switch'] as const)('ignores a late settings failure after %s', async boundary => {
    permission = { permission: 'blocked', systemEnabled: false, requesting: false };
    const opened = deferred<boolean>();
    jest.mocked(openPushSettings).mockReturnValue(opened.promise);
    await mount();
    await press();
    await changeOwner(boundary);
    await act(async () => { opened.resolve(false); });
    expect(alerts()).toHaveLength(0);
    expect(retryPushSetup).not.toHaveBeenCalled();
  });

  it.each(['permission', 'registration', 'settings'] as const)('sanitizes %s exceptions into an accessible themed retry message', async source => {
    const raw = new Error('Synthetic private detail: Bearer fixture-only; https://server.example.test/?token=not-real');
    if (source === 'permission') { jest.mocked(enableNotificationPermission).mockRejectedValueOnce(raw); }
    if (source === 'registration') {
      permission = { permission: 'granted', systemEnabled: true, requesting: false };
      jest.mocked(retryPushSetup).mockRejectedValueOnce(raw);
    }
    if (source === 'settings') {
      permission = { permission: 'blocked', systemEnabled: false, requesting: false };
      jest.mocked(openPushSettings).mockRejectedValueOnce(raw);
    }
    await mount();
    await press();
    expect(alerts()).toHaveLength(1);
    expect(alerts()[0].props.children).toBe('Phone notification setup could not finish. Please try again.');
    expect(alerts()[0].props.accessibilityLiveRegion).toBe('polite');
    expect(StyleSheet.flatten(alerts()[0].props.style).color).toBe(mode === 'dark' ? '#f4a7a7' : '#a33b3b');
    expect(JSON.stringify(renderer!.toJSON())).not.toMatch(/Bearer|fixture-only|server\.example|not-real|Synthetic private/);
    expect(button().props.accessibilityState).toEqual({ disabled: false, busy: false });
    await press();
    expect(alerts()).toHaveLength(0);
  });

  it.each(['unavailable', 'blocked'] as const)('handles a missing settings bridge safely with %s permission', async value => {
    permission = { permission: value, systemEnabled: value === 'blocked' ? false : null, requesting: false };
    jest.mocked(enableNotificationPermission).mockResolvedValue('unavailable');
    jest.mocked(openPushSettings).mockResolvedValue(false);
    await mount();
    await press();
    expect(alerts()).toHaveLength(1);
    expect(alerts()[0].props.children).toBe(value === 'blocked'
      ? 'Notification settings could not be opened. Use this app’s notification settings on your phone.'
      : 'Notifications are not enabled. You can allow them in your phone’s app settings.');
    expect(retryPushSetup).not.toHaveBeenCalled();
    expect(button().props.accessibilityState).toEqual({ disabled: false, busy: false });
  });

  it.each(['signedOut', 'restoring', 'expired', 'bootstrapError'] as const)('renders no account control while auth is %s', async status => {
    auth = { ...auth, status, user: null };
    await mount();
    expect(renderer!.toJSON()).toBeNull();
    expect(enableNotificationPermission).not.toHaveBeenCalled();
    expect(openPushSettings).not.toHaveBeenCalled();
    expect(retryPushSetup).not.toHaveBeenCalled();
  });
});
