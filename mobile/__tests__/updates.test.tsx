import React from 'react';
import { ActivityIndicator, FlatList, Text } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { useIsFocused } from '@react-navigation/native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ApiError, request } from '../src/api/client';
import { queryKeys } from '../src/api/queryKeys';
import { Action } from '../src/components/Action';
import { PageHeader } from '../src/components/PageHeader';
import { AnnouncementsScreen } from '../src/features/announcements/screens/AnnouncementsScreen';
import { AnnouncementDetailScreen } from '../src/features/announcements/screens/AnnouncementDetailScreen';
import { announcementDetail, announcementListing } from '../src/features/announcements/model';
import { NotificationsScreen } from '../src/features/notifications/screens/NotificationsScreen';
import { useNotificationInbox } from '../src/features/notifications/hooks';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/api/client', () => ({ ...jest.requireActual('../src/api/client'), request: jest.fn() }));
jest.mock('../src/theme/ThemeProvider', () => ({
  useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }),
}));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View }));
jest.mock('react-native-linear-gradient', () => jest.requireActual('react-native').View);
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({ goBack: mockGoBack, navigate: mockNavigate }),
  useRoute: () => ({ params: { id: 41 } }),
  useIsFocused: jest.fn(),
}));
jest.mock('lucide-react-native', () => ({
  ArrowLeft: () => null, Bell: () => null, BellRing: () => null,
  BookOpenText: () => null, CalendarCheck2: () => null, CalendarDays: () => null,
  ClipboardList: () => null, HeartPulse: () => null, Megaphone: () => null,
}));

const mockGoBack = jest.fn();
const mockNavigate = jest.fn();
const mockRequest = request as jest.Mock;
let renderer: ReactTestRenderer | undefined;
let client: QueryClient;
let element: React.ReactElement<{ children: React.ReactElement }>;

const story = {
  id: '41', unit: 'clinic' as const, title: 'Clinic hours for exam week', audience: 'all',
  published_at: '2026-10-26 08:30:00', ends_at: null,
  excerpt: 'The clinic will stay open later during exams.', read_at: null,
};
const secondStory = { ...story, id: '40', unit: 'guidance' as const, title: 'Counseling availability', excerpt: 'New appointment slots are available.' };
const article = { ...story, body: 'Full announcement text from the server.\nBring your university ID.' };
const announcementPage = (page = 1, pages = 2, items = [story, secondStory]) => ({
  data: items, meta: { page, pages, total: 30, per_page: 25, filtered: 30 },
  counts: { all: 30, unread: 30, read: 0 },
});
const notification = {
  id: 81, title: 'New announcement', message: 'A campus announcement is available in your portal.',
  created_at: '2026-10-26T08:30:00Z', read_at: null as string | null,
  url: 'https://portal.test/announcements',
};
const notificationPage = (page = 1, pages = 2, items = [notification], unread = 2) => ({
  data: { items, unread_count: unread, total: 21, page, pages, filter: 'all', per_page: 20 },
});

async function settle() {
  // Query notifications are batched onto timers; requests themselves resolve as microtasks.
  for (let i = 0; i < 3; i += 1) {
    await act(async () => { await jest.advanceTimersByTimeAsync(1); });
  }
}

async function render(screen: React.ReactElement) {
  element = <QueryClientProvider client={client}>{screen}</QueryClientProvider>;
  act(() => { renderer = create(element); });
  await settle();
}

async function refocus(focused: boolean) {
  jest.mocked(useIsFocused).mockReturnValue(focused);
  act(() => { renderer!.update(<QueryClientProvider client={client}>{React.cloneElement(element.props.children)}</QueryClientProvider>); });
  await settle();
}

function text(value: string) {
  return renderer!.root.findAllByType(Text).some(node => node.props.children === value);
}

function action(label: string) {
  const found = renderer!.root.findAllByType(Action).find(node => node.props.children === label);
  if (!found) { throw new Error(`Missing action: ${label}`); }
  return found;
}

