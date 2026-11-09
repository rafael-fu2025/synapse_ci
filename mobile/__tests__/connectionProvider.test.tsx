import React from 'react';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import {
  __resetClientForTests, getApiRuntime, SessionChangedError,
} from '../src/api/client';
import { ConnectionProvider } from '../src/features/connection/ConnectionProvider';
import { useOptionalConnection } from '../src/features/connection/ConnectionContext';
import { CONNECTION_PROFILES } from '../src/features/connection/profiles';
import {
  credentialBindingFor, newSavedConnection, readSavedConnection, writeSavedConnection, type SavedConnection,
} from '../src/features/connection/storage';
import { bindRuntimeApiBaseUrl } from '../src/lib/config';
import { bindCredentialStorage, clearLanRefreshToken } from '../src/lib/keychain';
import { clearUnownedPushScope } from '../src/lib/push/scope';
import { setNavigationIntentOwner } from '../src/features/workspace/navigationIntent';

jest.mock('../src/lib/config', () => ({
  config: { lanTest: true }, bindRuntimeApiBaseUrl: jest.fn(),
}));
jest.mock('../src/lib/keychain', () => ({
  bindCredentialStorage: jest.fn(), clearLanRefreshToken: jest.fn(), getRefreshToken: jest.fn(),
}));
jest.mock('../src/lib/push/registration', () => ({
  capturePushCleanup: () => Object.assign(() => Promise.resolve(), { clearScope: Promise.resolve() }),
}));
jest.mock('../src/lib/push/scope', () => ({ clearUnownedPushScope: jest.fn() }));
jest.mock('../src/features/connection/storage', () => ({
  credentialBindingFor: jest.fn(), newSavedConnection: jest.fn(),
  readSavedConnection: jest.fn(), writeSavedConnection: jest.fn(),
}));
jest.mock('../src/features/workspace/navigationIntent', () => ({
  clearNavigationIntents: jest.fn(), setNavigationIntentOwner: jest.fn(),
}));
jest.mock('../src/components/Action', () => ({ Action: () => null }));
jest.mock('../src/components/Screen', () => ({ Screen: jest.requireActual('react-native').View }));
jest.mock('../src/theme/ThemeProvider', () => ({
  useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }),
}));

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => { resolve = yes; });
  return { promise, resolve };
}
function saved(profileId: SavedConnection['profileId'] = 'university'): SavedConnection {
  return { version: 1, profileId, generation: '11111111-1111-4111-8111-111111111111', phase: 'ready', session: 'active' };
}

let renderer: ReactTestRenderer | undefined;
let connection: NonNullable<ReturnType<typeof useOptionalConnection>>;
const releaseConfig = jest.fn();
const releaseCredential = jest.fn();

function Probe() { connection = useOptionalConnection()!; return null; }
async function mount() {
  await act(async () => { renderer = create(<ConnectionProvider><Probe /></ConnectionProvider>); });
}

beforeEach(() => {
  __resetClientForTests();
  jest.resetAllMocks();
  jest.mocked(readSavedConnection).mockResolvedValue(saved());
  jest.mocked(writeSavedConnection).mockResolvedValue(undefined);
  jest.mocked(newSavedConnection).mockImplementation(profileId => ({ ...saved(profileId), phase: 'pending', session: 'signed-out' }));
  jest.mocked(credentialBindingFor).mockImplementation(record => JSON.stringify([
    record.profileId, CONNECTION_PROFILES.find(profile => profile.id === record.profileId)!.apiBaseUrl, record.generation,
  ]));
  jest.mocked(bindRuntimeApiBaseUrl).mockReturnValue(releaseConfig);
  jest.mocked(bindCredentialStorage).mockReturnValue(releaseCredential);
  jest.mocked(clearLanRefreshToken).mockResolvedValue(undefined);
  jest.mocked(clearUnownedPushScope).mockResolvedValue(undefined);
});
afterEach(() => {
  act(() => { renderer?.unmount(); });
  renderer = undefined;
  __resetClientForTests();
});

