import React from 'react';
import { Animated, AppState, Keyboard, StyleSheet, Text, TextInput, View } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { BookingStepTransition } from '../src/features/appointments/components/BookingStepTransition';
import type { BookingStep } from '../src/features/appointments/booking';
import { useReducedMotion } from '../src/lib/useReducedMotion';
import { motion } from '../src/lib/motion';
import { darkTheme, lightTheme } from '../src/theme/tokens';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: mockTheme }) }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: jest.fn(() => false) }));
jest.mock('react-native/Libraries/AppState/AppState', () => ({ __esModule: true, default: { currentState: 'active', addEventListener: jest.fn(() => ({ remove: jest.fn() })) } }));

type Timing = { value: Animated.Value; config: Parameters<typeof Animated.timing>[1]; complete?: (result: { finished: boolean }) => void; stop: jest.Mock };
const timings: Timing[] = [];
let renderer: ReactTestRenderer | undefined;
let mockTheme = lightTheme;
let step: BookingStep;
let reset: number;
let reason: string;
let focused: boolean;
let appChanged: (() => void) | undefined;
const listeners = new Map<string, Set<() => void>>();
const navigation = {
  isFocused: () => focused,
  addListener: (event: string, callback: () => void) => {
    const entries = listeners.get(event) ?? new Set(); entries.add(callback); listeners.set(event, entries);
    return () => { entries.delete(callback); };
  },
};
function node() {
  return <NavigationContext.Provider value={navigation as never}><BookingStepTransition step={step} reset={reset}
    renderStep={(value, active) => <View><Text>{value}</Text><TextInput accessibilityLabel={`${value} field`} value={reason} editable={active} /></View>} /></NavigationContext.Provider>;
}
function mount() { act(() => { renderer = create(node()); }); }
function update(value = step) { step = value; act(() => renderer!.update(node())); }
function viewport() { return renderer!.root.findAllByType(View).find(view => view.props.testID === 'booking-step-viewport')!; }
function layout(width: number, height = 500) { act(() => viewport().props.onLayout({ nativeEvent: { layout: { width, height, x: 0, y: 0 } } })); }
function panels() { return renderer!.root.findAllByType(Animated.View).filter(view => view.props.testID?.startsWith('booking-step-panel-')); }
function panel(value: BookingStep) { return panels().find(view => view.props.testID === `booking-step-panel-${value}`)!; }
function position(value: BookingStep) {
  const transform = StyleSheet.flatten(panel(value).props.style).transform as Array<{ translateX: number | { __getValue: () => number } }>;
  const x = transform[0].translateX;
  return typeof x === 'number' ? x : x.__getValue();
}
function finish(timing = timings.at(-1)!) {
  act(() => { timing.value.setValue(timing.config.toValue as number); timing.complete?.({ finished: true }); });
}

beforeEach(() => {
  jest.clearAllMocks(); timings.length = 0; listeners.clear(); mockTheme = lightTheme; step = 'unit'; reset = 0; reason = ''; focused = true;
  jest.replaceProperty(AppState, 'currentState', 'active');
  jest.mocked(useReducedMotion).mockReturnValue(false);
  jest.spyOn(AppState, 'addEventListener').mockImplementation((_event, callback) => {
    appChanged = () => callback(AppState.currentState as 'active' | 'background');
    return { remove: jest.fn() };
  });
  jest.spyOn(Keyboard, 'dismiss').mockImplementation(() => undefined);
  jest.spyOn(Animated, 'timing').mockImplementation((value, config) => {
    const timing: Timing = { value: value as Animated.Value, config, stop: jest.fn() }; timings.push(timing);
    return { start: callback => { timing.complete = callback; }, stop: timing.stop, reset: jest.fn() };
  });
});
afterEach(() => { act(() => renderer?.unmount()); renderer = undefined; jest.restoreAllMocks(); });

it.each([lightTheme, darkTheme])('uses opaque clipped translation-only panels in $mode', theme => {
  mockTheme = theme; mount(); layout(360); update('details');
  expect(StyleSheet.flatten(viewport().props.style)).toMatchObject({ backgroundColor: theme.canvas, overflow: 'hidden' });
  expect(panels()).toHaveLength(2);
  for (const view of panels()) {
    const style = StyleSheet.flatten(view.props.style);
    expect(style.backgroundColor).toBe(theme.canvas);
    expect(style.opacity).toBeUndefined(); expect(style.elevation).toBeUndefined();
    expect((style.transform as unknown[])).toHaveLength(1);
  }
  expect(position('unit')).toBe(0); expect(position('details')).toBe(360);
  expect(timings.at(-1)!.config).toMatchObject({ toValue: 1, duration: motion.context.sceneMs, useNativeDriver: true, isInteraction: false });
  expect(panel('unit').props.pointerEvents).toBe('none');
  expect(panel('unit').props.importantForAccessibility).toBe('no-hide-descendants');
  expect(panel('details').props.pointerEvents).toBe('auto');
  expect(panel('details').props.importantForAccessibility).toBe('auto');
  act(() => timings.at(-1)!.value.setValue(0.5));
  expect(position('unit')).toBe(-180); expect(position('details')).toBe(180);
  finish(); expect(panels()).toHaveLength(1); expect(position('details')).toBe(0);
});

