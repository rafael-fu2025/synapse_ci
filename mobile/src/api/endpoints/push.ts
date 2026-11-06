import { request } from '../client';

export type PushPlatform = 'android' | 'ios';

/**
 * Hand the device's push token to the API.
 *
 * The token is a routable address, not a credential: the API stores it against the
 * signed-in identity and never returns it.
 */
export function registerDeviceToken(
  token: string,
  platform: PushPlatform,
  installationId: string,
  capabilities: string[],
  deviceLabel?: string,
): Promise<{ data: { registered: boolean } }> {
  return request('api/v1/push/token', {
    method: 'POST',
    body: { token, platform, installation_id: installationId, capabilities, device_label: deviceLabel },
  });
}

/** Withdraw a token when the device stops using it or the account signs out. */
export function revokeDeviceToken(token: string): Promise<void> {
  return request('api/v1/push/token', { method: 'DELETE', body: { token } });
}
