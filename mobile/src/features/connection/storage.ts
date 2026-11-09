import AsyncStorage from '@react-native-async-storage/async-storage';
import { secureClientNonce } from '../../lib/secureNonce';
import { findConnectionProfile, type ConnectionProfileId } from './profiles';

const SELECTION_KEY = 'synapse.connection.lan-test';
const GENERATION = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export type SavedConnection = {
  version: 1;
  profileId: ConnectionProfileId;
  generation: string;
  phase: 'pending' | 'ready';
  session: 'signed-out' | 'active';
};

let writes: Promise<void> = Promise.resolve();

export function newSavedConnection(profileId: ConnectionProfileId): SavedConnection {
  if (!findConnectionProfile(profileId)) { throw new Error('Choose University or Home.'); }
  return { version: 1, profileId, generation: secureClientNonce(), phase: 'pending', session: 'signed-out' };
}

function validate(value: unknown): SavedConnection {
  const record = value as Partial<SavedConnection> | null;
  if (!record || record.version !== 1 || !findConnectionProfile(record.profileId) ||
      typeof record.generation !== 'string' || !GENERATION.test(record.generation) ||
      (record.phase !== 'pending' && record.phase !== 'ready') ||
      (record.session !== 'signed-out' && record.session !== 'active')) {
    throw new Error('The saved server choice could not be read. Choose a server again.');
  }
  return record as SavedConnection;
}

export async function readSavedConnection(): Promise<SavedConnection | null> {
  await writes;
  let text: string | null;
  try { text = await AsyncStorage.getItem(SELECTION_KEY); }
  catch { throw new Error('Saved server settings are unavailable. Please try again.'); }
  if (text === null) { return null; }
  try { return validate(JSON.parse(text)); }
  catch { throw new Error('The saved server choice could not be read. Choose a server again.'); }
}

export function writeSavedConnection(record: SavedConnection): Promise<void> {
  const snapshot = { ...validate(record) };
  const result = writes.then(async () => {
    try { await AsyncStorage.setItem(SELECTION_KEY, JSON.stringify(snapshot)); }
    catch { throw new Error('The server choice could not be saved. Please try again.'); }
  });
  writes = result.catch(() => undefined);
  return result;
}

export function credentialBindingFor(record: SavedConnection): string {
  const profile = findConnectionProfile(record.profileId)!;
  return JSON.stringify([profile.id, profile.apiBaseUrl, record.generation]);
}