it('activates the selected replacement only after its final ready record is saved', async () => {
  await mount();
  const previous = getApiRuntime();
  const finalWrite = deferred<void>();
  jest.mocked(writeSavedConnection).mockResolvedValueOnce(undefined).mockReturnValueOnce(finalWrite.promise);
  let pending!: Promise<void>;
  await act(async () => { pending = connection.selectProfile('home'); });

  expect(previous.active).toBe(false);
  expect(() => getApiRuntime()).toThrow(SessionChangedError);
  expect(bindRuntimeApiBaseUrl).toHaveBeenCalledTimes(1);
  expect(writeSavedConnection).toHaveBeenNthCalledWith(1, expect.objectContaining({ profileId: 'home', phase: 'pending' }));
  expect(writeSavedConnection).toHaveBeenNthCalledWith(2, expect.objectContaining({ profileId: 'home', phase: 'ready' }));

  await act(async () => { finalWrite.resolve(); await pending; });

  expect(connection.profile?.id).toBe('home');
  expect(getApiRuntime().apiBaseUrl).toBe(CONNECTION_PROFILES[1].apiBaseUrl);
  expect(bindRuntimeApiBaseUrl).toHaveBeenCalledTimes(2);
  expect(bindCredentialStorage).toHaveBeenCalledTimes(2);
  expect(setNavigationIntentOwner).toHaveBeenCalledTimes(2);
});

it.each(['initial', 'replacement'] as const)('does not activate an unmounted %s selection after its final storage write completes', async mode => {
  const initialRead = deferred<SavedConnection | null>();
  if (mode === 'initial') { jest.mocked(readSavedConnection).mockReturnValueOnce(initialRead.promise); }
  await mount();
  const finalWrite = deferred<void>();
  jest.mocked(writeSavedConnection).mockResolvedValueOnce(undefined).mockReturnValueOnce(finalWrite.promise);
  let pending!: Promise<void>;
  await act(async () => {
    if (mode === 'initial') {
      const context = renderer!.root.findAll(node => node.props.onSelect).find(node => typeof node.type === 'function')!;
      pending = context.props.onSelect('home');
      initialRead.resolve(null);
    } else { pending = connection.selectProfile('home'); }
  });
  const result = pending.then(() => undefined, (error: unknown) => error);
  const bindings = mode === 'replacement' ? 1 : 0;
  expect(writeSavedConnection).toHaveBeenCalledTimes(2);
  expect(bindRuntimeApiBaseUrl).toHaveBeenCalledTimes(bindings);
  act(() => { renderer!.unmount(); });
  renderer = undefined;

  await act(async () => { finalWrite.resolve(); expect(await result).toBeInstanceOf(SessionChangedError); });

  expect(() => getApiRuntime()).toThrow(SessionChangedError);
  expect(bindRuntimeApiBaseUrl).toHaveBeenCalledTimes(bindings);
  expect(bindCredentialStorage).toHaveBeenCalledTimes(bindings);
  expect(setNavigationIntentOwner).toHaveBeenCalledTimes(bindings);
  expect(releaseConfig).toHaveBeenCalledTimes(bindings);
  expect(releaseCredential).toHaveBeenCalledTimes(bindings);
});

it('does not finalize a pending server switch whose cleanup completed after unmount', async () => {
  await mount();
  const cleaned = deferred<void>();
  jest.mocked(clearLanRefreshToken).mockReturnValueOnce(cleaned.promise);
  let pending!: Promise<void>;
  act(() => { pending = connection.selectProfile('home'); });
  const result = pending.then(() => undefined, (error: unknown) => error);
  act(() => { renderer!.unmount(); });
  renderer = undefined;

  await act(async () => { cleaned.resolve(); expect(await result).toBeInstanceOf(SessionChangedError); });

  expect(writeSavedConnection).toHaveBeenCalledTimes(1);
  expect(bindRuntimeApiBaseUrl).toHaveBeenCalledTimes(1);
  expect(() => getApiRuntime()).toThrow(SessionChangedError);
});
