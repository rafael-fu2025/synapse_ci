import { resolveApiBaseUrl } from '../src/lib/apiConfig';

jest.mock('react-native-config', () => ({
  __esModule: true,
  default: { API_BASE_URL: 'https://api.example.test/' },
}));

describe('API configuration', () => {
  it.each([undefined, '', '   '])('uses USB loopback in development without an explicit URL (%s)', value => {
    expect(resolveApiBaseUrl(value, true)).toBe('http://127.0.0.1:8080');
  });

  it.each([
    ['http://127.0.0.1:8080/', 'http://127.0.0.1:8080'],
    [' http://10.0.2.2:8080/// ', 'http://10.0.2.2:8080'],
    ['http://172.16.153.237:8080', 'http://172.16.153.237:8080'],
    ['http://[::1]:8080/', 'http://[::1]:8080'],
    ['https://api.example.test/portal/', 'https://api.example.test/portal'],
  ])('normalizes an explicit development URL %s', (value, expected) => {
    expect(resolveApiBaseUrl(value, true)).toBe(expected);
  });

  it.each([
    '172.16.153.237:8080',
    'ftp://api.example.test',
    'not a URL',
    'http://user:password@api.example.test',
    'http://api.example.test?token=secret',
    'http://api.example.test#fragment',
  ])('rejects invalid configuration rather than silently contacting another server (%s)', value => {
    expect(() => resolveApiBaseUrl(value, true)).toThrow('API_BASE_URL');
  });

  it.each([undefined, '', 'http://127.0.0.1:8080', 'http://api.example.test'])('rejects unsafe release configuration (%s)', value => {
    expect(() => resolveApiBaseUrl(value, false)).toThrow('API_BASE_URL');
  });

  it('accepts an explicit HTTPS API in release builds', () => {
    expect(resolveApiBaseUrl('https://api.example.test/', false)).toBe('https://api.example.test');
  });

  it('uses the native compiled environment value', () => {
    const { config } = require('../src/lib/config');
    expect(config.apiBaseUrl).toBe('https://api.example.test');
  });
});
