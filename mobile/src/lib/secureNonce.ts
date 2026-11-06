/** Cryptographically secure UUID v4 using the RN getRandomValues polyfill loaded at app entry. */
export function secureClientNonce(): string {
  const cryptoApi = (globalThis as unknown as {
    crypto?: { getRandomValues?: (values: Uint8Array) => Uint8Array };
  }).crypto;
  if (typeof cryptoApi?.getRandomValues !== 'function') {
    throw new Error('Secure random values are unavailable.');
  }
  const bytes = cryptoApi.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
