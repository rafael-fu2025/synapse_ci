import * as Keychain from 'react-native-keychain';

const SERVICE = 'com.humus.synapse.session';

// Native writes are asynchronous. Keep rotation, sign-out deletion and a subsequent
// sign-in ordered so a late native write cannot resurrect a signed-out credential.
let writes: Promise<void> = Promise.resolve();
function enqueueWrite(operation: () => Promise<void>): Promise<void> {
  const result = writes.then(operation);
  writes = result.catch(() => undefined);
  return result;
}

/** The refresh token is the only long-lived credential; it stays in encrypted storage. */
export async function getRefreshToken(): Promise<string | null> {
  try {
    await writes;
    const credentials = await Keychain.getGenericPassword({ service: SERVICE });
    return credentials ? credentials.password : null;
  } catch {
    return null;
  }
}

export function setRefreshToken(token: string): Promise<void> {
  return enqueueWrite(async () => {
    const saved = await Keychain.setGenericPassword('synapse-session', token, {
      service: SERVICE,
      accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
    });
    if (saved === false) {
      throw new Error('Secure session storage is unavailable. Please try again.');
    }
  });
}

export function clearRefreshToken(): Promise<void> {
  return enqueueWrite(async () => {
    try {
      await Keychain.resetGenericPassword({ service: SERVICE });
    } catch {
      // The in-memory session is already gone. Server-side logout also revokes the row.
    }
  });
}
