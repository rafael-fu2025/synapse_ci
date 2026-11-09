import { ApiError, authorizedFetch, bindApiClient, request, type ApiBinding } from '../client';

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

export function healthSummary(binding?: ApiBinding): Promise<{ data: HealthSummary }> {
  return (binding ? bindApiClient(binding).request : request)<{ data: HealthSummary }>('api/v1/me/health-summary');
}

/** A credential-free data URI; fetch, blob decoding and FileReader all retain their owner. */
export async function profilePhoto(binding?: ApiBinding): Promise<string | null> {
  const client = bindApiClient(binding);
  return client.run(async signal => {
    const response = await authorizedFetch('api/v1/me/photo', { method: 'GET' }, client);
    client.assertCurrent();
    if (!response.ok) {
      throw new ApiError(response.status, { code: 'photo_unavailable', message: 'Your profile photo could not be loaded.' });
    }
    const contentType = response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase();
    // The API's initials SVG is rendered locally by Avatar instead.
    if (contentType === 'image/svg+xml') { return null; }
    if (contentType !== 'image/webp' && contentType !== 'image/jpeg' && contentType !== 'image/png') {
      throw new Error('The server returned an unsupported profile photo.');
    }
    const blob = await response.blob();
    client.assertCurrent();
    return new Promise<string>((resolve, reject) => {
      const reader = new FileReader();
      const abort = () => {
        try { reader.abort(); }
        catch { /* A native reader may already have finished. */ }
        finish(new Error('Your profile photo could not be read.'));
      };
      const finish = (error?: unknown) => {
        signal.removeEventListener('abort', abort);
        try {
          client.assertCurrent();
          if (error) { reject(error); }
          else if (typeof reader.result === 'string') { resolve(reader.result); }
          else { reject(new Error('Your profile photo could not be read.')); }
        } catch (failure) { reject(failure); }
      };
      reader.onload = () => finish();
      reader.onerror = reader.onabort = () => finish(new Error('Your profile photo could not be read.'));
      signal.addEventListener('abort', abort, { once: true });
      if (signal.aborted) { abort(); return; }
      try { reader.readAsDataURL(blob); }
      catch (error) { finish(error); }
    });
  });
}

export interface PhotoUpload {
  uri: string;
  name: string;
  type: 'image/jpeg' | 'image/png' | 'image/webp';
}

export async function uploadPhoto(photo: PhotoUpload, binding?: ApiBinding): Promise<void> {
  const client = bindApiClient(binding);
  await client.run(async () => {
    const form = new FormData();
    form.append('photo', photo as unknown as FormDataValue);
    const response = await authorizedFetch('api/v1/me/photo', { method: 'POST', body: form }, client);
    client.assertCurrent();
    if (!response.ok) {
      const body = (await response.json().catch(() => null)) as { error?: { code: string; message: string } } | null;
      client.assertCurrent();
      throw new ApiError(response.status, body?.error ?? { code: 'upload_failed', message: 'The photo could not be uploaded.' });
    }
  });
}

export async function removePhoto(binding?: ApiBinding): Promise<void> {
  await (binding ? bindApiClient(binding).request : request)<void>('api/v1/me/photo', { method: 'DELETE' });
}
