import { bindApiClient, request, type ApiRuntime } from '../client';

export type PushPlatform = 'android' | 'ios';

export interface PushCapabilities {
  scope_version: 1;
  deployment_id: string;
  audiences: string[];
}

export interface PushRegistrationScope {
  audience: 'lan-test';
  registration_id: string;
  deployment_id?: string;
}

export interface PushRegistrationResult {
  registered: boolean;
  scope_version?: number;
  deployment_id?: string;
  registration_id?: string;
}

function registrationBody(
  token: string,
  platform: PushPlatform,
  installationId: string,
  capabilities: string[],
  deviceLabel?: string,
  scope?: PushRegistrationScope,
) {
  return { token, platform, installation_id: installationId, capabilities, device_label: deviceLabel, ...scope };
}

/** Legacy calls keep their original envelope; scoped callers explicitly name the LAN audience. */
export function registerDeviceToken(
  token: string,
  platform: PushPlatform,
  installationId: string,
  capabilities: string[],
  deviceLabel?: string,
  scope?: PushRegistrationScope,
  runtime?: ApiRuntime,
): Promise<{ data: PushRegistrationResult }> {
  const options = { method: 'POST' as const, body: registrationBody(token, platform, installationId, capabilities, deviceLabel, scope) };
  return runtime === undefined ? request('api/v1/push/token', options) : request('api/v1/push/token', options, runtime);
}

/** Check support before obtaining or handing the routable FCM address to a LAN server. */
export function pushCapabilities(runtime?: ApiRuntime): Promise<{ data: PushCapabilities }> {
  return runtime === undefined ? request('api/v1/push/capabilities') : request('api/v1/push/capabilities', {}, runtime);
}

export function revokeDeviceToken(token: string, scope?: PushRegistrationScope, runtime?: ApiRuntime): Promise<void> {
  const options = { method: 'DELETE' as const, body: { token, ...(scope ? { audience: scope.audience, registration_id: scope.registration_id } : {}) } };
  return runtime === undefined ? request('api/v1/push/token', options) : request('api/v1/push/token', options, runtime);
}

/** One auth/runtime snapshot owns capabilities, registration and token rotation together. */
export function createPushApi(runtime?: ApiRuntime) {
  const client = bindApiClient(runtime);
  return {
    client,
    capabilities: () => client.request<{ data: PushCapabilities }>('api/v1/push/capabilities'),
    register: (
      token: string,
      platform: PushPlatform,
      installationId: string,
      capabilities: string[],
      scope?: PushRegistrationScope,
    ) => client.request<{ data: PushRegistrationResult }>('api/v1/push/token', {
      method: 'POST', body: registrationBody(token, platform, installationId, capabilities, undefined, scope),
    }),
    revoke: (token: string, scope?: PushRegistrationScope) => client.request<void>('api/v1/push/token', {
      method: 'DELETE', body: { token, ...(scope ? { audience: scope.audience, registration_id: scope.registration_id } : {}) },
    }),
  };
}
