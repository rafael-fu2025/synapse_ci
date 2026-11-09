import React from 'react';
import { AccessibilityInfo, Animated, BackHandler, Image, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { act, create, ReactTestInstance, ReactTestRenderer } from 'react-test-renderer';
import { useNavigation, usePreventRemove, useRoute } from '@react-navigation/native';
import { ApiError } from '../src/api/client';
import { Action } from '../src/components/Action';
import { formatFullDate } from '../src/lib/format';
import { PageHeader } from '../src/components/PageHeader';
import { Screen } from '../src/components/Screen';
import { Skeleton } from '../src/components/Skeleton';
import { Appointment } from '../src/api/endpoints/appointments';
import { useAppointment, useAppointments, useCancelAppointment, useCreateAppointment } from '../src/features/appointments/hooks';
import { BookingScreen } from '../src/features/appointments/screens/BookingScreen';
import { CancelAppointmentScreen } from '../src/features/appointments/screens/CancelAppointmentScreen';
import { AppointmentsScreen } from '../src/features/appointments/screens/AppointmentsScreen';
import { AppointmentDetailScreen } from '../src/features/appointments/screens/AppointmentDetailScreen';
import { AppointmentCard } from '../src/features/appointments/components/AppointmentCard';
import { CONTEXT_BAR_ACTION_CLEARANCE } from '../src/navigation/contextTabBarMetrics';
import { useKeyboardVisible } from '../src/lib/useKeyboardVisible';
import { useTheme } from '../src/theme/ThemeProvider';
import { darkTheme, lightTheme } from '../src/theme/tokens';

let mockWorkspace: { canSwitchContext: boolean } | null = null;
let mockReducedMotion = true;
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: () => mockReducedMotion }));
jest.mock('react-native/Libraries/AppState/AppState', () => ({ __esModule: true, default: { currentState: 'active', addEventListener: jest.fn(() => ({ remove: jest.fn() })) } }));
jest.mock('../src/features/workspace/WorkspaceContext', () => ({ useOptionalWorkspace: () => mockWorkspace }));
jest.mock('../src/lib/useKeyboardVisible', () => ({ useKeyboardVisible: jest.fn(() => false) }));
jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/features/appointments/hooks', () => ({
  useAppointment: jest.fn(), useAppointments: jest.fn(), useCancelAppointment: jest.fn(), useCreateAppointment: jest.fn(),
}));
jest.mock('@react-navigation/native', () => ({
  useNavigation: jest.fn(), useRoute: jest.fn(), usePreventRemove: jest.fn(),
  useFocusEffect: (effect: () => (() => void)) => jest.requireActual('react').useEffect(effect, [effect]),
}));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: jest.fn() }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View }));
jest.mock('react-native-svg', () => ({ SvgXml: () => null }));
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

