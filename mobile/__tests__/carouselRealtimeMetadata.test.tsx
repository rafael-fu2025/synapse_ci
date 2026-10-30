import React from 'react';
import { AccessibilityInfo, AppState, AppStateStatus, Text } from 'react-native';
import { QueryClient, QueryClientProvider, onlineManager } from '@tanstack/react-query';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { loginCarousel, loginCarouselRealtime, LoginCarouselSlide } from '../src/api/endpoints/auth';
import { LoginCarousel } from '../src/features/auth/components/LoginCarousel';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test' } }));
jest.mock('../src/api/endpoints/auth', () => ({ loginCarousel: jest.fn(), loginCarouselRealtime: jest.fn() }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('react-native-linear-gradient', () => ({ __esModule: true, default: jest.requireActual('react-native').View }));
jest.mock('../src/theme/ThemeProvider', () => ({
  useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }),
}));

const originalWebSocket = globalThis.WebSocket;
const originalAppState = Object.getOwnPropertyDescriptor(AppState, 'currentState')!;
class Socket {
  static current: Socket;
  onopen: (() => void) | null = null;
  onmessage: ((event: { data: string }) => void) | null = null;
  onerror: (() => void) | null = null;
  onclose: (() => void) | null = null;
  send = jest.fn();
  close = jest.fn();
  constructor() { Socket.current = this; }
  message(data: unknown) { this.onmessage?.({ data: JSON.stringify(data) }); }
}
const rows: LoginCarouselSlide[] = [11, 12, 13].map(id => ({
  id, title: `Slide ${id}`, subtitle: null, light_image: `uploads/carousel/${id}.png`,
  dark_image: null, fit_mode: 'cover',
}));
let currentRows: LoginCarouselSlide[];
let client: QueryClient;
let renderer: ReactTestRenderer;
let appChange: (state: AppStateStatus) => void;
function tree(active = true) {
  return <QueryClientProvider client={client}><LoginCarousel active={active} /></QueryClientProvider>;
}
async function flush(ms = 0) { await act(async () => { await jest.advanceTimersByTimeAsync(ms); }); }
function selected() {
  return renderer.root.findAllByProps({ accessibilityRole: 'button' }, { deep: false })
    .find(node => node.props.accessibilityState.selected)?.props.accessibilityLabel;
}
async function eventRefresh() {
  Socket.current.message({ type: 'event', channel: 'public.tenant.7.login-carousel', event: 'login.carousel.changed', data: [] });
  await flush(101);
}

beforeEach(async () => {
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  onlineManager.setOnline(true);
  Object.defineProperty(AppState, 'currentState', { configurable: true, writable: true, value: 'active' });
  globalThis.WebSocket = Socket as unknown as typeof WebSocket;
  jest.spyOn(AppState, 'addEventListener').mockImplementation((_, listener) => {
    appChange = listener;
    return { remove: jest.fn() };
  });
  jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(true);
  jest.spyOn(AccessibilityInfo, 'isScreenReaderEnabled').mockResolvedValue(false);
  jest.spyOn(AccessibilityInfo, 'addEventListener').mockReturnValue({ remove: jest.fn() });
  currentRows = rows;
  jest.mocked(loginCarousel).mockReset().mockImplementation(async () => ({ data: { slides: currentRows } }));
  jest.mocked(loginCarouselRealtime).mockReset().mockImplementation(async () => ({ data: {
    enabled: true, url: 'wss://socket.test/ws', origin: 'https://api.test', channel: 'public.tenant.7.login-carousel',
    ticket: 'secret', expires_at: Math.floor(Date.now() / 1000) + 60,
  } }));
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
  await act(async () => { renderer = create(tree()); });
  await flush(101);
  Socket.current.onopen?.();
  Socket.current.message({ type: 'ready' });
  await flush(101);
});
afterEach(() => {
  act(() => renderer.unmount());
  client.clear();
  globalThis.WebSocket = originalWebSocket;
  Object.defineProperty(AppState, 'currentState', originalAppState);
  jest.restoreAllMocks();
  jest.useRealTimers();
});

it.each(['initial', 'manual'] as const)('retains the %s selected ID across event-driven reordering and metadata changes', async choice => {
  if (choice === 'manual') {
    act(() => renderer.root.findAllByProps({ accessibilityRole: 'button' }, { deep: false })[1].props.onPress());
  }
  const id = choice === 'initial' ? 11 : 12;
  expect(selected()).toContain(`Slide ${id}`);
  currentRows = [...rows].reverse().map(row => ({ ...row, title: `Updated ${row.id}` }));
  await eventRefresh();
  expect(selected()).toContain(`Updated ${id}`);
  expect(renderer.root.findAllByType(Text).some(node => node.props.children === `Updated ${id}`)).toBe(true);
  expect(client.getQueryData(['auth', 'carousel'])).toEqual(expect.arrayContaining([expect.objectContaining({ id, title: `Updated ${id}` })]));
});

it('selects an available slide when the current ID is removed', async () => {
  act(() => renderer.root.findAllByProps({ accessibilityRole: 'button' }, { deep: false })[2].props.onPress());
  currentRows = [rows[0]];
  await eventRefresh();
  expect(renderer.root.findAllByType(Text).some(node => node.props.children === 'Slide 11')).toBe(true);
  expect(renderer.root.findAllByProps({ accessibilityRole: 'button' }, { deep: false })).toHaveLength(0);
});

it('wires the real component keyboard and AppState gates without restarting a socket while hidden', async () => {
  const ws = Socket.current;
  await act(async () => { renderer.update(tree(false)); });
  expect(ws.close).toHaveBeenCalledTimes(1);
  const grants = jest.mocked(loginCarouselRealtime).mock.calls.length;
  await act(async () => { appChange('background'); appChange('active'); });
  await flush(120_000);
  expect(loginCarouselRealtime).toHaveBeenCalledTimes(grants);
  await act(async () => { renderer.update(tree(true)); });
  await flush();
  expect(loginCarouselRealtime).toHaveBeenCalledTimes(grants + 1);
  const resumed = Socket.current;
  await act(async () => { appChange('background'); });
  expect(resumed.close).toHaveBeenCalledTimes(1);
  await flush(120_000);
  expect(loginCarouselRealtime).toHaveBeenCalledTimes(grants + 1);
  await act(async () => { appChange('active'); });
  await flush();
  expect(loginCarouselRealtime).toHaveBeenCalledTimes(grants + 2);
});
