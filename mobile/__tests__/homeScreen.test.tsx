import React from 'react';
import { ActivityIndicator, Image, Text } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { useQuery } from '@tanstack/react-query';
import type { Appointment } from '../src/api/endpoints/appointments';
import type { AnnouncementListItem } from '../src/api/endpoints/announcements';
import type { QueueTicket } from '../src/api/endpoints/queue';
import type { SurveyListItem } from '../src/api/endpoints/surveys';
import { DashboardScreen } from '../src/features/dashboard/screens/DashboardScreen';
import { useAppointments } from '../src/features/appointments/hooks';
import { useAnnouncements } from '../src/features/announcements/hooks';
import { useNotificationInbox } from '../src/features/notifications/hooks';
import { useQueueSnapshot } from '../src/features/queue/hooks';
import { useSurveys } from '../src/features/surveys/hooks';
import { useProfilePhoto } from '../src/features/profile/useProfilePhoto';
import { QueueTicketCard, QueueTicketPlaceholder } from '../src/features/queue/components/QueueTicketCard';

jest.mock('@tanstack/react-query', () => ({ useQuery: jest.fn() }));
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({ navigate: mockNavigate }),
}));
jest.mock('../src/api/endpoints/me', () => ({ me: jest.fn() }));
jest.mock('../src/features/appointments/hooks', () => ({ useAppointments: jest.fn() }));
jest.mock('../src/features/announcements/hooks', () => ({ useAnnouncements: jest.fn() }));
jest.mock('../src/features/notifications/hooks', () => ({ useNotificationInbox: jest.fn() }));
jest.mock('../src/features/queue/hooks', () => ({ useQueueSnapshot: jest.fn() }));
jest.mock('../src/features/surveys/hooks', () => ({ useSurveys: jest.fn() }));
jest.mock('../src/features/profile/useProfilePhoto', () => ({ useProfilePhoto: jest.fn() }));
jest.mock('../src/lib/useFocusPoll', () => ({ useFocusPoll: () => false }));
jest.mock('../src/store/auth', () => ({
  useAuth: () => ({ user: { name: 'Alex Rivera', avatar_initials: 'AR' } }),
}));
jest.mock('../src/theme/ThemeProvider', () => ({
  useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }),
}));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View }));
jest.mock('react-native-linear-gradient', () => jest.requireActual('react-native').View);
jest.mock('react-native-svg', () => {
  const { View } = jest.requireActual('react-native');
  return { __esModule: true, default: View, Svg: View, Path: View, Defs: View, G: View, Rect: View };
});
jest.mock('lucide-react-native', () => ({
  Bell: () => null, Building2: () => null, CalendarPlus: () => null,
  ChevronRight: () => null, ClipboardList: () => null, Clock3: () => null,
  ListChecks: () => null, Megaphone: () => null, Stethoscope: () => null,
}));

const mockNavigate = jest.fn();
const mockAppointments = useAppointments as jest.Mock;
const mockAnnouncements = useAnnouncements as jest.Mock;
const mockQueue = useQueueSnapshot as jest.Mock;
const mockSurveys = useSurveys as jest.Mock;
let renderer: ReactTestRenderer | undefined;

const appointment: Appointment = {
  id: 314, unit: 'clinic', status: 'confirmed', type: 'routine', date: '2026-10-27',
  starts_at: '2026-10-27T09:00:00+08:00', ends_at: '2026-10-27T09:30:00+08:00',
  reason: null, cancellation_reason: null, provider_name: 'Dr. Rivera', version: 1,
};
const announcement: AnnouncementListItem = {
  id: 81, unit: 'clinic', title: 'Extended clinic hours', audience: 'all',
  published_at: '2026-10-26T08:00:00+08:00', ends_at: null,
  excerpt: 'The clinic is open until 6 PM during exams.', read_at: null,
};
const survey: SurveyListItem = {
  id: 92, title: 'Campus wellbeing check-in', description: 'Tell us how your semester is going.',
  starts_at: null, ends_at: null, published_at: '2026-10-26T08:00:00+08:00',
  status: 'published', submitted: false, closed: false,
};
const ticket: QueueTicket = {
  unit: 'clinic', ticket_code: 'C-042', status: 'waiting', priority: 'normal',
  position: 3, people_ahead: 2, estimated_wait_minutes: 15,
};

