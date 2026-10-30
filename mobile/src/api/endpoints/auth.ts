import { request, revokeSession } from '../client';
import { LoginResponse } from '../types';
import { config } from '../../lib/config';

export function login(identifier: string, password: string): Promise<LoginResponse> {
  return request<LoginResponse>('api/v1/auth/login', {
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

export function loginCarousel(): Promise<{ data: { slides: LoginCarouselSlide[] } }> {
  return request('api/v1/auth/carousel', { authenticated: false });
}

export type LoginCarouselRealtimeGrant = { enabled: false } | {
  enabled: true;
  url: string;
  origin: string;
  channel: string;
  ticket: string;
  expires_at: number;
};

export function loginCarouselRealtime(): Promise<{ data: LoginCarouselRealtimeGrant }> {
  return request('api/v1/auth/carousel/realtime', {
    authenticated: false,
    headers: { Origin: new URL(config.apiBaseUrl).origin },
  });
}

export function logout(): Promise<void> {
  return revokeSession();
}
