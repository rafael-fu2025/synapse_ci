import { ApiError, authorizedFetch } from '../client';
import { request } from '../client';

export interface HealthSummary {
  person: {
    name: string;
    kind: 'student' | 'employee' | null;
    identifier: string | null;
    email: string | null;
    program: string | null;
    year_level: string | number | null;
    section: string | null;
    department: string | null;
    position: string | null;
    employment_status: string | null;
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

/** Fetch through the auth client so token rotation also works for images, not just JSON. */
export async function profilePhoto(): Promise<string | null> {
  const response = await authorizedFetch('api/v1/me/photo', { method: 'GET' });
  if (!response.ok) {
    throw new ApiError(response.status, { code: 'photo_unavailable', message: 'Your profile photo could not be loaded.' });
  }
  const contentType = response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase();
  // The API returns an initials SVG when no photo exists. Let Avatar draw its own maroon fallback.
  if (contentType === 'image/svg+xml') {
    return null;
  }
  if (contentType !== 'image/webp' && contentType !== 'image/jpeg' && contentType !== 'image/png') {
    throw new Error('The server returned an unsupported profile photo.');
  }
  const blob = await response.blob();
  // A data URI contains image bytes, never credentials. It stays in the session's memory cache
  // rather than a native image URL cache that might ignore Authorization when accounts change.
  return new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      if (typeof reader.result === 'string') {
        resolve(reader.result);
      } else {
        reject(new Error('Your profile photo could not be read.'));
      }
    };
    reader.onerror = reader.onabort = () => reject(new Error('Your profile photo could not be read.'));
    reader.readAsDataURL(blob);
  });
}

export interface PhotoUpload {
  uri: string;
  name: string;
  type: 'image/jpeg' | 'image/png' | 'image/webp';
}

export async function uploadPhoto(photo: PhotoUpload): Promise<void> {
  const form = new FormData();
  form.append('photo', photo as unknown as FormDataValue);
  const response = await authorizedFetch('api/v1/me/photo', { method: 'POST', body: form });
  if (!response.ok) {
    const body = (await response.json().catch(() => null)) as { error?: { code: string; message: string } } | null;
    throw new ApiError(response.status, body?.error ?? { code: 'upload_failed', message: 'The photo could not be uploaded.' });
  }
}

export async function removePhoto(): Promise<void> {
  await request<void>('api/v1/me/photo', { method: 'DELETE' });
}
