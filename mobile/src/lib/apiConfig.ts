const USB_API_BASE_URL = 'http://127.0.0.1:8080';

/** Native env values are compiled into the app; never put secrets in them. */
export function resolveApiBaseUrl(value: string | undefined, development: boolean): string {
  const configured = value?.trim();
  if (!configured) {
    if (development) {
      // adb reverse works for both a USB device and an Android emulator.
      return USB_API_BASE_URL;
    }
    throw new Error('API_BASE_URL is required for release builds. Configure an HTTPS API URL and rebuild the app.');
  }

  let url: URL;
  try {
    url = new URL(configured);
  } catch {
    throw new Error('API_BASE_URL must be an absolute HTTP(S) URL. Update mobile/.env and rebuild the app.');
  }
  if (
    !['http:', 'https:'].includes(url.protocol) ||
    !url.hostname ||
    url.username ||
    url.password ||
    url.search ||
    url.hash
  ) {
    throw new Error('API_BASE_URL must be an HTTP(S) URL without credentials, a query, or a fragment.');
  }
  if (!development && url.protocol !== 'https:') {
    throw new Error('API_BASE_URL must use HTTPS for release builds.');
  }
  return url.toString().replace(/\/+$/, '');
}