function result<T>(data: T, state: 'ready' | 'loading' | 'error' = 'ready') {
  return {
    data, isLoading: state === 'loading', isPending: state === 'loading',
    isError: state === 'error', isFetching: state === 'loading',
    isSuccess: state === 'ready', error: state === 'error' ? new Error('Offline') : null,
  };
}
function render() {
  act(() => { renderer = create(<DashboardScreen />); });
}
function texts() {
  return renderer!.root.findAllByType(Text).map(node => React.Children.toArray(node.props.children).join(''));
}
function press(label: string) {
  const button = renderer!.root.findAll(node =>
    typeof node.props.onPress === 'function' && node.props.accessibilityRole === 'button').find(node =>
    node.props.accessibilityLabel === label ||
    node.findAllByType(Text).some(child => React.Children.toArray(child.props.children).join('') === label));
  expect(button).toBeDefined();
  act(() => { button!.props.onPress(); });
}
function withRealData(state: 'ready' | 'error' = 'ready') {
  mockAppointments.mockReturnValue(result({ data: [appointment] }, state));
  mockAnnouncements.mockReturnValue(result({ data: [announcement] }, state));
  mockQueue.mockReturnValue(result({ data: { date: '2026-10-26', tickets: [ticket] } }, state));
  mockSurveys.mockReturnValue(result({ data: [survey] }, state));
}

const slots = [
  { name: 'appointments', hook: mockAppointments, empty: 'No upcoming appointment', loading: 'Loading appointments…', error: 'Appointments unavailable', action: 'Open appointments', route: 'Visits' },
  { name: 'queue', hook: mockQueue, empty: 'No active queue ticket', loading: 'Loading queue…', error: 'Queue unavailable', action: 'Open queue', route: 'Queue' },
  { name: 'announcements', hook: mockAnnouncements, empty: 'No announcements yet', loading: 'Loading announcements…', error: 'Announcements unavailable', action: 'Open announcements', route: 'Announcements' },
  { name: 'surveys', hook: mockSurveys, empty: 'No surveys waiting', loading: 'Loading surveys…', error: 'Surveys unavailable', action: 'Open surveys', route: 'Surveys' },
];

beforeEach(() => {
  jest.resetAllMocks();
  jest.mocked(useProfilePhoto).mockReturnValue(null);
  (useQuery as jest.Mock).mockReturnValue(result({ entitlements: { staff_queue_units: [] } }));
  (useNotificationInbox as jest.Mock).mockReturnValue(result({ data: { unread_count: 2 } }));
  mockAppointments.mockReturnValue(result({ data: [] }));
  mockAnnouncements.mockReturnValue(result({ data: [] }));
  mockQueue.mockReturnValue(result({ data: { date: '2026-10-26', tickets: [] } }));
  mockSurveys.mockReturnValue(result({ data: [] }));
});

afterEach(() => {
  act(() => { renderer?.unmount(); });
  renderer = undefined;
});

