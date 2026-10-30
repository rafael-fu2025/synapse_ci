import React from 'react';
import { AccessibilityInfo, Animated, AppState, AppStateStatus, Image, Text, View } from 'react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { loginCarousel, LoginCarouselSlide } from '../src/api/endpoints/auth';
import { LoginCarousel } from '../src/features/auth/components/LoginCarousel';
import { FALLBACK_LOGIN_SLIDES } from '../src/features/auth/loginContent';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test' } }));
jest.mock('../src/api/endpoints/auth', () => ({ loginCarousel: jest.fn() }));
jest.mock('../src/features/auth/realtime/useCarouselRealtime', () => ({ useCarouselRealtime: jest.fn() }));
jest.mock('react-native-linear-gradient', () => ({ __esModule: true, default: jest.requireActual('react-native').View }));
jest.mock('../src/theme/ThemeProvider', () => ({
  useTheme: () => {
    const tokens = jest.requireActual('../src/theme/tokens');
    return { theme: mockMode === 'dark' ? tokens.darkTheme : tokens.lightTheme };
  },
}));

const originalAppState = Object.getOwnPropertyDescriptor(AppState, 'currentState')!;
let mockMode: 'light' | 'dark' = 'light';
let renderer: ReactTestRenderer | undefined;
let client: QueryClient;
const accessibilityListeners = new Map<string, { emit: (value: boolean) => void; remove: jest.Mock }>();
let appSubscription: { emit: (state: AppStateStatus) => void; remove: jest.Mock };
const apiSlides: LoginCarouselSlide[] = [
  { id: 11, title: 'Campus news', subtitle: 'Welcome to campus', light_image: 'uploads/carousel/campus.jpg', dark_image: 'uploads/carousel/campus-dark.webp', fit_mode: 'cover' },
  { id: 12, title: 'Guidance services', subtitle: 'Find support', light_image: 'assets/img/brand/guidance.png', dark_image: 'assets/img/brand/guidance-dark.png', fit_mode: 'contain_blur' },
  { id: 13, title: 'Health services', subtitle: 'Stay well', light_image: 'uploads/carousel/health.png', dark_image: null, fit_mode: 'cover' },
];

function composition(active: boolean) {
  return <QueryClientProvider client={client}><LoginCarousel active={active} /></QueryClientProvider>;
}

async function renderCarousel(active = true) {
  await act(async () => { renderer = create(composition(active)); });
  // Query notifications are scheduled separately from the query promise.
  await act(async () => { await jest.advanceTimersByTimeAsync(0); });
}

function update(active: boolean) {
  act(() => renderer!.update(composition(active)));
}

function advance(milliseconds = 6000) {
  act(() => jest.advanceTimersByTime(milliseconds));
}

function indicators() {
  return renderer!.root.findAllByProps({ accessibilityRole: 'button' }, { deep: false });
}

function selectedIndex() {
  return indicators().findIndex(node => node.props.accessibilityState.selected);
}

function foregroundImage() {
  return renderer!.root.findAllByType(Image).find(node => typeof node.props.onError === 'function')!;
}

function hasText(text: string) {
  return renderer!.root.findAllByType(Text).some(node => node.props.children === text);
}

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  mockMode = 'light';
  accessibilityListeners.clear();
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
  jest.mocked(loginCarousel).mockReset().mockResolvedValue({ data: { slides: apiSlides } });
  Object.defineProperty(AppState, 'currentState', { configurable: true, writable: true, value: 'active' });
  jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(false);
  jest.spyOn(AccessibilityInfo, 'isScreenReaderEnabled').mockResolvedValue(false);
  jest.spyOn(AccessibilityInfo, 'addEventListener').mockImplementation((event, listener) => {
    const subscription = { emit: (value: boolean) => (listener as (enabled: boolean) => void)(value), remove: jest.fn() };
    accessibilityListeners.set(event, subscription);
    return subscription;
  });
  jest.spyOn(AppState, 'addEventListener').mockImplementation((event, listener) => {
    expect(event).toBe('change');
    appSubscription = { emit: listener, remove: jest.fn() };
    return appSubscription;
  });
  jest.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('Unexpected network request'));
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  client.clear();
  expect(globalThis.fetch).not.toHaveBeenCalled();
  jest.restoreAllMocks();
  Object.defineProperty(AppState, 'currentState', originalAppState);
  jest.useRealTimers();
});

