import React from 'react';
import { BackHandler, Image, Text, TextInput } from 'react-native';
import { act, create, ReactTestInstance, ReactTestRenderer } from 'react-test-renderer';
import { useNavigation, usePreventRemove, useRoute } from '@react-navigation/native';
import { ApiError } from '../src/api/client';
import { Action } from '../src/components/Action';
import { formatFullDate } from '../src/lib/format';
import { PageHeader } from '../src/components/PageHeader';
import { Appointment } from '../src/api/endpoints/appointments';
import { useAppointment, useAppointments, useCancelAppointment, useCreateAppointment } from '../src/features/appointments/hooks';
import { BookingScreen } from '../src/features/appointments/screens/BookingScreen';
import { CancelAppointmentScreen } from '../src/features/appointments/screens/CancelAppointmentScreen';
import { AppointmentsScreen } from '../src/features/appointments/screens/AppointmentsScreen';
import { AppointmentDetailScreen } from '../src/features/appointments/screens/AppointmentDetailScreen';
import { AppointmentCard } from '../src/features/appointments/components/AppointmentCard';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/features/appointments/hooks', () => ({
  useAppointment: jest.fn(), useAppointments: jest.fn(), useCancelAppointment: jest.fn(), useCreateAppointment: jest.fn(),
}));
jest.mock('@react-navigation/native', () => ({
  useNavigation: jest.fn(), useRoute: jest.fn(), usePreventRemove: jest.fn(),
  useFocusEffect: (effect: () => (() => void)) => jest.requireActual('react').useEffect(effect, [effect]),
}));
jest.mock('../src/theme/ThemeProvider', () => ({
  useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }),
}));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View }));
jest.mock('lucide-react-native', () => ({
  ArrowLeft: () => null, CalendarDays: () => null, CalendarX2: () => null,
  Check: () => null, ChevronRight: () => null, CircleCheckBig: () => null,
  Clock3: () => null, MessageSquareText: () => null, Stethoscope: () => null, UserRound: () => null,
}));

const appointment: Appointment = {
  id: 1052, unit: 'clinic', status: 'requested', type: 'routine', date: '2026-10-31',
  starts_at: '2026-10-31T13:30:00+08:00', ends_at: '2026-10-31T14:00:00+08:00',
  reason: 'Follow up on headaches', cancellation_reason: null, provider_name: null, version: 1,
};
const navigation = {
  goBack: jest.fn(), canGoBack: jest.fn(() => true), popTo: jest.fn(), replace: jest.fn(), navigate: jest.fn(),
  getState: jest.fn(() => ({ routeNames: ['Appointments', 'Booking', 'AppointmentDetail', 'CancelAppointment'] })),
};
const createRequest = jest.fn();
const cancelRequest = jest.fn();
const refetch = jest.fn();
let renderer: ReactTestRenderer | undefined;
let hardwareBack: (() => boolean | null | undefined) | undefined;

function render(element: React.ReactElement) {
  act(() => { renderer = create(element); });
}
function hasText(text: string) {
  return renderer!.root.findAllByType(Text).some(node => node.props.children === text);
}
function action(label: string) {
  return renderer!.root.findAllByType(Action).find(node => node.props.children === label)!;
}
function pressText(label: string) {
  const button = renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityRole === 'button').find(node =>
    node.findAllByType(Text).some(text => text.props.children === label));
  if (!button) {
    throw new Error(`Missing button ${label}. Visible text: ${textContent(renderer!.root)}`);
  }
  act(() => { button.props.onPress(); });
}
function checkbox() {
  return renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityRole === 'checkbox')[0];
}
function advanceToDateTime() {
  pressText('University Clinic');
  pressText('Routine consultation');
  act(() => renderer!.root.findByType(TextInput).props.onChangeText('  Headache  '));
  act(() => action('Continue to date & time').props.onPress());
}
function advanceToReview() {
  advanceToDateTime();
  const date = renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityLabel === formatFullDate('2026-10-31T00:00:00+08:00'))[0];
  act(() => date.props.onPress());
  pressText('1:30 PM');
  act(() => action('Review appointment').props.onPress());
}
function textContent(node: ReactTestInstance): string {
  return node.findAllByType(Text).map(text => JSON.stringify(text.props.children)).join(' ');
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers();
  jest.setSystemTime(new Date('2026-10-30T01:00:00Z'));
  createRequest.mockReset().mockResolvedValue({ data: appointment });
  cancelRequest.mockReset().mockResolvedValue(undefined);
  refetch.mockReset().mockResolvedValue(undefined);
  navigation.getState.mockReturnValue({ routeNames: ['Appointments', 'Booking', 'AppointmentDetail', 'CancelAppointment'] });
  navigation.canGoBack.mockReturnValue(true);
  jest.mocked(useNavigation).mockReturnValue(navigation as unknown as ReturnType<typeof useNavigation>);
  jest.mocked(useRoute).mockReturnValue({ key: 'cancel', name: 'CancelAppointment', params: { id: appointment.id } });
  jest.mocked(useCreateAppointment).mockReturnValue({ mutateAsync: createRequest, isPending: false } as unknown as ReturnType<typeof useCreateAppointment>);
  jest.mocked(useCancelAppointment).mockReturnValue({ mutateAsync: cancelRequest, isPending: false } as unknown as ReturnType<typeof useCancelAppointment>);
  jest.mocked(useAppointment).mockReturnValue({ data: { data: appointment }, isLoading: false, isError: false, refetch } as unknown as ReturnType<typeof useAppointment>);
  jest.mocked(useAppointments).mockReturnValue({ data: { data: [] }, isLoading: false, isError: false, refetch } as unknown as ReturnType<typeof useAppointments>);
  jest.spyOn(BackHandler, 'addEventListener').mockImplementation((_event, callback) => {
    hardwareBack = () => callback({} as never);
    return { remove: jest.fn() };
  });
});
afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  hardwareBack = undefined;
  jest.restoreAllMocks();
  jest.useRealTimers();
});

