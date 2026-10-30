import React from 'react';
import { ActivityIndicator, StyleSheet, Text } from 'react-native';
import { useTheme } from '../src/theme/ThemeProvider';
import { darkTheme, lightTheme } from '../src/theme/tokens';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import type { QueueTicket } from '../src/api/endpoints/queue';
import {
  QueueTicketCard,
  QueueTicketPlaceholder,
  ticketPresentation,
} from '../src/features/queue/components/QueueTicketCard';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: jest.fn() }));
jest.mock('react-native-linear-gradient', () => jest.requireActual('react-native').View);
jest.mock('lucide-react-native', () => ({ ListChecks: () => null }));

const ticket: QueueTicket = {
  unit: 'clinic', ticket_code: 'C-042', status: 'waiting', priority: 'normal',
  position: 4, people_ahead: 3, estimated_wait_minutes: 20,
};
let renderer: ReactTestRenderer | undefined;

function render(element: React.ReactElement) {
  act(() => { renderer = create(element); });
}
function texts() {
  return renderer!.root.findAllByType(Text).map(node => React.Children.toArray(node.props.children).join(''));
}

beforeEach(() => {
  jest.mocked(useTheme).mockReturnValue({ theme: lightTheme, mode: 'light', preference: 'system', setPreference: jest.fn() });
});

afterEach(() => {
  act(() => { renderer?.unmount(); });
  renderer = undefined;
});

describe('ticketPresentation', () => {
  it('presents a waiting ticket using actual place, people ahead and estimate', () => {
    expect(ticketPresentation(ticket)).toEqual({
      statusLabel: 'Waiting', place: '#4', peopleAhead: '3', wait: '~20 min',
    });
  });

  it.each([
    { status: 'called', statusLabel: 'Now calling', place: 'Your turn', peopleAhead: '0', wait: 'Now' },
    { status: 'in_consultation', statusLabel: 'In consultation', place: 'In progress', peopleAhead: '0', wait: 'In progress' },
    { status: 'skipped', statusLabel: 'On hold', place: 'On hold', peopleAhead: '—', wait: 'Ask reception' },
  ] as const)('uses $status guidance instead of stale waiting metrics', ({ status, ...expected }) => {
    expect(ticketPresentation({ ...ticket, status })).toEqual(expected);
    expect(ticketPresentation({
      ...ticket, status, position: null, people_ahead: null, estimated_wait_minutes: null,
    })).toEqual(expected);
  });

  it('treats zero minutes and zero people ahead as real values, not missing data', () => {
    expect(ticketPresentation({
      ...ticket, position: 1, people_ahead: 0, estimated_wait_minutes: 0,
    })).toEqual({
      statusLabel: 'Waiting', place: '#1', peopleAhead: '0', wait: 'You’re next',
    });
  });

  it('does not invent a place, people ahead or wait when metrics are unknown', () => {
    expect(ticketPresentation({
      ...ticket, position: null, people_ahead: null, estimated_wait_minutes: null,
    })).toEqual({
      statusLabel: 'Waiting', place: '—', peopleAhead: '—', wait: 'Not enough data',
    });
  });

  it.each([0, -1])('does not show an invalid waiting position (%p)', position => {
    expect(ticketPresentation({ ...ticket, position }).place).toBe('—');
  });

  it('does not show negative ahead counts or wait estimates', () => {
    const view = ticketPresentation({ ...ticket, people_ahead: -1, estimated_wait_minutes: -5 });
    expect(view.peopleAhead).toBe('—');
    expect(view.wait).toBe('Not enough data');
  });
});

describe('QueueTicketCard', () => {
  it.each([
    { unit: 'clinic', code: 'C-042', label: 'Clinic' },
    { unit: 'guidance', code: 'G-007', label: 'Guidance' },
  ] as const)('renders the real $unit ticket and an accessible summary', ({ unit, code, label }) => {
    render(<QueueTicketCard ticket={{ ...ticket, unit, ticket_code: code, priority: 'urgent' }} />);
    expect(texts()).toEqual(expect.arrayContaining([
      'Live waiting room ticket', code, `${label} · Waiting`, 'Urgent',
      '#4', '3', '~20 min', 'Place in line', 'People ahead', 'Est. wait',
    ]));
    expect(renderer!.root.findAll(node => node.props.accessible && node.props.accessibilityLabel ===
      `${label} ticket ${code}. Waiting. Place in line #4. People ahead 3. Estimated wait ~20 min.`,
    ).length).toBeGreaterThan(0);
  });

  it.each([lightTheme, darkTheme])('keeps the clinic pill readable in $mode mode', theme => {
    jest.mocked(useTheme).mockReturnValue({ theme, mode: theme.mode, preference: 'system', setPreference: jest.fn() });
    render(<QueueTicketCard ticket={ticket} />);
    const label = renderer!.root.findAllByType(Text).find(node => node.props.children === 'Live waiting room ticket')!;
    expect(StyleSheet.flatten(label.props.style).color).toBe(theme.mode === 'dark' ? '#ffffff' : '#800000');
    expect(StyleSheet.flatten(label.parent!.props.style).backgroundColor).toBe(theme.mode === 'dark' ? theme.surface : '#ffffff');
  });

  it('preserves the contrasting guidance pill in dark mode', () => {
    jest.mocked(useTheme).mockReturnValue({ theme: darkTheme, mode: 'dark', preference: 'dark', setPreference: jest.fn() });
    render(<QueueTicketCard ticket={{ ...ticket, unit: 'guidance' }} />);
    const label = renderer!.root.findAllByType(Text).find(node => node.props.children === 'Live waiting room ticket')!;
    expect(StyleSheet.flatten(label.props.style).color).toBe('#7356a2');
    expect(StyleSheet.flatten(label.parent!.props.style).backgroundColor).toBe('#ffffff');
  });

  it('uses normal priority when the API has no priority text', () => {
    render(<QueueTicketCard ticket={{ ...ticket, priority: '' }} />);
    expect(texts()).toContain('Normal');
  });
});

describe('QueueTicketPlaceholder', () => {
  it('keeps an empty ticket slot with a working Open queue action', () => {
    const onPress = jest.fn();
    render(<QueueTicketPlaceholder onPress={onPress} />);
    expect(texts()).toContain('No active queue ticket');
    expect(renderer!.root.findAllByType(ActivityIndicator)).toHaveLength(0);
    const button = renderer!.root.findAll(node =>
      typeof node.props.onPress === 'function' && node.props.accessibilityLabel === 'Open queue')[0];
    expect(button).toBeDefined();
    act(() => { button.props.onPress(); });
    expect(onPress).toHaveBeenCalledTimes(1);
  });

  it('shows loading instead of claiming there is no active ticket', () => {
    render(<QueueTicketPlaceholder loading />);
    expect(texts()).toContain('Loading queue…');
    expect(texts()).not.toContain('No active queue ticket');
    expect(renderer!.root.findAllByType(ActivityIndicator)).toHaveLength(1);
  });
});