it('does not animate initial mount, typing, theme or height-only layout updates', () => {
  mount(); layout(360);
  expect(timings).toHaveLength(0);
  const input = panel('unit').findByType(TextInput);
  reason = 'Kept entry'; update(); layout(360, 280);
  mockTheme = darkTheme; update();
  expect(panel('unit').findByType(TextInput)).toBe(input);
  expect(input.props.value).toBe('Kept entry');
  expect(timings).toHaveLength(0); expect(Keyboard.dismiss).not.toHaveBeenCalled();
});

it('slides backward from the left and keeps the active field across transition cleanup', () => {
  step = 'review'; mount(); layout(360); update('date-time');
  const input = panel('date-time').findByType(TextInput);
  expect(position('date-time')).toBe(-360);
  act(() => timings.at(-1)!.value.setValue(0.5));
  expect(position('review')).toBe(180); expect(position('date-time')).toBe(-180);
  reason = 'Still here'; update();
  expect(timings).toHaveLength(1);
  finish(); expect(panel('date-time').findByType(TextInput)).toBe(input);
  expect(input.props.value).toBe('Still here');
});

it('reverses the same pair from actual progress and rejects the outgoing completion', () => {
  mount(); layout(360); update('details'); const forward = timings.at(-1)!;
  const input = panel('unit').findByType(TextInput);
  act(() => forward.value.setValue(0.4)); update('unit');
  const reverse = timings.at(-1)!;
  expect(reverse.value).toBe(forward.value);
  expect(reverse.config).toMatchObject({ toValue: 0, duration: 96 });
  expect(position('unit')).toBe(-144);
  act(() => forward.complete?.({ finished: true }));
  expect(panels()).toHaveLength(2); expect(panel('unit').props.pointerEvents).toBe('auto');
  finish(reverse); expect(panels()).toHaveLength(1); expect(position('unit')).toBe(0);
  expect(panel('unit').findByType(TextInput)).toBe(input);
});

it('supersedes a third destination instead of queueing extra panels', () => {
  mount(); layout(360); update('details'); const old = timings.at(-1)!;
  act(() => old.value.setValue(0.3)); update('date-time');
  expect(panels().map(view => view.props.testID)).toEqual(['booking-step-panel-details', 'booking-step-panel-date-time']);
  expect(old.stop).toHaveBeenCalled();
  act(() => old.complete?.({ finished: true }));
  expect(panel('date-time').props.pointerEvents).toBe('auto');
  finish(); expect(panels()).toHaveLength(1); expect(position('date-time')).toBe(0);
});

it('ignores delayed native value reads after a newer destination and unmount', () => {
  mount(); layout(360); update('details'); const first = timings.at(-1)!;
  const reads: Array<(value: number) => void> = [];
  jest.spyOn(first.value, 'stopAnimation').mockImplementation(callback => { if (callback) { reads.push(callback); } });
  update('unit'); const count = timings.length;
  update('date-time');
  act(() => reads[0](0.4)); expect(timings.length).toBe(count + 1);
  const latest = timings.at(-1)!;
  act(() => renderer!.unmount()); renderer = undefined;
  act(() => { reads[0](0.4); latest.complete?.({ finished: true }); });
  expect(timings.length).toBe(count + 1);
});

it.each(['reduced', 'width', 'blur', 'background', 'discard'] as const)('settles immediately on %s and never replays stale motion', reasonForStop => {
  mount(); layout(360); update('details'); const old = timings.at(-1)!;
  act(() => old.value.setValue(0.5));
  if (reasonForStop === 'reduced') { jest.mocked(useReducedMotion).mockReturnValue(true); update(); }
  else if (reasonForStop === 'width') { layout(640); }
  else if (reasonForStop === 'blur') { focused = false; act(() => { for (const callback of listeners.get('blur') ?? []) { callback(); } }); }
  else if (reasonForStop === 'background') { jest.replaceProperty(AppState, 'currentState', 'background'); act(() => appChanged?.()); }
  else { reset += 1; update('unit'); }
  const target = reasonForStop === 'discard' ? 'unit' : 'details';
  expect(panels()).toHaveLength(1); expect(position(target)).toBe(0); expect(old.stop).toHaveBeenCalled();
  const count = timings.length;
  act(() => old.complete?.({ finished: true }));
  jest.mocked(useReducedMotion).mockReturnValue(false); focused = true; jest.replaceProperty(AppState, 'currentState', 'active'); update();
  expect(timings).toHaveLength(count); expect(panels()).toHaveLength(1);
});

it('skips zero-width motion and cleans every navigation listener on unmount', () => {
  mount(); update('details');
  expect(timings).toHaveLength(0); expect(panels()).toHaveLength(1);
  layout(360); expect(timings).toHaveLength(0);
  act(() => renderer!.unmount()); renderer = undefined;
  expect([...listeners.values()].every(entries => entries.size === 0)).toBe(true);
});

it('slides to terminal success without treating it as a fifth editable booking step', () => {
  step = 'review'; mount(); layout(360); update('success');
  expect(position('success')).toBe(360);
  expect(panel('review').findByType(TextInput).props.editable).toBe(false);
  finish(); expect(panels()).toHaveLength(1); expect(position('success')).toBe(0);
});
