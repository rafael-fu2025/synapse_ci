import React from 'react';
import { Text } from 'react-native';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { ApiError } from '../src/api/client';
import { EmptyState } from '../src/components/EmptyState';
import { Skeleton } from '../src/components/Skeleton';
import { QueueTicketCard } from '../src/features/queue/components/QueueTicketCard';
import { QueueBoardScreen } from '../src/features/queue/screens/QueueBoardScreen';
import { useQueueSnapshot } from '../src/features/queue/hooks';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: () => true }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }) }));
jest.mock('../src/features/queue/hooks', () => ({ useQueueSnapshot: jest.fn() }));
jest.mock('@react-navigation/native', () => ({ useNavigation: () => ({ navigate: jest.fn() }) }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View }));
jest.mock('react-native-linear-gradient', () => jest.requireActual('react-native').View);
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));

const ticket = { unit: 'clinic', ticket_code: 'C-042', status: 'waiting', priority: 'normal', position: 3, people_ahead: 2, estimated_wait_minutes: 15 };
let renderer: ReactTestRenderer | undefined;
const refetch = jest.fn().mockResolvedValue(undefined);
// These in-memory hook snapshots never execute HTTP, SQL or a database operation.
function mockSnapshot(state: object) {
  jest.mocked(useQueueSnapshot).mockReturnValue({ isLoading: false, isError: false, dataUpdatedAt: 0, refetch, ...state } as unknown as ReturnType<typeof useQueueSnapshot>);
}
function render() { act(() => { renderer = create(<QueueBoardScreen />); }); }
function hasText(value: string) { return renderer!.root.findAllByType(Text).some(node => node.props.children === value); }
afterEach(() => { act(() => renderer?.unmount()); renderer = undefined; jest.clearAllMocks(); });

it.each(['fetching', 'paused', 'idle'] as const)('separates initial queue %s from a successful empty response', fetchStatus => {
  mockSnapshot({ fetchStatus }); render();
  if (fetchStatus === 'fetching') {
    expect(renderer!.root.findByType(Skeleton).props.label).toBe('Loading queue…');
  } else {
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    expect(renderer!.root.findByType(EmptyState).props.title).toBe('Waiting to load queue');
  }
  expect(hasText('You’re not in a queue')).toBe(false);
});

it.each([{ tickets: [] }, { tickets: [ticket] }])('keeps cached queue content during refresh and transient failure (%#)', ({ tickets }) => {
  const data = { data: { date: '2026-11-09', tickets } };
  mockSnapshot({ data, isLoading: true, fetchStatus: 'fetching' }); render();
  expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
  const card = renderer!.root.findAllByType(QueueTicketCard)[0];
  mockSnapshot({ data, isError: true, error: new Error('Offline') });
  act(() => renderer!.update(<QueueBoardScreen />));
  expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
  if (tickets.length) { expect(renderer!.root.findByType(QueueTicketCard)).toBe(card); }
  else { expect(renderer!.root.findByType(EmptyState).props.title).toBe('You’re not in a queue'); }
});

it.each([401, 403, 404])('hides a cached ticket after an authoritative %s failure', status => {
  mockSnapshot({ data: { data: { tickets: [ticket] } }, isError: true, error: new ApiError(status, { code: 'denied', message: 'Unavailable' }) }); render();
  expect(renderer!.root.findAllByType(QueueTicketCard)).toHaveLength(0);
  expect(renderer!.root.findByType(EmptyState).props.title).toBe('Can’t load your queue');
});