describe('carousel pacing and accessibility', () => {
  it('auto-rotates at exactly six seconds and wraps back to the first slide', async () => {
    await renderCarousel();
    expect(loginCarousel).toHaveBeenCalledTimes(1);
    expect(selectedIndex()).toBe(0);
    advance(5999);
    expect(selectedIndex()).toBe(0);
    advance(1);
    expect(selectedIndex()).toBe(1);
    advance();
    expect(selectedIndex()).toBe(2);
    advance();
    expect(selectedIndex()).toBe(0);
    expect(loginCarousel).toHaveBeenCalledTimes(1);
  });

  it.each(['inactive prop', 'background', 'inactive app', 'reduced motion', 'screen reader'] as const)(
    'does not auto-rotate with %s, but indicators remain manually usable', async reason => {
      if (reason === 'background') { jest.replaceProperty(AppState, 'currentState', 'background'); }
      if (reason === 'inactive app') { jest.replaceProperty(AppState, 'currentState', 'inactive'); }
      if (reason === 'reduced motion') { jest.mocked(AccessibilityInfo.isReduceMotionEnabled).mockResolvedValue(true); }
      if (reason === 'screen reader') { jest.mocked(AccessibilityInfo.isScreenReaderEnabled).mockResolvedValue(true); }
      await renderCarousel(reason !== 'inactive prop');
      advance(24000);
      expect(selectedIndex()).toBe(0);
      expect(indicators()[1].props.accessibilityLabel).toBe('Show slide 2: Guidance services');
      act(() => indicators()[1].props.onPress());
      expect(selectedIndex()).toBe(1);
      expect(hasText('Guidance services')).toBe(true);
      advance(24000);
      expect(selectedIndex()).toBe(1);
    },
  );

  it('cancels the active timer while hidden and resumes with a fresh six-second interval', async () => {
    await renderCarousel();
    advance(5000);
    update(false);
    advance(24000);
    expect(selectedIndex()).toBe(0);
    update(true);
    advance(5999);
    expect(selectedIndex()).toBe(0);
    advance(1);
    expect(selectedIndex()).toBe(1);
  });

  it.each(['background', 'inactive'] as const)('pauses on an AppState %s event and resumes in foreground', async state => {
    await renderCarousel();
    advance(5000);
    act(() => appSubscription.emit(state));
    advance(24000);
    expect(selectedIndex()).toBe(0);
    act(() => appSubscription.emit('active'));
    advance(5999);
    expect(selectedIndex()).toBe(0);
    advance(1);
    expect(selectedIndex()).toBe(1);
  });

  it.each(['reduceMotionChanged', 'screenReaderChanged'] as const)('responds to live %s events', async event => {
    await renderCarousel();
    advance(5000);
    act(() => accessibilityListeners.get(event)!.emit(true));
    advance(24000);
    expect(selectedIndex()).toBe(0);
    act(() => indicators()[2].props.onPress());
    expect(selectedIndex()).toBe(2);
    act(() => accessibilityListeners.get(event)!.emit(false));
    advance(5999);
    expect(selectedIndex()).toBe(2);
    advance(1);
    expect(selectedIndex()).toBe(0);
  });

  it('requires both accessibility flags to be disabled before resuming', async () => {
    await renderCarousel();
    act(() => {
      accessibilityListeners.get('reduceMotionChanged')!.emit(true);
      accessibilityListeners.get('screenReaderChanged')!.emit(true);
    });
    act(() => accessibilityListeners.get('reduceMotionChanged')!.emit(false));
    advance(24000);
    expect(selectedIndex()).toBe(0);
    act(() => accessibilityListeners.get('screenReaderChanged')!.emit(false));
    advance();
    expect(selectedIndex()).toBe(1);
  });

  it('stays paused until the initial accessibility checks have resolved', async () => {
    let resolveMotion!: (enabled: boolean) => void;
    let resolveReader!: (enabled: boolean) => void;
    jest.mocked(AccessibilityInfo.isReduceMotionEnabled).mockImplementationOnce(() => new Promise(resolve => { resolveMotion = resolve; }));
    jest.mocked(AccessibilityInfo.isScreenReaderEnabled).mockImplementationOnce(() => new Promise(resolve => { resolveReader = resolve; }));
    await renderCarousel();
    advance(24000);
    expect(selectedIndex()).toBe(0);
    await act(async () => resolveMotion(false));
    advance(24000);
    expect(selectedIndex()).toBe(0);
    await act(async () => resolveReader(false));
    advance();
    expect(selectedIndex()).toBe(1);
  });

  it('fails safely to manual navigation if accessibility checks reject', async () => {
    jest.mocked(AccessibilityInfo.isReduceMotionEnabled).mockRejectedValueOnce(new Error('Unavailable'));
    jest.mocked(AccessibilityInfo.isScreenReaderEnabled).mockRejectedValueOnce(new Error('Unavailable'));
    await renderCarousel();
    advance(24000);
    expect(selectedIndex()).toBe(0);
    act(() => indicators()[1].props.onPress());
    expect(selectedIndex()).toBe(1);
  });

  it('does not create a rotation interval or indicators for one valid slide', async () => {
    jest.mocked(loginCarousel).mockResolvedValueOnce({ data: { slides: [apiSlides[0]] } });
    const interval = jest.spyOn(globalThis, 'setInterval');
    await renderCarousel();
    expect(indicators()).toHaveLength(0);
    expect(interval).not.toHaveBeenCalled();
    advance(24000);
    expect(hasText('Campus news')).toBe(true);
  });

  it('removes native subscriptions and its interval on unmount', async () => {
    const interval = jest.spyOn(globalThis, 'setInterval');
    const clear = jest.spyOn(globalThis, 'clearInterval');
    await renderCarousel();
    expect([...accessibilityListeners.keys()]).toEqual(['reduceMotionChanged', 'screenReaderChanged']);
    expect(interval).toHaveBeenCalledWith(expect.any(Function), 6000);
    const timer = interval.mock.results[interval.mock.results.length - 1].value;
    act(() => renderer!.unmount());
    renderer = undefined;
    expect(clear).toHaveBeenCalledWith(timer);
    expect(appSubscription.remove).toHaveBeenCalledTimes(1);
    accessibilityListeners.forEach(subscription => expect(subscription.remove).toHaveBeenCalledTimes(1));
    advance(24000);
  });

  it('ignores accessibility promises settling after unmount', async () => {
    let resolveMotion!: (enabled: boolean) => void;
    let resolveReader!: (enabled: boolean) => void;
    jest.mocked(AccessibilityInfo.isReduceMotionEnabled).mockImplementationOnce(() => new Promise(resolve => { resolveMotion = resolve; }));
    jest.mocked(AccessibilityInfo.isScreenReaderEnabled).mockImplementationOnce(() => new Promise(resolve => { resolveReader = resolve; }));
    const interval = jest.spyOn(globalThis, 'setInterval');
    await renderCarousel();
    act(() => renderer!.unmount());
    renderer = undefined;
    await act(async () => { resolveMotion(false); resolveReader(false); });
    expect(interval).not.toHaveBeenCalled();
    expect(appSubscription.remove).toHaveBeenCalledTimes(1);
  });
});

