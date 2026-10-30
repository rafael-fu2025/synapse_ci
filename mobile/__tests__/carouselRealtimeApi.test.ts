import { __resetClientForTests, setAccessToken } from '../src/api/client';
import { loginCarousel, loginCarouselRealtime } from '../src/api/endpoints/auth';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test/nested' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));

beforeEach(() => { __resetClientForTests(); });
afterEach(() => { jest.restoreAllMocks(); __resetClientForTests(); });

it('fetches public metadata and grants without Bearer even when an access token exists', async () => {
  setAccessToken('must-not-leak');
  const fetchMock = jest.spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(new Response(JSON.stringify({ data: { slides: [] } }), { status: 200 }))
    .mockResolvedValueOnce(new Response(JSON.stringify({ data: { enabled: false } }), { status: 200 }));
  await expect(loginCarousel()).resolves.toEqual({ data: { slides: [] } });
  await expect(loginCarouselRealtime()).resolves.toEqual({ data: { enabled: false } });
  expect(fetchMock).toHaveBeenNthCalledWith(1, 'https://api.test/nested/api/v1/auth/carousel', expect.objectContaining({ method: 'GET' }));
  expect(fetchMock).toHaveBeenNthCalledWith(2, 'https://api.test/nested/api/v1/auth/carousel/realtime', expect.objectContaining({
    method: 'GET', headers: { Accept: 'application/json', Origin: 'https://api.test' },
  }));
  for (const [, options] of fetchMock.mock.calls) {
    expect(options?.headers).not.toHaveProperty('Authorization');
    expect(options?.body).toBeUndefined();
  }
});

it('does not attempt session refresh for a rejected public grant', async () => {
  setAccessToken('must-not-leak');
  const fetchMock = jest.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({
    error: { code: 'invalid_origin', message: 'Origin not permitted' },
  }), { status: 403 }));
  await expect(loginCarouselRealtime()).rejects.toMatchObject({ status: 403 });
  expect(fetchMock).toHaveBeenCalledTimes(1);
});
