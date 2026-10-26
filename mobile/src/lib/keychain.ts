import * as Keychain from 'react-native-keychain';

const SERVICE = 'com.humus.synapse.session';

/** The refresh token is the only long-lived credential; it stays in encrypted storage. */
export async function getRefreshToken(): Promise<string | null> {
  try {
    const credentials = await Keychain.getGenericPassword({ service: SERVICE });
    return credentials ? credentials.password : null;
  } catch {
    return null;
  }
}

export async function setRefreshToken(token: string): Promise<void> {
  await Keychain.setGenericPassword('synapse-session', token, {
    service: SERVICE,
    accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
  });
}

export async function clearRefreshToken(): Promise<void> {
  try {
    await Keychain.resetGenericPassword({ service: SERVICE });
  } catch {
    // A missing or corrupted entry is equivalent to no stored credential.
  }
}
