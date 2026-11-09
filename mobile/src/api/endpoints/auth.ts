import { bindApiClient, request, revokeSession, type ApiBinding } from '../client';
import { LoginResponse } from '../types';
import { config } from '../../lib/config';

export function login(identifier: string, password: string, binding?: ApiBinding): Promise<LoginResponse> {
  return (binding ? bindApiClient(binding).request : request)<LoginResponse>('api/v1/auth/login', {
    method: 'POST',
    body: { identifier, password },
    authenticated: false,
  });
}

export interface LoginCarouselSlide {
  id: number;
  title: string | null;
  subtitle: string | null;
  light_image: string;
  dark_image: string | null;
  fit_mode: 'cover' | 'contain_blur';
}

export function loginCarousel(binding?: ApiBinding): Promise<{ data: { slides: LoginCarouselSlide[] } }> {
  return (binding ? bindApiClient(binding).request : request)('api/v1/auth/carousel', { authenticated: false });
}

export type LoginCarouselRealtimeGrant = { enabled: false } | {
  enabled: true;
  url: string;
  origin: string;
  channel: string;
  ticket: string;
  expires_at: number;
};

export function loginCarouselRealtime(binding?: ApiBinding): Promise<{ data: LoginCarouselRealtimeGrant }> {
  const client = binding ? bindApiClient(binding) : null;
  const baseUrl = client ? client.runtime.apiBaseUrl : config.apiBaseUrl;
  return (client ? client.request : request)('api/v1/auth/carousel/realtime', {
    authenticated: false,
    headers: { Origin: new URL(baseUrl).origin },
  });
}

export function logout(binding?: ApiBinding): Promise<void> {
  return revokeSession(binding && 'runtime' in binding ? binding.runtime : binding);
}
