import { request } from '../client';
import { LoginResponse } from '../types';

export function login(identifier: string, password: string): Promise<LoginResponse> {
  return request<LoginResponse>('api/v1/auth/login', {
    method: 'POST',
    body: { identifier, password },
    authenticated: false,
  });
}

export function logout(): Promise<void> {
  return request<void>('api/v1/auth/logout', { method: 'POST' });
}
