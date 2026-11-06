import { request } from '../client';
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
export function userRealtimeGrant(): Promise<{ data: UserRealtimeGrant }> {
  return request('api/v1/realtime/grant', {
    headers: { Origin: new URL(config.apiBaseUrl).origin },
  });
}