describe('carousel pill transitions', () => {
  it('smoothly expands and brightens the selected pill using native-driven motion', async () => {
    const timing = jest.spyOn(Animated, 'timing');
    await renderCarousel();
    timing.mockClear();
    act(() => indicators()[1].props.onPress());
    expect(selectedIndex()).toBe(1);
    expect(timing.mock.calls.map(([, options]) => options.toValue)).toEqual([0, 1]);
    timing.mock.calls.forEach(([, options]) => {
      expect(options).toMatchObject({ duration: 280, useNativeDriver: true, isInteraction: false });
    });
  });

  it('animates the pills on automatic slide changes too', async () => {
    const timing = jest.spyOn(Animated, 'timing');
    await renderCarousel();
    timing.mockClear();
    advance();
    expect(selectedIndex()).toBe(1);
    expect(timing.mock.calls.map(([, options]) => options.toValue)).toEqual([0, 1]);
  });

  it.each(['reduced motion', 'screen reader', 'hidden', 'background'] as const)('keeps manual selection instant with %s', async reason => {
    if (reason === 'reduced motion') { jest.mocked(AccessibilityInfo.isReduceMotionEnabled).mockResolvedValue(true); }
    if (reason === 'screen reader') { jest.mocked(AccessibilityInfo.isScreenReaderEnabled).mockResolvedValue(true); }
    if (reason === 'background') { jest.replaceProperty(AppState, 'currentState', 'background'); }
    const timing = jest.spyOn(Animated, 'timing');
    await renderCarousel(reason !== 'hidden');
    timing.mockClear();
    act(() => indicators()[1].props.onPress());
    expect(selectedIndex()).toBe(1);
    expect(timing).not.toHaveBeenCalled();
  });

  it('stops active transitions when Reduce Motion is enabled and restores motion when disabled', async () => {
    const timing = jest.spyOn(Animated, 'timing');
    await renderCarousel();
    const stops = timing.mock.results.slice(-apiSlides.length).map(result => jest.spyOn(result.value, 'stop'));
    timing.mockClear();
    act(() => accessibilityListeners.get('reduceMotionChanged')!.emit(true));
    stops.forEach(stop => expect(stop).toHaveBeenCalled());
    act(() => indicators()[1].props.onPress());
    expect(timing).not.toHaveBeenCalled();
    act(() => accessibilityListeners.get('reduceMotionChanged')!.emit(false));
    expect(timing).toHaveBeenCalledTimes(apiSlides.length);
  });

  it('stops pill transitions when hidden and on unmount', async () => {
    const timing = jest.spyOn(Animated, 'timing');
    await renderCarousel();
    const stops = timing.mock.results.slice(-apiSlides.length).map(result => jest.spyOn(result.value, 'stop'));
    update(false);
    stops.forEach(stop => expect(stop).toHaveBeenCalled());
    timing.mockClear();
    update(true);
    const resumedStops = timing.mock.results.map(result => jest.spyOn(result.value, 'stop'));
    expect(resumedStops).toHaveLength(apiSlides.length);
    act(() => renderer!.unmount());
    renderer = undefined;
    resumedStops.forEach(stop => expect(stop).toHaveBeenCalled());
  });
});

