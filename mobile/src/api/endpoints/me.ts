import { request } from '../client';
import { MeResponse } from '../types';

export function me(): Promise<MeResponse> {
  return request<MeResponse>('api/v1/me');
}
