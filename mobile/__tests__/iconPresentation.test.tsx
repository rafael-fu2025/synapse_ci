import React from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { CalendarDays, Clock3, MessageSquareText, Stethoscope } from 'lucide-react-native';
import type { Appointment } from '../src/api/endpoints/appointments';
import { Action } from '../src/components/Action';
import { Eyebrow } from '../src/components/Typography';
import { AppointmentCard } from '../src/features/appointments/components/AppointmentCard';
import type { StyleProp, ViewStyle } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { DetailRow } from '../src/components/DetailRow';
import { EmptyState } from '../src/components/EmptyState';
import { PlaceholderCard } from '../src/components/PlaceholderCard';
import { useTheme } from '../src/theme/ThemeProvider';
import { darkTheme, lightTheme } from '../src/theme/tokens';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: jest.fn() }));
jest.mock('lucide-react-native', () => ({
  CalendarDays: () => null, ChevronRight: () => null, Clock3: () => null,
  MessageSquareText: () => null, Stethoscope: () => null,
}));

const appointment: Appointment = {
  id: 1, unit: 'clinic', status: 'confirmed', type: 'routine', date: '2026-10-27',
  starts_at: '2026-10-27T09:00:00+08:00', ends_at: '2026-10-27T09:30:00+08:00',
  reason: null, cancellation_reason: null, provider_name: null, version: 1,
};

let renderer: ReactTestRenderer | undefined;

function render(element: React.ReactElement) {
  act(() => { renderer = create(element); });
}

function expectPlainSlot(style: StyleProp<ViewStyle>) {
  const flattened = StyleSheet.flatten(style) ?? {};
  expect(flattened.width).toBeGreaterThan(0);
  expect(flattened.backgroundColor).toBeUndefined();
  expect(flattened.borderRadius).toBeUndefined();
}

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
});

describe.each([lightTheme, darkTheme])('plain icon presentation in $mode mode', theme => {
  beforeEach(() => {
    jest.mocked(useTheme).mockReturnValue({ theme, mode: theme.mode, preference: 'system', setPreference: jest.fn() });
  });

  it('keeps placeholder icons unboxed without removing the content slot or its action', () => {
    const onPress = jest.fn();
    render(<PlaceholderCard icon={<View testID="plain-icon" />} title="No ticket" body="Check in first." onPress={onPress} />);
    const icon = renderer!.root.findByProps({ testID: 'plain-icon' });
    expectPlainSlot(icon.parent!.props.style);
    const button = renderer!.root.findAll(node => node.props.accessibilityRole === 'button' && typeof node.props.onPress === 'function')[0];
    act(() => button.props.onPress());
    expect(onPress).toHaveBeenCalledTimes(1);
  });

  it('shows the loading spinner without a colored tile', () => {
    render(<PlaceholderCard icon={<View />} title="Loading queue…" body="Please wait." loading />);
    const spinner = renderer!.root.findByType(ActivityIndicator);
    expectPlainSlot(spinner.parent!.props.style);
    expect(spinner.props.color).toBe(theme.mode === 'dark' ? '#ffffff' : '#800000');
  });

  it('adapts brand text and secondary actions without changing primary fills', () => {
    render(<>
      <Eyebrow>UPDATES</Eyebrow>
      <Action variant="secondary" loading>Retry</Action>
      <Action>Sign in</Action>
    </>);
    const expected = theme.mode === 'dark' ? '#ffffff' : '#800000';
    const textStyle = (label: string) => StyleSheet.flatten(renderer!.root.findAllByType(Text)
      .find(node => node.props.children === label)!.props.style);
    expect(textStyle('UPDATES').color).toBe(expected);
    expect(textStyle('Retry').color).toBe(expected);
    expect(renderer!.root.findByType(ActivityIndicator).props.color).toBe(expected);
    expect(textStyle('Sign in').color).toBe('#ffffff');
    const buttons = renderer!.root.findAll(node => typeof node.type === 'string' && node.props?.accessibilityState && node.props.style);
    const background = (index: number) => {
      const style = buttons[index].props.style;
      return StyleSheet.flatten(typeof style === 'function' ? style({ pressed: false }) : style).backgroundColor;
    };
    expect(background(0)).toBe(theme.surface);
    expect(background(1)).toBe('#800000');
  });

  it('adapts clinic and schedule icons but preserves the guidance color', () => {
    render(<AppointmentCard appointment={appointment} onOpen={jest.fn()} />);
    const expected = theme.mode === 'dark' ? '#ffffff' : '#800000';
    expect(renderer!.root.findByType(Stethoscope).props.color).toBe(expected);
    expect(renderer!.root.findByType(CalendarDays).props.color).toBe(expected);
    expect(renderer!.root.findByType(Clock3).props.color).toBe(expected);
    act(() => { renderer!.update(<AppointmentCard appointment={{ ...appointment, unit: 'guidance' }} onOpen={jest.fn()} />); });
    expect(renderer!.root.findByType(MessageSquareText).props.color).toBe('#7356a2');
  });

  it('keeps empty-state icons plain', () => {
    render(<EmptyState icon={<View testID="plain-icon" />} title="No updates" />);
    expectPlainSlot(renderer!.root.findByProps({ testID: 'plain-icon' }).parent!.props.style);
  });

  it('keeps detail-row icons plain', () => {
    render(<DetailRow icon={<View testID="plain-icon" />} label="Date" value="29 October" />);
    expectPlainSlot(renderer!.root.findByProps({ testID: 'plain-icon' }).parent!.props.style);
  });
});
