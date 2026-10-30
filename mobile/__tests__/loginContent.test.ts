import * as client from '../src/api/client';
import { loginCarousel } from '../src/api/endpoints/auth';
import { FALLBACK_LOGIN_SLIDES, LOGIN_SOCIAL_LINKS, loginSlides } from '../src/features/auth/loginContent';
import { getRefreshToken } from '../src/lib/keychain';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));

const validSlide = {
  id: 8,
  title: 'Campus services',
  subtitle: 'Here to help',
  light_image: 'uploads/carousel/campus.jpg',
  dark_image: 'uploads/carousel/campus-dark.webp',
  fit_mode: 'contain_blur',
};

function normalize(overrides: Record<string, unknown> = {}) {
  return loginSlides({ data: { slides: [{ ...validSlide, ...overrides }] } });
}

beforeEach(() => {
  client.__resetClientForTests();
  jest.mocked(getRefreshToken).mockReset();
  jest.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('Unexpected network request'));
});

afterEach(() => {
  client.__resetClientForTests();
  jest.restoreAllMocks();
});

describe('public login content', () => {
  it('preserves the three exact HTTPS web social destinations in display order', () => {
    expect(LOGIN_SOCIAL_LINKS.map(({ label, url }) => ({ label, url }))).toEqual([
      { label: 'Guidance', url: 'https://www.facebook.com/profile.php?id=100063778576372' },
      { label: 'FoundationU', url: 'https://www.facebook.com/foundationu.edu' },
      { label: 'Health Care', url: 'https://www.facebook.com/profile.php?id=100057107094031' },
    ]);
    LOGIN_SOCIAL_LINKS.forEach(link => {
      expect(link.url).toMatch(/^https:\/\/www\.facebook\.com\//);
      expect(link.accessibilityLabel).toMatch(/on Facebook$/);
      expect(link.image).toBeDefined();
    });
  });

  it('bundles the web-default titles, subtitles, fit modes, and both theme variants', () => {
    expect(FALLBACK_LOGIN_SLIDES).toEqual([
      {
        id: 1, title: 'Foundation University', subtitle: 'Health & Guidance Services Portal', fitMode: 'cover',
        lightImage: require('../src/assets/login/fuel-bg.jpg'),
        darkImage: require('../src/assets/login/FoundationU-Dark.png'),
      },
      {
        id: 2, title: 'Guidance & Counseling Center', subtitle: 'Confidential Support & Mental Wellness Services', fitMode: 'contain_blur',
        lightImage: require('../src/assets/login/guidance-light.png'),
        darkImage: require('../src/assets/login/guidance-dark.png'),
      },
      {
        id: 3, title: 'University Health Services', subtitle: 'Accessible Campus Medical & Wellness Care', fitMode: 'contain_blur',
        lightImage: require('../src/assets/login/health-light.png'),
        darkImage: require('../src/assets/login/health-dark.png'),
      },
    ]);
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });
});

describe('same-server carousel image normalization', () => {
  it.each([
    'assets/img/brand/guidance.png',
    'assets/img/brand/nested/health-dark.WEBP',
    'uploads/carousel/2026/campus-1.jpeg',
    'uploads/carousel/campus.jpg',
    'uploads/carousel/campus.gif',
  ])('accepts an allowlisted relative image path: %s', path => {
    expect(normalize({ light_image: path })).toEqual([{
      id: 8,
      title: 'Campus services',
      subtitle: 'Here to help',
      lightImage: { uri: `https://api.test/${path}` },
      darkImage: { uri: 'https://api.test/uploads/carousel/campus-dark.webp' },
      fitMode: 'contain_blur',
    }]);
  });

  it.each([
    'https://evil.test/banner.png',
    'http://evil.test/banner.jpg',
    'https://api.test/uploads/carousel/campus.jpg',
    '//evil.test/banner.png',
    '/uploads/carousel/banner.png',
    'file:///uploads/carousel/banner.png',
    'data:image/png;base64,AAAA',
    '../uploads/carousel/banner.png',
    'uploads/carousel/../private/banner.png',
    'uploads/carousel/nested/../../banner.png',
    'uploads/carousel/./banner.png',
    'uploads/carousel/%2e%2e/banner.png',
    'uploads/carousel/%252e%252e/banner.png',
    'uploads/carousel/..\\banner.png',
    'uploads/carousel/banner.png?token=secret',
    'uploads/carousel/banner.png?url=https://evil.test',
    'uploads/carousel/banner.png#fragment',
    'uploads/carousel/banner.png%3Ftoken=secret',
    'uploads/carousel//banner.png',
    'uploads/other/banner.png',
    'assets/img/other/banner.png',
    'uploads/carousel/banner.svg',
    'uploads/carousel/banner.html',
    'uploads/carousel/banner.png.exe',
    `uploads/carousel/${'a'.repeat(240)}.png`,
    '',
    null,
    123,
    { uri: 'https://evil.test/banner.png' },
  ])('rejects unsafe or unsupported light artwork (case %#): %s', path => {
    expect(normalize({ light_image: path })).toBe(FALLBACK_LOGIN_SLIDES);
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });

  it.each([
    null,
    undefined,
    'https://evil.test/dark.png',
    'uploads/carousel/../dark.png',
    'uploads/carousel/dark.png?token=secret',
  ])('uses validated light artwork when dark artwork is invalid (case %#)', path => {
    const [slide] = normalize({ dark_image: path });
    expect(slide.lightImage).toEqual({ uri: 'https://api.test/uploads/carousel/campus.jpg' });
    expect(slide.darkImage).toEqual(slide.lightImage);
  });

  it.each([undefined, null, {}, { data: null }, { data: {} }, { data: { slides: 'invalid' } }, { data: { slides: [] } }])(
    'uses bundled defaults for absent, malformed, or empty payloads (case %#)', payload => {
      expect(loginSlides(payload)).toBe(FALLBACK_LOGIN_SLIDES);
    },
  );

  it('filters invalid rows and duplicate IDs while preserving valid server order', () => {
    const rows = [
      null,
      false,
      'not a slide',
      { ...validSlide, id: 0 },
      { ...validSlide, id: -1 },
      { ...validSlide, id: 1.5 },
      { ...validSlide, id: Number.MAX_SAFE_INTEGER + 1 },
      { ...validSlide, id: 'invalid' },
      { ...validSlide, id: 4, light_image: 'https://evil.test/banner.png' },
      { ...validSlide, id: 4 },
      { ...validSlide, id: 4, title: 'Duplicate' },
      { ...validSlide, id: '5', title: null, subtitle: 123, fit_mode: 'unsupported' },
    ];
    const slides = loginSlides({ data: { slides: rows } });
    expect(slides.map(slide => slide.id)).toEqual([4, 5]);
    expect(slides[0].title).toBe('Campus services');
    expect(slides[1]).toMatchObject({ title: '', subtitle: '', fitMode: 'cover' });
  });
});

describe('public carousel endpoint', () => {
  it('uses GET api/v1/auth/carousel with authenticated:false and no bearer, even with a live token', async () => {
    client.setAccessToken('private-access-token');
    const payload = { data: { slides: [validSlide] } };
    const request = jest.spyOn(client, 'request');
    jest.mocked(globalThis.fetch).mockResolvedValueOnce(new Response(JSON.stringify(payload), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }));

    await expect(loginCarousel()).resolves.toEqual(payload);
    expect(request).toHaveBeenCalledWith('api/v1/auth/carousel', { authenticated: false });
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    const [url, options] = jest.mocked(globalThis.fetch).mock.calls[0];
    expect(url).toBe('https://api.test/api/v1/auth/carousel');
    expect(options?.method).toBe('GET');
    expect(options?.body).toBeUndefined();
    expect(new Headers(options?.headers).get('Authorization')).toBeNull();
    expect(getRefreshToken).not.toHaveBeenCalled();
  });

  it('does not refresh or retry a public carousel 401', async () => {
    client.setAccessToken('private-access-token');
    jest.mocked(globalThis.fetch).mockResolvedValueOnce(new Response(JSON.stringify({
      error: { code: 'unauthenticated', message: 'Not available.' },
    }), { status: 401 }));

    await expect(loginCarousel()).rejects.toMatchObject({ status: 401, code: 'unauthenticated' });
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    expect(getRefreshToken).not.toHaveBeenCalled();
    expect(client.getAccessToken()).toBe('private-access-token');
  });
});
