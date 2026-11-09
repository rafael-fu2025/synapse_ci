import { bindApiClient, request, type ApiBinding } from '../client';
import { MeResponse } from '../types';

export function me(binding?: ApiBinding): Promise<MeResponse> {
  return (binding ? bindApiClient(binding).request : request)<MeResponse>('api/v1/me');
}