async function pressAction(label: string) {
  const button = action(label);
  expect(button.props.disabled).not.toBe(true);
  act(() => { button.props.onPress(); });
  await settle();
}

async function pressFilter(label: string) {
  const button = renderer!.root.findAll(node => typeof node.props.onPress === 'function').find(node =>
    node.findAllByType(Text).some(child => child.props.children === label));
  if (!button) { throw new Error(`Missing filter: ${label}`); }
  act(() => { button.props.onPress(); });
  await settle();
}

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  mockRequest.mockReset();
  mockGoBack.mockReset();
  mockNavigate.mockReset();
  jest.mocked(useIsFocused).mockReturnValue(true);
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } } });
});

afterEach(() => {
  act(() => { renderer?.unmount(); });
  renderer = undefined;
  client.clear();
  jest.useRealTimers();
});

describe('announcement API rows and list', () => {
  it('normalizes database IDs and preserves actual response fields', () => {
    const response = announcementListing(announcementPage());
    expect(response.data[0]).toEqual({ ...story, id: 41 });
    expect(response.meta).toEqual(announcementPage().meta);
    expect(announcementDetail({ data: article }).data).toEqual({ ...article, id: 41 });
  });

  it('uses real featured/latest stories, pages in both directions, and resets filters', async () => {
    mockRequest.mockImplementation((path: string) => Promise.resolve(
      path.includes('page=2') ? announcementPage(2, 2, [{ ...story, id: '15', title: 'Older update' }]) : announcementPage(),
    ));
    await render(<AnnouncementsScreen />);
    expect(text('Featured')).toBe(true);
    expect(text(story.title)).toBe(true);
    expect(text(story.excerpt)).toBe(true);
    expect(text('LATEST')).toBe(true);
    expect(renderer!.root.findByType(FlatList).props.data).toEqual([{ ...secondStory, id: 40 }]);
    const featured = renderer!.root.findAll(node => node.props.accessibilityLabel === story.title && typeof node.props.onPress === 'function')[0];
    act(() => { featured.props.onPress(); });
    expect(mockNavigate).toHaveBeenCalledWith('AnnouncementDetail', { id: 41 });

    expect(action('Previous').props.disabled).toBe(true);
    await pressAction('Next');
    expect(mockRequest).toHaveBeenLastCalledWith('api/v1/announcements?unit=all&status=all&page=2');
    expect(text('Featured')).toBe(false);
    expect(text('Older update')).toBe(true);
    expect(action('Next').props.disabled).toBe(true);
    await pressAction('Previous');
    expect(text(story.title)).toBe(true);
    await pressAction('Next');
    await pressFilter('Guidance');
    expect(mockRequest).toHaveBeenLastCalledWith('api/v1/announcements?unit=guidance&status=all&page=1');
    await pressFilter('Unread');
    expect(mockRequest).toHaveBeenLastCalledWith('api/v1/announcements?unit=all&status=unread&page=1');
  });

  it('does not turn a failed page into empty content, supports Retry, and keeps Previous on page failure', async () => {
    mockRequest.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(announcementPage());
    await render(<AnnouncementsScreen />);
    expect(text('Could not load announcements.')).toBe(true);
    expect(text('No announcements')).toBe(false);
    await pressAction('Retry');
    expect(text(story.title)).toBe(true);
    mockRequest.mockRejectedValueOnce(new Error('page unavailable'));
    await pressAction('Next');
    expect(text('Could not load announcements.')).toBe(true);
    expect(action('Previous').props.disabled).toBe(false);
    await pressAction('Previous');
    expect(text(story.title)).toBe(true);
  });

  it('shows an empty state only for a successful empty response', async () => {
    mockRequest.mockResolvedValue(announcementPage(1, 1, []));
    await render(<AnnouncementsScreen />);
    expect(text('No announcements')).toBe(true);
    expect(renderer!.root.findAllByType(Action)).toHaveLength(0);
  });

  it('retains cached stories with an explicit refresh error and working Retry', async () => {
    mockRequest.mockResolvedValueOnce(announcementPage()).mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(announcementPage());
    await render(<AnnouncementsScreen />);
    act(() => { renderer!.root.findByType(FlatList).props.onRefresh(); });
    await settle();
    expect(text(story.title)).toBe(true);
    expect(text('Could not refresh announcements. Showing saved updates.')).toBe(true);
    await pressAction('Retry');
    expect(text('Could not refresh announcements. Showing saved updates.')).toBe(false);
  });
});