describe('BookingScreen', () => {
  it('shows the bundled web logos for both service choices without cropping', () => {
    render(<BookingScreen />);
    const logos = renderer!.root.findAllByType(Image);
    expect(logos).toHaveLength(2);
    expect(logos[0].props.source).toEqual(require('../src/assets/brand/health-services.png'));
    expect(logos[1].props.source).toEqual(require('../src/assets/brand/guidance-center.png'));
    for (const logo of logos) {
      expect(logo.props.resizeMode).toBe('contain');
      expect(logo.props.accessible).toBe(false);
    }
    expect(hasText('GC')).toBe(false);
    pressText('Guidance Center');
    expect(hasText('Step 2 of 4')).toBe(true);
  });

  it('requires review consent and blocks duplicate same-tick and stale successful submits', async () => {
    let resolve!: (value: { data: Appointment }) => void;
    createRequest.mockImplementationOnce(() => new Promise(result => { resolve = result; }));
    render(<BookingScreen />);
    advanceToReview();
    expect(hasText('Step 4 of 4')).toBe(true);
    expect(hasText('1:30 PM (30-minute request)')).toBe(true);
    expect(action('Submit appointment request').props.disabled).toBe(true);
    await act(async () => action('Submit appointment request').props.onPress());
    expect(createRequest).not.toHaveBeenCalled();
    act(() => checkbox().props.onPress());
    const submit = action('Submit appointment request').props.onPress;
    act(() => { submit(); submit(); });
    expect(createRequest).toHaveBeenCalledTimes(1);
    expect(createRequest).toHaveBeenCalledWith({ unit: 'clinic', date: '2026-10-31', preferred_time: '13:30', type: 'routine', reason: 'Headache' });
    act(() => { expect(hardwareBack!()).toBe(true); });
    expect(hasText('Step 4 of 4')).toBe(true);
    await act(async () => resolve({ data: appointment }));
    expect(hasText('Request received')).toBe(true);
    expect(textContent(renderer!.root)).toContain('Reference #');
    expect(textContent(renderer!.root)).toContain('1052');
    await act(async () => submit());
    expect(createRequest).toHaveBeenCalledTimes(1);
    act(() => action('View request status').props.onPress());
    expect(navigation.replace).toHaveBeenCalledWith('AppointmentDetail', { id: 1052 });
  });

  it('steps back with hardware/native/header back without losing details, date, or time', () => {
    render(<BookingScreen />);
    advanceToReview();
    act(() => { expect(hardwareBack!()).toBe(true); });
    expect(hasText('Step 3 of 4')).toBe(true);
    const selectedTime = renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityLabel === '1:30 PM')[0];
    expect(selectedTime.props.accessibilityState.selected).toBe(true);
    const calls = jest.mocked(usePreventRemove).mock.calls;
    expect(calls[calls.length - 1][0]).toBe(true);
    act(() => { calls[calls.length - 1][1]({} as never); });
    expect(hasText('Step 2 of 4')).toBe(true);
    expect(renderer!.root.findByType(TextInput).props.value).toBe('  Headache  ');
    act(() => renderer!.root.findByType(PageHeader).props.onBack());
    expect(hasText('Step 1 of 4')).toBe(true);
    pressText('University Clinic');
    const selectedType = renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityRole === 'button').find(node => textContent(node).includes('Routine consultation'))!;
    expect(selectedType.props.accessibilityState.selected).toBe(true);
    act(() => action('Continue to date & time').props.onPress());
    expect(hasText('Step 3 of 4')).toBe(true);
    act(() => action('Review appointment').props.onPress());
    expect(hasText('Step 4 of 4')).toBe(true);
    expect(checkbox().props.accessibilityState.checked).toBe(false);
    expect(navigation.goBack).not.toHaveBeenCalled();
  });

  it('shows server field errors and releases the submit guard for an explicit retry', async () => {
    createRequest.mockRejectedValueOnce(new ApiError(422, { code: 'validation_failed', message: 'Review the request.', fields: { preferred_time: ['Choose a future time.'] } }));
    render(<BookingScreen />);
    advanceToReview();
    act(() => checkbox().props.onPress());
    await act(async () => action('Submit appointment request').props.onPress());
    expect(hasText('Review the request.\nChoose a future time.')).toBe(true);
    expect(hasText('Step 4 of 4')).toBe(true);
    await act(async () => action('Submit appointment request').props.onPress());
    expect(createRequest).toHaveBeenCalledTimes(2);
    expect(hasText('Request received')).toBe(true);
  });

  it('routes success safely to Visits when hosted by a different stack', async () => {
    navigation.getState.mockReturnValue({ routeNames: ['Dashboard', 'Booking'] });
    render(<BookingScreen />);
    advanceToReview();
    act(() => checkbox().props.onPress());
    await act(async () => action('Submit appointment request').props.onPress());
    act(() => action('View request status').props.onPress());
    expect(navigation.replace).not.toHaveBeenCalled();
    expect(navigation.navigate).toHaveBeenCalledWith('Visits', { screen: 'AppointmentDetail', params: { id: 1052 }, initial: false });
    act(() => action('View my visits').props.onPress());
    expect(navigation.navigate).toHaveBeenCalledWith('Visits', { screen: 'Appointments', initial: false });
  });
});