describe('Home dashboard', () => {
  it('shows the saved photo in the Home avatar and drops it when removed', () => {
    const uri = 'data:image/webp;base64,AQID';
    jest.mocked(useProfilePhoto).mockReturnValue(uri);
    render();
    expect(renderer!.root.findByType(Image).props.source).toEqual({ uri });
    jest.mocked(useProfilePhoto).mockReturnValue(null);
    act(() => { renderer!.update(<DashboardScreen />); });
    expect(renderer!.root.findAllByType(Image)).toHaveLength(0);
    expect(texts()).toContain('AR');
  });

  it('keeps personal content without SYNAPSE branding or its footer', () => {
    render();
    expect(texts()).toContain('How are you feeling today?');
    expect(texts().some(text => text.includes('Alex'))).toBe(true);
    expect(texts().join('\n')).not.toMatch(/synapse|personal portal|Foundation University/i);
    expect(renderer!.root.findAllByType(Image)).toHaveLength(0);
  });

  it('keeps the queue, announcement and survey placeholders visible for successful empty responses', () => {
    render();
    expect(texts()).toContain('My queue ticket');
    slots.forEach(slot => {
      expect(texts()).toContain(slot.empty);
      expect(texts()).not.toContain(slot.loading);
      expect(texts()).not.toContain(slot.error);
    });
    expect(renderer!.root.findAllByType(QueueTicketPlaceholder)).toHaveLength(1);
    expect(renderer!.root.findAllByType(QueueTicketCard)).toHaveLength(0);
    expect(renderer!.root.findAllByType(ActivityIndicator)).toHaveLength(0);
  });

  it.each(slots)('does not describe loading $name as empty', slot => {
    slot.hook.mockReturnValue(result(undefined, 'loading'));
    render();
    expect(texts()).toContain(slot.loading);
    expect(texts()).not.toContain(slot.empty);
    expect(texts()).not.toContain(slot.error);
    expect(renderer!.root.findAllByType(ActivityIndicator)).toHaveLength(1);
    if (slot.name === 'queue') {
      expect(texts()).toContain('My queue ticket');
      expect(renderer!.root.findAllByType(QueueTicketPlaceholder)).toHaveLength(1);
    }
  });

  it.each(slots)('does not describe unavailable $name as empty', slot => {
    slot.hook.mockReturnValue(result(undefined, 'error'));
    render();
    expect(texts()).toContain(slot.error);
    expect(texts()).not.toContain(slot.empty);
    expect(texts()).not.toContain(slot.loading);
    expect(renderer!.root.findAllByType(ActivityIndicator)).toHaveLength(0);
    if (slot.name === 'queue') {
      expect(texts()).toContain('My queue ticket');
      expect(renderer!.root.findAllByType(QueueTicketPlaceholder)).toHaveLength(1);
    }
  });

  it.each(slots)('opens $name from its empty placeholder', slot => {
    render();
    press(slot.action);
    expect(mockNavigate).toHaveBeenCalledWith(slot.route);
  });

  it.each([
    { label: 'Book a visit', route: 'Visits', params: { screen: 'Booking', initial: false } },
    { label: 'Open profile', route: 'Profile', params: { screen: 'ProfileHome' } },
  ])('uses nested navigation for $label', ({ label, route, params }) => {
    render();
    press(label);
    expect(mockNavigate).toHaveBeenCalledTimes(1);
    expect(mockNavigate).toHaveBeenCalledWith(route, params);
  });

  it('renders real appointment, queue, announcement and survey cards instead of placeholders', () => {
    withRealData();
    render();
    expect(texts()).toEqual(expect.arrayContaining([
      'NEXT APPOINTMENT', 'Clinic consultation', 'Dr. Rivera', 'Confirmed',
      'My queue ticket', 'Live waiting room ticket', 'C-042', '#3', '~15 min',
      announcement.title, announcement.excerpt, survey.title, survey.description, 'Start survey',
    ]));
    expect(renderer!.root.findAllByType(QueueTicketCard)).toHaveLength(1);
    expect(renderer!.root.findAllByType(QueueTicketPlaceholder)).toHaveLength(0);
    slots.forEach(slot => { expect(texts()).not.toContain(slot.empty); });
    expect(useAppointments).toHaveBeenCalledWith({ service: 'all', status: 'active', sort: 'upcoming' });
    expect(useAnnouncements).toHaveBeenCalledWith({ unit: 'all', status: 'all', page: 1 });
    expect(useSurveys).toHaveBeenCalledWith({ status: 'available' });
  });

  it('opens the next visit in the nested Visits detail route with its ID', () => {
    withRealData();
    render();
    press('View appointment');
    expect(mockNavigate).toHaveBeenCalledTimes(1);
    expect(mockNavigate).toHaveBeenCalledWith('Visits', {
      screen: 'AppointmentDetail', params: { id: appointment.id }, initial: false,
    });
  });

  it('opens real announcement and survey cards using their IDs', () => {
    withRealData();
    render();
    press(announcement.title);
    expect(mockNavigate).toHaveBeenLastCalledWith('AnnouncementDetail', { id: announcement.id });
    press(survey.title);
    expect(mockNavigate).toHaveBeenLastCalledWith('SurveyForm', { id: survey.id });
  });

  it('keeps both clinic and guidance tickets visible', () => {
    const guidance: QueueTicket = { ...ticket, unit: 'guidance', ticket_code: 'G-007', status: 'called' };
    mockQueue.mockReturnValue(result({ data: { date: '2026-10-26', tickets: [ticket, guidance] } }));
    render();
    expect(texts()).toEqual(expect.arrayContaining(['C-042', 'G-007', 'Your turn', 'Now']));
    expect(renderer!.root.findAllByType(QueueTicketCard)).toHaveLength(2);
    expect(renderer!.root.findAllByType(QueueTicketPlaceholder)).toHaveLength(0);
  });

  it('offers only an available survey, skipping submitted and closed surveys', () => {
    mockSurveys.mockReturnValue(result({ data: [
      { ...survey, id: 90, title: 'Already answered', submitted: true },
      { ...survey, id: 91, title: 'Closed questionnaire', closed: true, status: 'closed' },
      survey,
    ] }));
    render();
    expect(texts()).toContain(survey.title);
    expect(texts()).not.toContain('Already answered');
    expect(texts()).not.toContain('Closed questionnaire');
    expect(texts()).not.toContain('No surveys waiting');
  });

  it('keeps the survey placeholder when all surveys are submitted or closed', () => {
    mockSurveys.mockReturnValue(result({ data: [
      { ...survey, submitted: true }, { ...survey, id: 93, closed: true, status: 'closed' },
    ] }));
    render();
    expect(texts()).toContain('No surveys waiting');
    expect(texts()).not.toContain(survey.title);
  });

  it('keeps cached real cards visible when background refreshes fail', () => {
    withRealData('error');
    render();
    expect(texts()).toEqual(expect.arrayContaining([
      'Clinic consultation', 'C-042', announcement.title, survey.title,
    ]));
    slots.forEach(slot => { expect(texts()).not.toContain(slot.empty); });
  });
});