describe('announcement detail navigation and state', () => {
  it('keeps the back header while loading', async () => {
    mockRequest.mockImplementation(() => new Promise(() => {}));
    await render(<AnnouncementDetailScreen />);
    expect(renderer!.root.findAllByType(ActivityIndicator)).toHaveLength(1);
    act(() => { renderer!.root.findByType(PageHeader).props.onBack(); });
    expect(mockGoBack).toHaveBeenCalledTimes(1);
  });

  it.each([
    [new Error('offline'), 'Could not load this announcement.'],
    [new ApiError(404, { code: 'not_found', message: 'Announcement not found.' }), 'This announcement is no longer available.'],
  ])('keeps back navigation and retries detail errors (%#)', async (error, message) => {
    mockRequest.mockRejectedValueOnce(error).mockResolvedValue({ data: { ...article, read_at: '2026-10-26 09:00:00' } });
    await render(<AnnouncementDetailScreen />);
    expect(text(message)).toBe(true);
    act(() => { renderer!.root.findByType(PageHeader).props.onBack(); });
    expect(mockGoBack).toHaveBeenCalledTimes(1);
    await pressAction('Retry');
    expect(text(article.title)).toBe(true);
    expect(text(article.body)).toBe(true);
    expect(renderer!.root.findByType(PageHeader).props.onBack).toBe(mockGoBack);
  });

  it('marks an opened unread detail using its numeric ID', async () => {
    let read = false;
    mockRequest.mockImplementation((path: string) => {
      if (path.endsWith('/read')) {
        read = true;
        return Promise.resolve({ data: { ...article, read_at: '2026-10-26 09:00:00' } });
      }
      return Promise.resolve({ data: { ...article, read_at: read ? '2026-10-26 09:00:00' : null } });
    });
    await render(<AnnouncementDetailScreen />);
    expect(mockRequest).toHaveBeenCalledWith('api/v1/announcements/41/read', { method: 'POST' });
    expect(mockRequest.mock.calls.filter(([path]) => path.endsWith('/read'))).toHaveLength(1);
    expect(text(article.body)).toBe(true);
  });
});

