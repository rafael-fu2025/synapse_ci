import { bindApiClient, request, type ApiBinding } from '../client';
import { config } from '../../lib/config';

export type UserRealtimeGrant = { enabled: false } | {
  enabled: true;
  url: string;
  origin: string;
  channel: string;
  ticket: string;
  expires_at: number;
};

/** Bearer-authenticated private-channel grant; identity comes from the token, never selectors. */
export function userRealtimeGrant(binding?: ApiBinding): Promise<{ data: UserRealtimeGrant }> {
  const client = binding ? bindApiClient(binding) : null;
  const baseUrl = client ? client.runtime.apiBaseUrl : config.apiBaseUrl;
  return (client ? client.request : request)('api/v1/realtime/grant', {
    headers: { Origin: new URL(baseUrl).origin },
  });
}