async function render(element: React.ReactElement) {
  await act(async () => { renderer = create(element); });
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
  // A Monday: Saturdays are half-day, so no afternoon slot is offered there.
  const date = renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityLabel === formatFullDate('2026-11-02T00:00:00+08:00'))[0];
  act(() => date.props.onPress());
  pressText('1:30 PM');
  act(() => action('Review appointment').props.onPress());
}
function textContent(node: ReactTestInstance): string {
  return node.findAllByType(Text).map(text => JSON.stringify(text.props.children)).join(' ');
}
function filledAncestor(node: ReactTestInstance): ReactTestInstance {
  let ancestor = node.parent;
  while (ancestor && StyleSheet.flatten(ancestor.props.style)?.backgroundColor === undefined) {
    ancestor = ancestor.parent;
  }
  if (!ancestor) { throw new Error('Missing enclosing card fill'); }
  return ancestor;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockWorkspace = null;
  mockReducedMotion = true;
  jest.mocked(useTheme).mockReturnValue({ theme: lightTheme, mode: 'light', preference: 'system', setPreference: jest.fn() });
  jest.mocked(useKeyboardVisible).mockReturnValue(false);
  jest.useFakeTimers();
  jest.setSystemTime(new Date('2026-10-30T01:00:00Z'));
  jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(true);
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

function expectPinnedAction(label: string) {
  const button = action(label);
  expect(button).toBeDefined();
  let ancestor = button.parent;
  let footer: ReactTestInstance | null = null;
  while (ancestor && ancestor.type !== Screen) {
    expect(ancestor.type).not.toBe(ScrollView);
    if (StyleSheet.flatten(ancestor.props.style)?.flexShrink === 0) {
      footer = ancestor;
    }
    ancestor = ancestor.parent;
  }
  expect(ancestor?.type).toBe(Screen);
  expect(footer).not.toBeNull();
  const scroll = renderer!.root.findAllByType(ScrollView).find(node => !node.props.horizontal)!;
  let viewport: ReactTestInstance = scroll;
  while (viewport.parent && viewport.parent !== footer!.parent) { viewport = viewport.parent; }
  expect(viewport.parent === footer!.parent).toBe(true);
  expect(footer!.parent!.children.indexOf(viewport)).toBeLessThan(footer!.parent!.children.indexOf(footer!));
}

function expectFixedHeader() {
  const screen = renderer!.root.findByType(Screen);
  expect(screen.props.scroll).toBe(false);
  const children = React.Children.toArray(screen.props.children) as React.ReactElement[];
  expect(children[0].type).toBe(PageHeader);
}

describe('BookingScreen', () => {
  const themeServices = [lightTheme, darkTheme].flatMap(theme => [
    { theme, unit: 'clinic' as const, label: 'University Clinic', visit: 'Routine consultation', type: 'routine', logo: require('../src/assets/brand/health-services.png') },
    { theme, unit: 'guidance' as const, label: 'Guidance Center', visit: 'First session', type: 'initial', logo: require('../src/assets/brand/guidance-center.png') },
  ]);

  function bottomPadding() {
    const style = renderer!.root.findByType(Screen).props.bodyStyle;
    return style ? StyleSheet.flatten(style).paddingBottom : 20;
  }

  it('clears the raised switcher on every pinned booking step without changing ordinary or keyboard spacing', async () => {
    mockWorkspace = { canSwitchContext: true };
    await render(<BookingScreen />);
    expect(bottomPadding()).toBe(20);
    pressText('University Clinic');
    expect(bottomPadding()).toBe(CONTEXT_BAR_ACTION_CLEARANCE);
    expectPinnedAction('Continue to date & time');
    const button = action('Continue to date & time');
    const input = renderer!.root.findByType(TextInput);
    act(() => input.props.onChangeText('Keep this reason'));
    jest.mocked(useKeyboardVisible).mockReturnValue(true);
    await act(async () => renderer!.update(<BookingScreen />));
    expect(bottomPadding()).toBe(20);
    expect(action('Continue to date & time')).toBe(button);
    expect(renderer!.root.findByType(TextInput)).toBe(input);
    expect(input.props.value).toBe('Keep this reason');
    jest.mocked(useKeyboardVisible).mockReturnValue(false);
    mockWorkspace = { canSwitchContext: false };
    await act(async () => renderer!.update(<BookingScreen />));
    expect(bottomPadding()).toBe(20);
    mockWorkspace = null;
    await act(async () => renderer!.update(<BookingScreen />));
    expect(bottomPadding()).toBe(20);
    mockWorkspace = { canSwitchContext: true };
    await act(async () => renderer!.update(<BookingScreen />));
    expect(bottomPadding()).toBe(CONTEXT_BAR_ACTION_CLEARANCE);
    expect(action('Continue to date & time')).toBe(button);
    pressText('Routine consultation');
    act(() => button.props.onPress());
    expect(bottomPadding()).toBe(CONTEXT_BAR_ACTION_CLEARANCE);
    expectPinnedAction('Review appointment');
    const monday = renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityLabel === formatFullDate('2026-11-02T00:00:00+08:00'))[0];
    act(() => monday.props.onPress());
    pressText('1:30 PM');
    act(() => action('Review appointment').props.onPress());
    expect(bottomPadding()).toBe(CONTEXT_BAR_ACTION_CLEARANCE);
    expectPinnedAction('Submit appointment request');
    expect(renderer!.root.findByType(Screen).props.bottomSafeArea).toBeUndefined();
    act(() => checkbox().props.onPress());
    await act(async () => action('Submit appointment request').props.onPress());
    expect(hasText('Request received')).toBe(true);
    expect(renderer!.root.findByType(Screen).props.bodyStyle).toBeUndefined();
    expect(createRequest).toHaveBeenCalledWith(expect.objectContaining({ reason: 'Keep this reason' }));
  });

  it('keeps the header and step actions outside the vertical scroll with keyboard-aware layout', async () => {
    await render(<BookingScreen />);
    expectFixedHeader();
    expect(renderer!.root.findAllByType(Action)).toHaveLength(0);
    const keyboard = renderer!.root.findByType(KeyboardAvoidingView);
    expect(keyboard.props.behavior).toBe(Platform.OS === 'ios' ? 'padding' : undefined);
    expect(StyleSheet.flatten(keyboard.props.style).flex).toBe(1);
    const scroll = renderer!.root.findByType(ScrollView);
    expect(scroll.props.keyboardShouldPersistTaps).toBe('handled');
    expect(StyleSheet.flatten(scroll.props.style).flex).toBe(1);
    expect(StyleSheet.flatten(scroll.props.contentContainerStyle).paddingBottom).toBeGreaterThan(0);
    pressText('University Clinic');
    expectPinnedAction('Continue to date & time');
    act(() => action('Continue to date & time').props.onPress());
    expect(hasText('Choose a visit type to continue.')).toBe(true);
    pressText('Routine consultation');
    act(() => action('Continue to date & time').props.onPress());
    expectPinnedAction('Review appointment');
    act(() => action('Review appointment').props.onPress());
    expect(hasText('Choose a preferred date and time to continue.')).toBe(true);
    const monday = renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityLabel === formatFullDate('2026-11-02T00:00:00+08:00'))[0];
    act(() => monday.props.onPress());
    pressText('1:30 PM');
    act(() => action('Review appointment').props.onPress());
    expectFixedHeader();
    expectPinnedAction('Submit appointment request');
    expect(action('Submit appointment request').props.disabled).toBe(true);
    act(() => checkbox().props.onPress());
    expect(action('Submit appointment request').props.disabled).toBe(false);
    await act(async () => action('Submit appointment request').props.onPress());
    expect(createRequest).toHaveBeenCalledTimes(1);
    expect(hasText('Request received')).toBe(true);
  });

  it('slides accepted booking changes without moving progress/actions or losing same-step input identity', async () => {
    mockReducedMotion = false;
    const timings: Array<{ value: Animated.Value; config: Parameters<typeof Animated.timing>[1]; complete?: (result: { finished: boolean }) => void }> = [];
    jest.spyOn(Animated, 'timing').mockImplementation((value, config) => {
      const timing = { value: value as Animated.Value, config, complete: undefined as ((result: { finished: boolean }) => void) | undefined };
      timings.push(timing);
      return { start: callback => { timing.complete = callback; }, stop: jest.fn(), reset: jest.fn() };
    });
    await render(<BookingScreen />);
    const viewport = renderer!.root.findAllByType(View).find(node => node.props.testID === 'booking-step-viewport')!;
    act(() => viewport.props.onLayout({ nativeEvent: { layout: { width: 360, height: 500, x: 0, y: 0 } } }));
    const header = renderer!.root.findByType(PageHeader);
    const progress = renderer!.root.findAllByType(View).find(node => node.props.accessibilityLabel === 'Step 1 of 4')!;
    expect(timings).toHaveLength(0);
    pressText('University Clinic');
    expect(timings).toHaveLength(1);
    expect(timings[0].config).toMatchObject({ duration: 240, useNativeDriver: true, toValue: 1 });
    const input = renderer!.root.findByType(TextInput);
    const staleChange = input.props.onChangeText;
    const button = action('Continue to date & time');
    act(() => input.props.onChangeText('Kept reason'));
    act(() => button.props.onPress()); // Invalid: no visit type; no motion restart.
    expect(hasText('Choose a visit type to continue.')).toBe(true);
    expect(timings).toHaveLength(1);
    expect(renderer!.root.findByType(TextInput)).toBe(input);
    pressText('Routine consultation');
    act(() => {
      timings[0].value.setValue(1); timings[0].complete?.({ finished: true });
    });
    expect(renderer!.root.findByType(TextInput)).toBe(input);
    expect(action('Continue to date & time')).toBe(button);
    expect(renderer!.root.findByType(PageHeader)).toBe(header);
    expect(renderer!.root.findAllByType(View).find(node => node.props.accessibilityLabel === 'Step 2 of 4')).toBe(progress);
    act(() => button.props.onPress());
    expect(timings).toHaveLength(2);
    const outgoing = renderer!.root.findAllByType(Animated.View).find(node => node.props.testID === 'booking-step-panel-details')!;
    expect(outgoing.props.pointerEvents).toBe('none');
    expect(outgoing.props.importantForAccessibility).toBe('no-hide-descendants');
    expect(outgoing.findByType(TextInput).props.editable).toBe(false);
    act(() => staleChange('Must not replace reason'));
    expect(outgoing.findByType(TextInput).props.value).toBe('Kept reason');
    const next = action('Review appointment');
    expectPinnedAction('Review appointment');
    expect(next.findAllByType(Animated.View)).toHaveLength(1); // Only its existing press feedback moves.
    act(() => { timings[1].value.setValue(0.5); });
    act(() => renderer!.root.findByType(PageHeader).props.onBack());
    expect(timings.at(-1)!.config).toMatchObject({ toValue: 0, duration: 120 });
    expect(renderer!.root.findByType(TextInput)).toBe(input);
    expect(input.props.value).toBe('Kept reason');
    expect(createRequest).not.toHaveBeenCalled();
  });

  it('does not unlock or resend review while the receipt slides in', async () => {
    mockReducedMotion = false;
    const completions: Array<(result: { finished: boolean }) => void> = [];
    jest.spyOn(Animated, 'timing').mockImplementation(() => ({
      start: callback => { if (callback) { completions.push(callback); } }, stop: jest.fn(), reset: jest.fn(),
    }));
    await render(<BookingScreen />);
    const viewport = renderer!.root.findAllByType(View).find(node => node.props.testID === 'booking-step-viewport')!;
    act(() => viewport.props.onLayout({ nativeEvent: { layout: { width: 360, height: 500, x: 0, y: 0 } } }));
    advanceToReview();
    const pending = { resolve: undefined as ((value: { data: Appointment }) => void) | undefined };
    createRequest.mockImplementationOnce(() => new Promise(value => { pending.resolve = value; }));
    act(() => checkbox().props.onPress());
    const submit = action('Submit appointment request').props.onPress;
    act(() => { submit(); submit(); });
    expect(createRequest).toHaveBeenCalledTimes(1);
    act(() => renderer!.root.findByType(PageHeader).props.onBack());
    expect(hasText('Step 4 of 4')).toBe(true);
    await act(async () => pending.resolve!({ data: appointment }));
    expect(hasText('Request received')).toBe(true);
    expect(action('Submit appointment request')).toBeUndefined();
    expect(renderer!.root.findByType(PageHeader).props.subtitle).toBeUndefined();
    const receipt = renderer!.root.findAllByType(Animated.View).find(node => node.props.testID === 'booking-step-panel-success')!;
    expect(receipt.props.pointerEvents).toBe('auto');
    const review = renderer!.root.findAllByType(Animated.View).find(node => node.props.testID === 'booking-step-panel-review')!;
    expect(review.props.pointerEvents).toBe('none');
    await act(async () => { submit(); completions.slice(0, -1).forEach(callback => callback({ finished: true })); });
    expect(createRequest).toHaveBeenCalledTimes(1);
    expect(hasText('Request received')).toBe(true);
  });

  it('shows the bundled web logos for both service choices without cropping', async () => {
    await render(<BookingScreen />);
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

  it.each(themeServices)('scopes the step-two summary fill and readable text to $label in $theme.mode mode', async ({ theme, label, logo }) => {
    jest.mocked(useTheme).mockReturnValue({ theme, mode: theme.mode, preference: 'system', setPreference: jest.fn() });
    await render(<BookingScreen />);
    pressText(label);
    const kicker = renderer!.root.findAllByType(Text).find(node => node.props.children === 'SELECTED SERVICE')!;
    const summary = filledAncestor(kicker);
    const texts = summary.findAllByType(Text);
    expect(texts.map(node => node.props.children)).toEqual(['SELECTED SERVICE', label]);
    expect(StyleSheet.flatten(summary.props.style)).toMatchObject({
      backgroundColor: theme.mode === 'dark' ? theme.surface : theme.brand,
      padding: 11, borderRadius: 13,
    });
    expect(texts.map(node => StyleSheet.flatten(node.props.style).color)).toEqual(
      theme.mode === 'dark' ? [theme.ink500, theme.ink900] : ['#ffffff', '#ffffff'],
    );
    expect(summary.findByType(Image).props).toMatchObject({ source: logo, resizeMode: 'contain', accessible: false });
    expect(StyleSheet.flatten(renderer!.root.findAllByType(Text).find(node => node.props.children === 'Type of visit')!.props.style).color).toBe(theme.ink700);
    expect(renderer!.root.findByType(PageHeader).props.subtitle).toBe('Step 2 of 4');
    expectPinnedAction('Continue to date & time');
    expect(createRequest).not.toHaveBeenCalled();
  });

  it.each(themeServices)('preserves review and receipt styles for $label in $theme.mode mode', async ({ theme, unit, label, visit, type, logo }) => {
    jest.mocked(useTheme).mockReturnValue({ theme, mode: theme.mode, preference: 'system', setPreference: jest.fn() });
    createRequest.mockResolvedValue({ data: { ...appointment, unit, type, date: '2026-11-02' } });
    await render(<BookingScreen />);
    pressText(label);
    pressText(visit);
    act(() => action('Continue to date & time').props.onPress());
    const monday = renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityLabel === formatFullDate('2026-11-02T00:00:00+08:00'))[0];
    act(() => monday.props.onPress());
    pressText('1:30 PM');
    act(() => action('Review appointment').props.onPress());
    expect(hasText('SELECTED SERVICE')).toBe(false);
    const reviewKicker = renderer!.root.findAllByType(Text).find(node => node.props.children === 'REVIEW')!;
    const review = filledAncestor(reviewKicker);
    expect(StyleSheet.flatten(review.props.style)).toMatchObject({
      backgroundColor: theme.brand50, padding: 13, borderRadius: 14,
    });
    expect(review.findAllByType(Text).map(node => StyleSheet.flatten(node.props.style).color)).toEqual([theme.ink500, theme.ink900]);
    expect(review.findByType(Image).props).toMatchObject({ source: logo, resizeMode: 'contain', accessible: false });
    expect(action('Submit appointment request').props.disabled).toBe(true);
    act(() => checkbox().props.onPress());
    await act(async () => action('Submit appointment request').props.onPress());
    expect(createRequest).toHaveBeenCalledTimes(1);
    expect(createRequest).toHaveBeenCalledWith({ unit, date: '2026-11-02', preferred_time: '13:30', type, reason: undefined });
    expect(hasText('SELECTED SERVICE')).toBe(false);
    expect(hasText('REVIEW')).toBe(false);
    const receiptTitle = renderer!.root.findAllByType(Text).find(node => node.props.children === label)!;
    const ticket = filledAncestor(receiptTitle);
    expect(StyleSheet.flatten(ticket.props.style)).toMatchObject({
      backgroundColor: theme.surface, borderColor: theme.line, borderWidth: 1, padding: 15, borderRadius: 15,
    });
    expect(ticket.findAllByType(Text).map(node => StyleSheet.flatten(node.props.style).color)).toEqual([
      theme.ink500, theme.ink900, theme.ink500, theme.ink900, theme.ink500, theme.ink500,
    ]);
    expect(textContent(ticket)).toContain('1052');
    expect(action('View request status')).toBeDefined();
    expect(action('View my visits')).toBeDefined();
  });

  it('offers no afternoon preference on a Saturday and clears a stale afternoon pick', async () => {
    await render(<BookingScreen />);
    advanceToDateTime();
    // 2026-10-31 is a Saturday: only the morning group is offered.
    const saturday = renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityLabel === formatFullDate('2026-10-31T00:00:00+08:00'))[0];
    act(() => saturday.props.onPress());
    expect(hasText('Morning')).toBe(true);
    expect(hasText('Afternoon')).toBe(false);
    expect(hasText('Saturdays are half-day: only morning times are available.')).toBe(true);

    // Choosing an afternoon time on a weekday, then switching to Saturday, clears it.
    const monday = renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityLabel === formatFullDate('2026-11-02T00:00:00+08:00'))[0];
    act(() => monday.props.onPress());
    pressText('1:30 PM');
    expect(renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityLabel === '1:30 PM').length).toBeGreaterThan(0);
    act(() => saturday.props.onPress());
    expect(renderer!.root.findAll(node => typeof node.props.onPress === 'function' && node.props.accessibilityLabel === '1:30 PM')).toHaveLength(0);
    // With no time selected, review is blocked.
    act(() => action('Review appointment').props.onPress());
    expect(hasText('Choose a preferred date and time to continue.')).toBe(true);
  });

  it('requires review consent and blocks duplicate same-tick and stale successful submits', async () => {
    let resolve!: (value: { data: Appointment }) => void;
    createRequest.mockImplementationOnce(() => new Promise(result => { resolve = result; }));
    await render(<BookingScreen />);
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
    expect(createRequest).toHaveBeenCalledWith({ unit: 'clinic', date: '2026-11-02', preferred_time: '13:30', type: 'routine', reason: 'Headache' });
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

  it('steps back with hardware/native/header back without losing details, date, or time', async () => {
    await render(<BookingScreen />);
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
    await render(<BookingScreen />);
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
    await render(<BookingScreen />);
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

describe('AppointmentDetailScreen layout', () => {
  function bottomPadding() {
    const style = renderer!.root.findByType(Screen).props.bodyStyle;
    return style ? StyleSheet.flatten(style).paddingBottom : 20;
  }

  it('uses shared raised-CTA clearance without stacking padding or moving the pinned action', async () => {
    mockWorkspace = { canSwitchContext: true };
    await render(<AppointmentDetailScreen />);
    expect(CONTEXT_BAR_ACTION_CLEARANCE).toBe(34);
    expect(bottomPadding()).toBe(34);
    expectPinnedAction('Cancel this appointment');
    expect(renderer!.root.findByType(Screen).props.bottomSafeArea).toBeUndefined();
    const button = action('Cancel this appointment');
    jest.mocked(useKeyboardVisible).mockReturnValue(true);
    await act(async () => renderer!.update(<AppointmentDetailScreen />));
    expect(bottomPadding()).toBe(20);
    expect(action('Cancel this appointment')).toBe(button);
    jest.mocked(useKeyboardVisible).mockReturnValue(false);
    mockWorkspace = { canSwitchContext: false };
    await act(async () => renderer!.update(<AppointmentDetailScreen />));
    expect(bottomPadding()).toBe(20);
    mockWorkspace = { canSwitchContext: true };
    await act(async () => renderer!.update(<AppointmentDetailScreen />));
    expect(bottomPadding()).toBe(34);
    expect(action('Cancel this appointment')).toBe(button);
    act(() => button.props.onPress());
    expect(navigation.navigate).toHaveBeenCalledWith('CancelAppointment', { id: appointment.id });
  });

  it.each(['loading', 'missing', 'reason', 'completed', 'checked_in', 'cancelled', 'no_show'] as const)(
    'does not reserve raised action padding for %s details', async state => {
      mockWorkspace = { canSwitchContext: true };
      jest.mocked(useAppointment).mockReturnValue({
        isLoading: state === 'loading', isError: false,
        data: state === 'loading' || state === 'missing' ? undefined : { data: { ...appointment,
          status: state === 'reason' ? 'confirmed' : state,
          cancellation_reason: state === 'reason' ? 'Cannot attend' : null,
        } },
      } as unknown as ReturnType<typeof useAppointment>);
      await render(<AppointmentDetailScreen />);
      expect(bottomPadding()).toBe(20);
      expect(action('Cancel this appointment')).toBeUndefined();
    },
  );

  it.each([
    ['requested', 1, 'Awaiting confirmation, step 1 of 4'],
    ['scheduled', 2, 'Confirmed, step 2 of 4'],
    ['confirmed', 2, 'Confirmed, step 2 of 4'],
    ['checked_in', 3, 'Checked in, step 3 of 4'],
    ['completed', 4, 'Completed, step 4 of 4'],
    ['cancelled', 0, 'Cancelled'],
    ['no_show', 0, 'Missed'],
  ] as const)('keeps four caption-free segments with accessible %s progress', async (status, step, label) => {
    jest.mocked(useAppointment).mockReturnValue({ data: { data: { ...appointment, status } }, isLoading: false, isError: false } as unknown as ReturnType<typeof useAppointment>);
    await act(async () => { renderer = create(<AppointmentDetailScreen />); });
    expectFixedHeader();
    const screen = renderer!.root.findByType(Screen);
    const children = React.Children.toArray(screen.props.children) as React.ReactElement[];
    expect(children[1].type).toBe(ScrollView);
    const scroll = renderer!.root.findByType(ScrollView);
    expect(scroll.findAllByType(PageHeader)).toHaveLength(0);
    const progress = renderer!.root.findAll(node => node.props.accessibilityRole === 'progressbar')[0];
    expect(progress.props.accessible).toBe(true);
    expect(progress.props.accessibilityLabel).toBe(label);
    expect(progress.props.accessibilityValue).toEqual({ min: 0, max: 4, now: step });
    expect(React.Children.toArray(progress.props.children)).toHaveLength(4);
    expect(progress.findAllByType(Text)).toHaveLength(0);
    const fills = progress.findAllByType(Animated.View);
    expect(fills).toHaveLength(4);
    fills.forEach((fill, index) => {
      expect(StyleSheet.flatten(fill.props.style).opacity.__getValue()).toBe(index < step ? 1 : 0);
    });
    expect(hasText('Requested')).toBe(false);
    expect(hasText('Done')).toBe(false);
    expect(hasText('Checked in')).toBe(status === 'checked_in');
    expect(renderer!.root.findAllByType(Text).filter(node => node.props.children === 'Confirmed')).toHaveLength(status === 'scheduled' || status === 'confirmed' ? 1 : 0);
    if (['requested', 'scheduled', 'confirmed'].includes(status)) {
      expectPinnedAction('Cancel this appointment');
      act(() => action('Cancel this appointment').props.onPress());
      expect(navigation.navigate).toHaveBeenCalledWith('CancelAppointment', { id: appointment.id });
    } else {
      expect(action('Cancel this appointment')).toBeUndefined();
    }
    act(() => renderer!.root.findByType(PageHeader).props.onBack());
    expect(navigation.goBack).toHaveBeenCalledTimes(1);
  });

  it('honours reduced motion and animates segments only when motion is enabled', async () => {
    jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(true);
    let changeMotion!: (enabled: boolean) => void;
    jest.spyOn(AccessibilityInfo, 'addEventListener').mockImplementation((_event, listener) => {
      changeMotion = listener;
      return { remove: jest.fn() };
    });
    const timing = jest.spyOn(Animated, 'timing');
    await act(async () => { renderer = create(<AppointmentDetailScreen />); });
    expect(timing).not.toHaveBeenCalled();
    act(() => changeMotion(false));
    expect(timing).toHaveBeenCalledTimes(4);
    expect(timing).toHaveBeenCalledWith(expect.anything(), expect.objectContaining({ useNativeDriver: true, duration: 280 }));
    timing.mockClear();
    act(() => changeMotion(true));
    expect(timing).not.toHaveBeenCalled();
  });
});

describe('Cancellation and appointment loading', () => {
  it.each([AppointmentsScreen, AppointmentDetailScreen, CancelAppointmentScreen])('uses skeletons only without current appointment data (%#)', async Component => {
    const initial = { isLoading: false, fetchStatus: 'fetching', refetch };
    jest.mocked(useAppointments).mockReturnValue(initial as unknown as ReturnType<typeof useAppointments>);
    jest.mocked(useAppointment).mockReturnValue(initial as unknown as ReturnType<typeof useAppointment>);
    await render(<Component />);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(1);
    const cached = { data: { data: appointment }, isLoading: true, isRefetching: true, fetchStatus: 'fetching', refetch };
    jest.mocked(useAppointment).mockReturnValue(cached as unknown as ReturnType<typeof useAppointment>);
    jest.mocked(useAppointments).mockReturnValue({ ...cached, data: { data: [appointment] } } as unknown as ReturnType<typeof useAppointments>);
    await act(async () => renderer!.update(<Component />));
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
  });

  it.each([AppointmentsScreen, AppointmentDetailScreen, CancelAppointmentScreen])('does not invent missing appointments while paused or ineligible (%#)', async Component => {
    const waiting = { isLoading: false, isPending: true, fetchStatus: 'paused', refetch };
    jest.mocked(useAppointment).mockReturnValue(waiting as unknown as ReturnType<typeof useAppointment>);
    jest.mocked(useAppointments).mockReturnValue(waiting as unknown as ReturnType<typeof useAppointments>);
    await render(<Component />);
    expect(renderer!.root.findAllByType(Skeleton)).toHaveLength(0);
    expect(textContent(renderer!.root)).toMatch(/Waiting to load/);
    expect(hasText('This appointment is no longer available.')).toBe(false);
    expect(hasText('No upcoming visits')).toBe(false);
  });

  it.each([401, 403, 404])('hides retained appointment detail and cancellation actions after %s', async status => {
    jest.mocked(useAppointment).mockReturnValue({ data: { data: appointment }, isError: true,
      error: new ApiError(status, { code: 'denied', message: 'Unavailable' }), refetch } as unknown as ReturnType<typeof useAppointment>);
    await render(<AppointmentDetailScreen />);
    expect(action('Cancel appointment')).toBeUndefined();
    expect(hasText(appointment.reason!)).toBe(false);
    await act(async () => renderer!.update(<CancelAppointmentScreen />));
    expect(action('Yes, cancel appointment')).toBeUndefined();
    expect(renderer!.root.findAllByType(TextInput)).toHaveLength(0);
  });
  it('shows the appointment summary, requires a reason, and guards duplicate cancellation', async () => {
    let resolve!: () => void;
    cancelRequest.mockImplementationOnce(() => new Promise<void>(result => { resolve = result; }));
    await render(<CancelAppointmentScreen />);
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
    await render(<CancelAppointmentScreen />);
    act(() => renderer!.root.findByType(TextInput).props.onChangeText('Cannot attend'));
    await act(async () => action('Yes, cancel appointment').props.onPress());
    expect(hasText('This appointment has changed.')).toBe(true);
    expect(renderer!.root.findByType(TextInput).props.value).toBe('Cannot attend');
    await act(async () => action('Yes, cancel appointment').props.onPress());
    expect(cancelRequest).toHaveBeenCalledTimes(2);
  });

  it.each([AppointmentsScreen, AppointmentDetailScreen, CancelAppointmentScreen])('does not call a server failure an empty appointment state (%#)', async ScreenComponent => {
    const failure = { isLoading: false, isError: true, error: new ApiError(503, { code: 'unavailable', message: 'Department service unavailable.' }), refetch };
    jest.mocked(useAppointments).mockReturnValue(failure as unknown as ReturnType<typeof useAppointments>);
    jest.mocked(useAppointment).mockReturnValue(failure as unknown as ReturnType<typeof useAppointment>);
    await render(<ScreenComponent />);
    expect(hasText('Department service unavailable.')).toBe(true);
    expect(hasText('No upcoming visits')).toBe(false);
    expect(hasText('This appointment is no longer available.')).toBe(false);
    expect(action('Yes, cancel appointment')).toBeUndefined();
    act(() => action('Try again').props.onPress());
    expect(refetch).toHaveBeenCalledTimes(1);
  });

  it('uses real provider data in the card footer, not the private reason', async () => {
    await render(<AppointmentCard appointment={{ ...appointment, provider_name: 'Dr. Rivera' }} onOpen={jest.fn()} />);
    expect(hasText('Dr. Rivera')).toBe(true);
    expect(hasText(appointment.reason!)).toBe(false);
  });
});