describe('notification history, polling and read state', () => {
  it('uses only paged history, supports previous/next and unread filtering, and polls the displayed source', async () => {
    mockRequest.mockImplementation((path: string) => Promise.resolve(notificationPage(path.includes('page=2') ? 2 : 1)));
    await render(<NotificationsScreen />);
    expect(mockRequest).toHaveBeenCalledTimes(1);
    expect(mockRequest).toHaveBeenLastCalledWith('api/v1/notifications/history?page=1&filter=all');
    await pressAction('Next');
    expect(mockRequest).toHaveBeenLastCalledWith('api/v1/notifications/history?page=2&filter=all');
    expect(action('Next').props.disabled).toBe(true);
    await pressAction('Previous');
    expect(mockRequest).toHaveBeenLastCalledWith('api/v1/notifications/history?page=1&filter=all');
    await pressAction('Next');
    await pressFilter('Unread');
    expect(mockRequest).toHaveBeenLastCalledWith('api/v1/notifications/history?page=1&filter=unread');

    const calls = mockRequest.mock.calls.length;
    mockRequest.mockResolvedValueOnce(notificationPage(1, 2, [{ ...notification, title: 'New survey' }]));
    await act(async () => { await jest.advanceTimersByTimeAsync(30000); });
    await settle();
    expect(mockRequest.mock.calls.length).toBe(calls + 1);
    expect(text('New survey')).toBe(true);
    expect(text(notification.title)).toBe(false);
    await refocus(false);
    const blurredCalls = mockRequest.mock.calls.length;
    await act(async () => { await jest.advanceTimersByTimeAsync(90000); });
    expect(mockRequest.mock.calls.length).toBe(blurredCalls);
    await refocus(true);
    expect(mockRequest.mock.calls.length).toBe(blurredCalls + 1);
    expect(mockRequest.mock.calls.every(([path]) => path.startsWith('api/v1/notifications/history?'))).toBe(true);
  });

  it('does not fetch in the background on mount and resumes on focus', async () => {
    jest.mocked(useIsFocused).mockReturnValue(false);
    mockRequest.mockResolvedValue(notificationPage());
    await render(<NotificationsScreen />);
    expect(mockRequest).not.toHaveBeenCalled();
    await refocus(true);
    expect(mockRequest).toHaveBeenCalledTimes(1);
    expect(text(notification.title)).toBe(true);
  });

  it('marks one and then all read, refreshing the authoritative history and invalidating the inbox cache', async () => {
    const other = { ...notification, id: 80, title: 'Appointment confirmed' };
    let items = [notification, other];
    client.setQueryData(queryKeys.notifications, { data: { items, unread_count: 2 } });
    mockRequest.mockImplementation((path: string) => {
      if (path.endsWith('/81/read')) {
        items = items.map(item => item.id === 81 ? { ...item, read_at: '2026-10-26T09:00:00Z' } : item);
        return Promise.resolve(undefined);
      }
      if (path.endsWith('/read-all')) {
        items = items.map(item => ({ ...item, read_at: '2026-10-26T09:00:00Z' }));
        return Promise.resolve(undefined);
      }
      return Promise.resolve(notificationPage(1, 1, items, items.filter(item => item.read_at === null).length));
    });
    await render(<NotificationsScreen />);
    const row = renderer!.root.findAll(node => node.props.accessibilityLabel?.startsWith(notification.title) && typeof node.props.onPress === 'function')[0];
    act(() => { row.props.onPress(); });
    await settle();
    expect(mockRequest).toHaveBeenCalledWith('api/v1/notifications/81/read', { method: 'POST' });
    expect(renderer!.root.findByType(PageHeader).props.subtitle).toBe('1 unread');
    expect(renderer!.root.findAll(node => node.props.accessibilityLabel?.startsWith(notification.title) && typeof node.props.onPress === 'function')[0].props.disabled).toBe(true);
    expect(client.getQueryState(queryKeys.notifications)?.isInvalidated).toBe(true);
    await pressAction('Mark all read');
    expect(mockRequest).toHaveBeenCalledWith('api/v1/notifications/read-all', { method: 'POST' });
    expect(renderer!.root.findByType(PageHeader).props.subtitle).toBe('You are all caught up');
    expect(renderer!.root.findAllByType(Action).some(node => node.props.children === 'Mark all read')).toBe(false);
  });

  it('distinguishes a history error from empty and retries the same source', async () => {
    mockRequest.mockRejectedValueOnce(new Error('offline')).mockResolvedValue(notificationPage(1, 1, [], 0));
    await render(<NotificationsScreen />);
    expect(text('Could not load notifications.')).toBe(true);
    expect(text('Nothing yet')).toBe(false);
    expect(renderer!.root.findByType(PageHeader).props.subtitle).toBe('Your in-app updates');
    await pressAction('Retry');
    expect(text('Nothing yet')).toBe(true);
    expect(mockRequest.mock.calls.every(([path]) => path.startsWith('api/v1/notifications/history?'))).toBe(true);
  });

  it('keeps cached notifications visible when refreshing fails', async () => {
    mockRequest.mockResolvedValueOnce(notificationPage()).mockRejectedValueOnce(new Error('offline')).mockResolvedValue(notificationPage());
    await render(<NotificationsScreen />);
    act(() => { renderer!.root.findByType(FlatList).props.onRefresh(); });
    await settle();
    expect(text(notification.title)).toBe(true);
    expect(text('Could not refresh notifications. Showing saved updates.')).toBe(true);
    await pressAction('Retry');
    expect(text('Could not refresh notifications. Showing saved updates.')).toBe(false);
  });

  it('removes a read item from the unread filter after the server confirms it', async () => {
    let read = false;
    mockRequest.mockImplementation((path: string) => {
      if (path.endsWith('/81/read')) {
        read = true;
        return Promise.resolve(undefined);
      }
      return Promise.resolve(notificationPage(1, 1, read ? [] : [notification], read ? 0 : 1));
    });
    await render(<NotificationsScreen />);
    await pressFilter('Unread');
    const row = renderer!.root.findAll(node => node.props.accessibilityLabel?.startsWith(notification.title) && typeof node.props.onPress === 'function')[0];
    act(() => { row.props.onPress(); });
    await settle();
    expect(text(notification.title)).toBe(false);
    expect(text('No unread notifications')).toBe(true);
    expect(mockRequest).toHaveBeenLastCalledWith('api/v1/notifications/history?page=1&filter=unread');
  });

  it('keeps unread state on a failed single-read mutation and offers a retry', async () => {
    mockRequest.mockResolvedValue(notificationPage(1, 1));
    await render(<NotificationsScreen />);
    mockRequest.mockRejectedValueOnce(new Error('write failed'));
    const row = renderer!.root.findAll(node => node.props.accessibilityLabel?.startsWith(notification.title) && typeof node.props.onPress === 'function')[0];
    act(() => { row.props.onPress(); });
    await settle();
    expect(text('Could not update read status. Please try again.')).toBe(true);
    expect(renderer!.root.findByType(PageHeader).props.subtitle).toBe('2 unread');
    await pressAction('Retry marking read');
    expect(mockRequest).toHaveBeenCalledWith('api/v1/notifications/81/read', { method: 'POST' });
    expect(text('Could not update read status. Please try again.')).toBe(false);
  });

  it('retries a failed mark-all mutation and reports the backend unread-count cap honestly', async () => {
    mockRequest.mockResolvedValue(notificationPage(1, 1, [notification], 100));
    await render(<NotificationsScreen />);
    expect(renderer!.root.findByType(PageHeader).props.subtitle).toBe('100+ unread');
    mockRequest.mockRejectedValueOnce(new Error('write failed'));
    await pressAction('Mark all read');
    expect(text('Could not update read status. Please try again.')).toBe(true);
    await pressAction('Retry marking read');
    expect(mockRequest.mock.calls.filter(([path]) => path.endsWith('/read-all'))).toHaveLength(2);
    expect(text('Could not update read status. Please try again.')).toBe(false);
  });

  it('clears an old single-read failure when mark-all succeeds', async () => {
    mockRequest.mockResolvedValue(notificationPage(1, 1));
    await render(<NotificationsScreen />);
    mockRequest.mockRejectedValueOnce(new Error('write failed'));
    const row = renderer!.root.findAll(node => node.props.accessibilityLabel?.startsWith(notification.title) && typeof node.props.onPress === 'function')[0];
    act(() => { row.props.onPress(); });
    await settle();
    expect(text('Could not update read status. Please try again.')).toBe(true);
    mockRequest.mockResolvedValueOnce(undefined).mockResolvedValue(notificationPage(1, 1, [{ ...notification, read_at: '2026-10-26T09:00:00Z' }], 0));
    await pressAction('Mark all read');
    expect(text('Could not update read status. Please try again.')).toBe(false);
    expect(renderer!.root.findByType(PageHeader).props.subtitle).toBe('You are all caught up');
  });

  it('preserves focus-aware polling for the dashboard inbox consumer', async () => {
    function Inbox() { useNotificationInbox(); return null; }
    mockRequest.mockResolvedValue({ data: { items: [], unread_count: 0 } });
    await render(<Inbox />);
    expect(mockRequest).toHaveBeenLastCalledWith('api/v1/notifications');
    await refocus(false);
    const calls = mockRequest.mock.calls.length;
    await act(async () => { await jest.advanceTimersByTimeAsync(30000); });
    expect(mockRequest).toHaveBeenCalledTimes(calls);
    await refocus(true);
    expect(mockRequest).toHaveBeenCalledTimes(calls + 1);
  });
});
