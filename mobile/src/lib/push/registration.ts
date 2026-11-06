import { getOrCreateInstallationId } from '../keychain';
import { secureClientNonce } from '../secureNonce';
import { registerDeviceToken, revokeDeviceToken } from '../../api/endpoints/push';
import { currentDeviceToken, currentPushPlatform, onPushTokenRefresh, requestPushPermission } from './fcm';

/**
 * The token this session registered. Kept so a sign-out can withdraw exactly what
 * was registered, without waiting on the platform.
 */
let registeredToken: string | null = null;

/**
 * Register this device so the server can deliver notices to it.
 *
 * The token is a routable address, not a credential. Every failure is swallowed:
 * the in-app inbox, the realtime hint and the polling cadences remain the channel.
 */
export async function registerDevice(): Promise<void> {
  const permission = await requestPushPermission();
  if (permission !== 'granted') {
    return;
  }
  const token = await currentDeviceToken();
  if (!token) {
    return;
  }
  registeredToken = token;
  try {
    const installationId = await getOrCreateInstallationId(secureClientNonce);
    await registerDeviceToken(token, currentPushPlatform(), installationId, ['team_workspace']);
  } catch {
    // A failed registration only costs the push channel.
  }
}

/**
 * Withdraw the token this session registered.
 *
 * A token is only withdrawn when this session registered one: a device that was
 * never registered has nothing to withdraw.
 */
export async function revokeDevice(): Promise<void> {
  const token = registeredToken;
  registeredToken = null;
  if (!token) {
    return;
  }
  try {
    await revokeDeviceToken(token);
  } catch {
    // Best effort. The token stops being used once the session ends.
  }
}

/** Keep the registered token in step with the platform's rotation. */
export function watchPushToken(): () => void {
  return onPushTokenRefresh((token) => {
    registeredToken = token;
    getOrCreateInstallationId(secureClientNonce)
      .then(installationId => registerDeviceToken(token, currentPushPlatform(), installationId, ['team_workspace']))
      .catch(() => undefined);
  });
}
