import * as Keychain from 'react-native-keychain';

const SERVICE = 'com.humus.synapse.session';
const LAN_SERVICE = 'com.humus.synapse.lantest.session';
const INSTALLATION_SERVICE = 'com.humus.synapse.installation';
let credentialOwner: { binding: string } | null = null;

export function bindCredentialStorage(binding: string): () => void {
  const owner = { binding };
  credentialOwner = owner;
  return () => { if (credentialOwner === owner) { credentialOwner = null; } };
}

function boundToken(password: string, binding: string): string | null {
  try {
    const value = JSON.parse(password) as { version?: number; binding?: string; token?: unknown };
    return value.version === 1 && value.binding === binding && typeof value.token === 'string' && value.token.length > 0
      ? value.token : null;
  } catch { return null; }
}

// Native writes are asynchronous. Keep rotation, sign-out deletion and a subsequent
// sign-in ordered so a late native write cannot resurrect a signed-out credential.
let writes: Promise<void> = Promise.resolve();
function enqueueWrite(operation: () => Promise<void>): Promise<void> {
  const result = writes.then(operation);
  writes = result.catch(() => undefined);
  return result;
}

export async function getOrCreateInstallationId(create: () => string): Promise<string> {
  try {
    const existing = await Keychain.getGenericPassword({ service: INSTALLATION_SERVICE });
    if (existing && /^[0-9a-f-]{36}$/i.test(existing.password)) { return existing.password; }
  } catch {
    // Continue to create a new device-only installation identifier.
  }
  const id = create();
  const saved = await Keychain.setGenericPassword('synapse-installation', id, {
    service: INSTALLATION_SERVICE,
    accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
  });
  if (saved === false) { throw new Error('Secure installation storage is unavailable.'); }
  return id;
}

/** The refresh token is the only long-lived credential; it stays in encrypted storage. */
export async function getRefreshToken(binding: string | null = null): Promise<string | null> {
  const owner = credentialOwner;
  if (binding !== null && owner?.binding !== binding) { return null; }
  try {
    await writes;
    if (binding !== null && (credentialOwner !== owner || owner?.binding !== binding)) { return null; }
    const credentials = await Keychain.getGenericPassword({ service: binding === null ? SERVICE : LAN_SERVICE });
    if (binding !== null && credentialOwner !== owner) { return null; }
    if (!credentials) { return null; }
    return binding === null ? credentials.password : boundToken(credentials.password, binding);
  } catch (error) {
    if (binding !== null) { throw error; }
    return null;
  }
}

export function setRefreshToken(token: string, binding: string | null = null): Promise<void> {
  const owner = credentialOwner;
  const password = binding === null ? token : JSON.stringify({ version: 1, binding, token });
  return enqueueWrite(async () => {
    if (binding !== null && (credentialOwner !== owner || owner?.binding !== binding)) {
      throw new Error('The selected server has changed. Please sign in again.');
    }
    const saved = await Keychain.setGenericPassword('synapse-session', password, {
      service: binding === null ? SERVICE : LAN_SERVICE,
      accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
    });
    if (saved === false) {
      throw new Error('Secure session storage is unavailable. Please try again.');
    }
    if (binding !== null && credentialOwner !== owner) {
      throw new Error('The selected server has changed. Please sign in again.');
    }
  });
}
export function clearRefreshToken(binding: string | null = null): Promise<void> {
  return enqueueWrite(async () => {
    if (binding !== null) {
      const credentials = await Keychain.getGenericPassword({ service: LAN_SERVICE });
      if (!credentials || boundToken(credentials.password, binding) === null) { return; }
      const tombstone = await Keychain.setGenericPassword('synapse-session', JSON.stringify({ version: 1, binding, signedOut: true }), {
        service: LAN_SERVICE, accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
      });
      if (tombstone === false) { throw new Error('The saved account could not be removed. Please try again.'); }
      const cleared = await Keychain.resetGenericPassword({ service: LAN_SERVICE });
      if (cleared === false) { throw new Error('The saved account could not be removed. Please try again.'); }
      return;
    }
    try {
      await Keychain.resetGenericPassword({ service: SERVICE });
    } catch {
      // The in-memory session is already gone. Server-side logout also revokes the row.
    }
  });
}
/** Used only while no LAN connection is mounted, including interrupted switches. */
export function clearLanRefreshToken(): Promise<void> {
  return enqueueWrite(async () => {
    const credentials = await Keychain.getGenericPassword({ service: LAN_SERVICE });
    if (!credentials) { return; }
    const tombstone = await Keychain.setGenericPassword('synapse-session', JSON.stringify({ version: 1, signedOut: true }), {
      service: LAN_SERVICE, accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
    });
    if (tombstone === false) { throw new Error('The saved account could not be removed. Please try again.'); }
    const cleared = await Keychain.resetGenericPassword({ service: LAN_SERVICE });
    if (cleared === false) { throw new Error('The saved account could not be removed. Please try again.'); }
  });
}