describe('carousel artwork and local fallback', () => {
  it.each(['light', 'dark'] as const)('selects %s artwork and implements cover versus contain_blur fit', async mode => {
    mockMode = mode;
    await renderCarousel(false);
    expect(renderer!.root.findAllByType(Image)).toHaveLength(1);
    expect(foregroundImage().props.source).toEqual({ uri: `https://api.test/uploads/carousel/campus${mode === 'dark' ? '-dark.webp' : '.jpg'}` });
    expect(foregroundImage().props.resizeMode).toBe('cover');
    expect(foregroundImage().props.accessible).toBe(false);

    act(() => indicators()[1].props.onPress());
    const images = renderer!.root.findAllByType(Image);
    expect(images).toHaveLength(2);
    expect(images[0].props).toMatchObject({ resizeMode: 'cover', blurRadius: 14, accessible: false });
    expect(images[1].props).toMatchObject({ resizeMode: 'contain', accessible: false });
    expect(images[0].props.source).toEqual(images[1].props.source);
    expect(images[1].props.source).toEqual({ uri: `https://api.test/assets/img/brand/guidance${mode === 'dark' ? '-dark' : ''}.png` });
    expect(hasText('Find support')).toBe(true);
  });

  it('switches artwork on a theme change without resetting the selected slide', async () => {
    await renderCarousel(false);
    act(() => indicators()[1].props.onPress());
    mockMode = 'dark';
    update(false);
    expect(selectedIndex()).toBe(1);
    expect(foregroundImage().props.source).toEqual({ uri: 'https://api.test/assets/img/brand/guidance-dark.png' });
    act(() => indicators()[2].props.onPress());
    expect(foregroundImage().props.source).toEqual({ uri: 'https://api.test/uploads/carousel/health.png' });
  });

  it('uses bundled web-default artwork while the API is pending', async () => {
    jest.mocked(loginCarousel).mockImplementationOnce(() => new Promise(() => {}));
    await renderCarousel(false);
    expect(indicators()).toHaveLength(3);
    expect(hasText('Foundation University')).toBe(true);
    expect(hasText('Health & Guidance Services Portal')).toBe(true);
    expect(foregroundImage().props.source).toEqual(FALLBACK_LOGIN_SLIDES[0].lightImage);
    expect(renderer!.root.findAllByType(View).some(node => node.props.testID === 'login-carousel')).toBe(true);
  });

  it.each(['light', 'dark'] as const)('keeps all local web defaults after an API error in %s mode without retrying', async mode => {
    mockMode = mode;
    jest.mocked(loginCarousel).mockRejectedValueOnce(new Error('API unavailable'));
    await renderCarousel(false);
    const titles = ['Foundation University', 'Guidance & Counseling Center', 'University Health Services'];
    titles.forEach((title, index) => {
      act(() => indicators()[index].props.onPress());
      expect(hasText(title)).toBe(true);
      expect(foregroundImage().props.source).toEqual(mode === 'dark' ? FALLBACK_LOGIN_SLIDES[index].darkImage : FALLBACK_LOGIN_SLIDES[index].lightImage);
    });
    advance(60000);
    expect(loginCarousel).toHaveBeenCalledTimes(1);
  });

  it('uses local defaults for an empty API slide list', async () => {
    jest.mocked(loginCarousel).mockResolvedValueOnce({ data: { slides: [] } });
    await renderCarousel(false);
    expect(indicators()).toHaveLength(3);
    expect(hasText('Foundation University')).toBe(true);
    expect(foregroundImage().props.source).toEqual(FALLBACK_LOGIN_SLIDES[0].lightImage);
  });

  it.each(['light', 'dark'] as const)('falls back to bundled %s artwork on image load failure', async mode => {
    mockMode = mode;
    await renderCarousel(false);
    act(() => indicators()[1].props.onPress());
    act(() => foregroundImage().props.onError());
    const fallback = mode === 'dark' ? FALLBACK_LOGIN_SLIDES[1].darkImage : FALLBACK_LOGIN_SLIDES[1].lightImage;
    expect(renderer!.root.findAllByType(Image).map(node => node.props.source)).toEqual([fallback, fallback]);
    expect(hasText('Guidance services')).toBe(true);
    act(() => indicators()[2].props.onPress());
    expect(foregroundImage().props.source).toEqual({ uri: 'https://api.test/uploads/carousel/health.png' });
  });
});
