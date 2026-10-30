import { ApiError, __resetClientForTests, request, setAccessToken, setSessionExpiredHandler } from '../src/api/client';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));

const keychainState: { token: string | null } = { token: null };

jest.mock('../src/lib/keychain', () => ({
  getRefreshToken: async () => keychainState.token,
  setRefreshToken: async (token: string) => {
    keychainState.token = token;
  },
  clearRefreshToken: async () => {
    keychainState.token = null;
  },
}));

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('api client', () => {
  let expired: boolean;

  beforeEach(() => {
    __resetClientForTests();
    keychainState.token = null;
    expired = false;
    setSessionExpiredHandler(() => {
      expired = true;
    });
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  it('passes the bearer token and unwraps JSON bodies', async () => {
    setAccessToken('a'.repeat(64));
    const fetchMock = jest.fn().mockResolvedValue(jsonResponse(200, { data: { hello: 'world' } }));
    globalThis.fetch = fetchMock as unknown as typeof fetch;

    const result = await request<{ data: { hello: string } }>('api/v1/me');

    expect(result).toEqual({ data: { hello: 'world' } });
    const init = fetchMock.mock.calls[0][1] as RequestInit;
    expect(init.headers).toMatchObject({ Authorization: 'Bearer ' + 'a'.repeat(64) });
  });

  it('throws ApiError with field payload for validation failures', async () => {
    globalThis.fetch = jest.fn().mockResolvedValue(
      jsonResponse(422, { error: { code: 'validation_failed', message: 'Review fields', fields: { date: ['bad'] } } }),
    ) as unknown as typeof fetch;

    await expect(request('api/v1/appointments', { method: 'POST', body: {} })).rejects.toMatchObject({
      status: 422,
      code: 'validation_failed',
      fields: { date: ['bad'] },
    });
  });

  it.each([
    ['287', 287],
    ['0', 0],
    ['invalid', null],
    ['-1', null],
    ['1.5', null],
    ['', null],
    [null, null],
    ['999999999999999999999999', null],
  ])('preserves 429 Retry-After %s without retrying login', async (header, expected) => {
    const response = new Response(JSON.stringify({ error: { code: 'rate_limited', message: 'Please wait.' } }), {
      status: 429,
      headers: header === null ? {} : { 'Retry-After': header },
    });
    globalThis.fetch = jest.fn().mockResolvedValue(response) as unknown as typeof fetch;

    await expect(request('api/v1/auth/login', { method: 'POST', body: {}, authenticated: false })).rejects.toMatchObject({
      status: 429,
      code: 'rate_limited',
      retryAfterSeconds: expected,
    });
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);
  });

  it('supports HTTP-date Retry-After values', async () => {
    const now = Date.parse('2026-01-01T00:00:00Z');
    jest.spyOn(Date, 'now').mockReturnValue(now);
    globalThis.fetch = jest.fn().mockResolvedValue(new Response('{}', {
      status: 429,
      headers: { 'Retry-After': new Date(now + 45000).toUTCString() },
    })) as unknown as typeof fetch;

    await expect(request('api/v1/auth/login', { authenticated: false })).rejects.toMatchObject({
      status: 429,
      retryAfterSeconds: 45,
    });
  });

  it('keeps invalid credentials distinct from rate limiting and connection failures', async () => {
    globalThis.fetch = jest.fn().mockResolvedValue(jsonResponse(401, {
      error: { code: 'invalid_credentials', message: 'Check your credentials.' },
    })) as unknown as typeof fetch;

    await expect(request('api/v1/auth/login', { authenticated: false })).rejects.toMatchObject({
      status: 401,
      code: 'invalid_credentials',
      retryAfterSeconds: null,
    });
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);
  });

  it('converts network failures into NetworkError without status semantics', async () => {
    jest.spyOn(console, 'warn').mockImplementation(() => {});
    globalThis.fetch = jest.fn().mockRejectedValue(new Error('connection refused')) as unknown as typeof fetch;
    await expect(request('api/v1/me')).rejects.toThrow('Cannot reach the server');
  });

  it('refreshes once on 401, retries the request, and stores the rotated token', async () => {
    setAccessToken('b'.repeat(64));
    keychainState.token = 'r'.repeat(64);

    const fetchMock = jest
      .fn()
      .mockResolvedValueOnce(jsonResponse(401, { error: { code: 'unauthenticated', message: 'expired' } }))
      .mockResolvedValueOnce(jsonResponse(200, { token: { access_token: 'c'.repeat(64), refresh_token: 's'.repeat(64) } }))
      .mockResolvedValueOnce(jsonResponse(200, { data: 'fresh' }));
    globalThis.fetch = fetchMock as unknown as typeof fetch;

    const result = await request<{ data: string }>('api/v1/queue');

    expect(result).toEqual({ data: 'fresh' });
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(keychainState.token).toBe('s'.repeat(64));
    expect(expired).toBe(false);
  });

  it('ends the session when the refresh token is rejected', async () => {
    setAccessToken('d'.repeat(64));
    keychainState.token = 'r'.repeat(64);

    globalThis.fetch = jest
      .fn()
      .mockResolvedValueOnce(jsonResponse(401, { error: { code: 'unauthenticated', message: 'expired' } }))
      .mockResolvedValueOnce(jsonResponse(401, { error: { code: 'invalid_refresh', message: 'sign in' } })) as unknown as typeof fetch;

    await expect(request('api/v1/queue')).rejects.toBeInstanceOf(ApiError);
    expect(expired).toBe(true);
  });

  it('never auto-refreshes auth endpoints themselves', async () => {
    globalThis.fetch = jest
      .fn()
      .mockResolvedValue(jsonResponse(401, { error: { code: 'invalid_credentials', message: 'no' } })) as unknown as typeof fetch;

    await expect(
      request('api/v1/auth/login', { method: 'POST', body: {}, authenticated: false }),
    ).rejects.toMatchObject({ code: 'invalid_credentials' });
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);
  });

  it('shares one refresh attempt across concurrent 401s', async () => {
    setAccessToken('e'.repeat(64));
    keychainState.token = 'r'.repeat(64);

    const gate: { resolve?: (response: Response) => void } = {};
    const refreshCalls: string[] = [];
    const state = { refreshed: false };

    const fetchMock = jest.fn((input: RequestInfo | URL): Promise<Response> => {
      const path = String(input);
      if (path.endsWith('auth/refresh')) {
        refreshCalls.push(path);
        return new Promise<Response>((resolve) => {
          gate.resolve = resolve;
        });
      }
      if (state.refreshed) {
        return Promise.resolve(jsonResponse(200, { data: 'ok' }));
      }
      return Promise.resolve(jsonResponse(401, { error: { code: 'unauthenticated', message: 'expired' } }));
    });
    globalThis.fetch = fetchMock as unknown as typeof fetch;

    const first = request<{ data: string }>('api/v1/queue');
    const second = request<{ data: string }>('api/v1/notifications');
    await new Promise((resolve) => {
      setImmediate(() => resolve(undefined));
    });

    expect(refreshCalls).toHaveLength(1);

    state.refreshed = true;
    gate.resolve?.(jsonResponse(200, { token: { access_token: 'f'.repeat(64), refresh_token: 't'.repeat(64) } }));
    await Promise.all([first, second]);
    expect(keychainState.token).toBe('t'.repeat(64));
    expect(expired).toBe(false);
  });
});