describe('Cancellation and appointment loading', () => {
  it('shows the appointment summary, requires a reason, and guards duplicate cancellation', async () => {
    let resolve!: () => void;
    cancelRequest.mockImplementationOnce(() => new Promise<void>(result => { resolve = result; }));
    render(<CancelAppointmentScreen />);
    expect(textContent(renderer!.root)).toContain('Preferred time · Awaiting confirmation');
    expect(textContent(renderer!.root)).toContain('1:30 PM');
    expect(textContent(renderer!.root)).toContain('1052');
    await act(async () => action('Yes, cancel appointment').props.onPress());
    expect(cancelRequest).not.toHaveBeenCalled();
    expect(hasText('Tell the department why you are cancelling.')).toBe(true);
    act(() => renderer!.root.findByType(TextInput).props.onChangeText('  Schedule changed  '));
    const submit = action('Yes, cancel appointment').props.onPress;
    act(() => { submit(); submit(); });
    expect(cancelRequest).toHaveBeenCalledTimes(1);
    expect(cancelRequest).toHaveBeenCalledWith({ id: 1052, reason: 'Schedule changed' });
    await act(async () => resolve());
    await act(async () => submit());
    expect(cancelRequest).toHaveBeenCalledTimes(1);
    expect(navigation.goBack).toHaveBeenCalledTimes(1);
  });

  it('keeps a cancellation server error separate from reason validation and allows retry', async () => {
    cancelRequest.mockRejectedValueOnce(new ApiError(409, { code: 'conflict', message: 'This appointment has changed.' }));
    render(<CancelAppointmentScreen />);
    act(() => renderer!.root.findByType(TextInput).props.onChangeText('Cannot attend'));
    await act(async () => action('Yes, cancel appointment').props.onPress());
    expect(hasText('This appointment has changed.')).toBe(true);
    expect(renderer!.root.findByType(TextInput).props.value).toBe('Cannot attend');
    await act(async () => action('Yes, cancel appointment').props.onPress());
    expect(cancelRequest).toHaveBeenCalledTimes(2);
  });

  it.each([AppointmentsScreen, AppointmentDetailScreen, CancelAppointmentScreen])('does not call a server failure an empty appointment state (%#)', ScreenComponent => {
    const failure = { isLoading: false, isError: true, error: new ApiError(503, { code: 'unavailable', message: 'Department service unavailable.' }), refetch };
    jest.mocked(useAppointments).mockReturnValue(failure as unknown as ReturnType<typeof useAppointments>);
    jest.mocked(useAppointment).mockReturnValue(failure as unknown as ReturnType<typeof useAppointment>);
    render(<ScreenComponent />);
    expect(hasText('Department service unavailable.')).toBe(true);
    expect(hasText('No upcoming visits')).toBe(false);
    expect(hasText('This appointment is no longer available.')).toBe(false);
    expect(action('Yes, cancel appointment')).toBeUndefined();
    act(() => action('Try again').props.onPress());
    expect(refetch).toHaveBeenCalledTimes(1);
  });

  it('uses real provider data in the card footer, not the private reason', () => {
    render(<AppointmentCard appointment={{ ...appointment, provider_name: 'Dr. Rivera' }} onOpen={jest.fn()} />);
    expect(hasText('Dr. Rivera')).toBe(true);
    expect(hasText(appointment.reason!)).toBe(false);
  });
});
