import { ApiError, authorizedFetch } from '../client';
import { request } from '../client';

export interface HealthSummary {
  person: {
    name: string;
    kind: 'student' | 'employee' | null;
    identifier: string | null;
    program: string | null;
    year_level: string | null;
    department: string | null;
    position: string | null;
  };
  health: {
    blood_type: string | null;
    allergies: string | null;
    chronic_conditions: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    emergency_contact_relationship: string | null;
  };
}

export function healthSummary(): Promise<{ data: HealthSummary }> {
  return request<{ data: HealthSummary }>('api/v1/me/health-summary');
}

export async function uploadPhoto(uri: string): Promise<void> {
  const form = new FormData();
  form.append('photo', { uri, name: 'photo.jpg', type: 'image/jpeg' } as unknown as FormDataValue);
  const response = await authorizedFetch('api/v1/me/photo', { method: 'POST', body: form });
  if (!response.ok) {
    const body = (await response.json().catch(() => null)) as { error?: { code: string; message: string } } | null;
    throw new ApiError(response.status, body?.error ?? { code: 'upload_failed', message: 'The photo could not be uploaded.' });
  }
}

export async function removePhoto(): Promise<void> {
  await request<void>('api/v1/me/photo', { method: 'DELETE' });
}
