import React, { useState } from 'react';
import { Animated, AppState, StyleSheet, Text, TextInput } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { act, create, type ReactTestInstance, type ReactTestRenderer } from 'react-test-renderer';
import { BottomSheet } from '../src/components/BottomSheet';
import { OverlayProvider, useOverlayActivity } from '../src/components/OverlayProvider';
import { CalendarSegmentedControl } from '../src/features/team-calendar/components/CalendarSegmentedControl';
import { motion, motionEasing } from '../src/lib/motion';
import { useReducedMotion } from '../src/lib/useReducedMotion';
import { darkTheme, lightTheme } from '../src/theme/tokens';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: mockTheme }) }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: jest.fn(() => false) }));
jest.mock('../src/lib/useKeyboardVisible', () => ({ useKeyboardVisible: () => false }));
jest.mock('react-native/Libraries/AppState/AppState', () => ({ __esModule: true, default: { currentState: 'active', addEventListener: jest.fn(() => ({ remove: jest.fn() })) } }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 24, bottom: 20, left: 0, right: 0 }) }));
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));

type Timing = { value: Animated.Value; config: Parameters<typeof Animated.timing>[1]; complete?: (result: { finished: boolean }) => void; stop: jest.Mock };
type AppListener = Parameters<typeof AppState.addEventListener>[1];
const timings: Timing[] = [];
const listeners = new Map<string, Set<() => void>>();
const appListeners = new Set<AppListener>();
const VIEWS = ['agenda', 'week', 'month'] as const;
type CalendarView = typeof VIEWS[number];
let renderer: ReactTestRenderer | undefined;
let mockTheme = lightTheme;
let focused: boolean;
let enabled: boolean;
let selected: CalendarView;
let typed: string;
let refetchRevision: number;
let onChange: jest.Mock;
const navigation = {
  isFocused: () => focused,
  addListener: (event: string, callback: () => void) => {
    const entries = listeners.get(event) ?? new Set();
    entries.add(callback); listeners.set(event, entries);
    return () => { entries.delete(callback); };
  },
};
function viewLabel(option: string) { return option[0].toUpperCase() + option.slice(1); }
function node() {
  return <NavigationContext.Provider value={navigation as never}>
    <TextInput accessibilityLabel="Unrelated draft" value={typed} />
    <Text>{refetchRevision}</Text>
    <CalendarSegmentedControl label="Calendar view" values={VIEWS} selected={selected} enabled={enabled}
      labelFor={viewLabel} onChange={onChange} />
  </NavigationContext.Provider>;
}
function mount(element = node()) { act(() => { renderer = create(element); }); }
function update(option = selected) { selected = option; act(() => renderer!.update(node())); }
function radios() {
  return renderer!.root.findAll(target => target.props?.accessibilityRole === 'radio' && typeof target.props?.onPress === 'function');
}
function radio(text: string, label = 'Calendar view') {
  return radios().find(target => target.props.accessibilityLabel === `${label}: ${text}`)!;
}
function pill(target: ReactTestInstance) {
  return target.findAllByType(Animated.View).find(view => StyleSheet.flatten(view.props.style)?.backgroundColor === mockTheme.brand)!;
}
function progress(target: ReactTestInstance) { return StyleSheet.flatten(pill(target).props.style).opacity as Animated.Value; }
function value(animated: unknown) { return (animated as { __getValue: () => number }).__getValue(); }
function finish(timing: Timing) { act(() => { timing.value.setValue(timing.config.toValue as number); timing.complete?.({ finished: true }); }); }
function application(state: 'active' | 'background' | 'inactive') {
  act(() => {
    jest.replaceProperty(AppState, 'currentState', state);
    for (const callback of appListeners) { callback(state); }
  });
}
function route(next: boolean) {
  act(() => { focused = next; for (const callback of listeners.get(next ? 'focus' : 'blur') ?? []) { callback(); } });
}

beforeEach(() => {
  jest.clearAllMocks(); timings.length = 0; listeners.clear(); appListeners.clear();
  mockTheme = lightTheme; focused = true; enabled = true; selected = 'agenda'; typed = ''; refetchRevision = 0; onChange = jest.fn();
  jest.replaceProperty(AppState, 'currentState', 'active');
  jest.mocked(useReducedMotion).mockReturnValue(false);
  jest.spyOn(AppState, 'addEventListener').mockImplementation((_event, callback) => {
    appListeners.add(callback); return { remove: jest.fn(() => { appListeners.delete(callback); }) };
  });
  jest.spyOn(Animated, 'timing').mockImplementation((animated, config) => {
    const timing: Timing = { value: animated as Animated.Value, config, stop: jest.fn() }; timings.push(timing);
    return { start: callback => { timing.complete = callback; }, stop: timing.stop, reset: jest.fn() };
  });
});
afterEach(() => { act(() => renderer?.unmount()); renderer = undefined; jest.restoreAllMocks(); });

it.each([lightTheme, darkTheme])('starts at the accepted selection with no first-mount animation in $mode', theme => {
  mockTheme = theme; selected = 'month'; mount();
  expect(timings).toHaveLength(0);
  expect(value(progress(radio('Month')))).toBe(1);
  expect(value(progress(radio('Agenda')))).toBe(0);
  expect(radio('Month').props.accessibilityState.checked).toBe(true);
  expect(StyleSheet.flatten(pill(radio('Month')).props.style).backgroundColor).toBe(theme.brand);
});

it('activates immediately and moves only pill/label/press content with native timing', () => {
  mount();
  const agenda = radio('Agenda'); const week = radio('Week');
  const hitStyle = StyleSheet.flatten(week.props.style);
  expect(hitStyle).toMatchObject({ minHeight: 44, flex: 1 });
  expect(hitStyle.transform).toBeUndefined();
  act(() => week.props.onPressIn());
  const press = timings.at(-1)!;
  expect(press.config).toMatchObject({ toValue: motion.press.scale, duration: motion.press.pressMs, easing: motionEasing, useNativeDriver: true, isInteraction: false });
  act(() => week.props.onPress());
  expect(onChange).toHaveBeenCalledWith('week');
  update('week');
  expect(week.props.accessibilityState.checked).toBe(true);
  expect(agenda.props.accessibilityState.checked).toBe(false);
  const selectedMotion = timings.find(timing => timing.value === progress(week))!;
  const unselectedMotion = timings.find(timing => timing.value === progress(agenda))!;
  expect(selectedMotion.config).toMatchObject({ toValue: 1, duration: 180, easing: motionEasing, useNativeDriver: true, isInteraction: false });
  expect(unselectedMotion.config).toMatchObject({ toValue: 0, duration: 180, useNativeDriver: true });
  expect(value(progress(week))).toBe(0);
  expect(StyleSheet.flatten(week.props.style)).toEqual(hitStyle);
  act(() => week.props.onPressOut());
  expect(timings.at(-1)!.config).toMatchObject({ toValue: 1, duration: motion.press.releaseMs });
  expect(press.stop).toHaveBeenCalled();
});

it('keeps one in-flow wrapping label and hides both decorative label copies from announcements', () => {
  mount();
  expect(radios()).toHaveLength(3);
  for (const target of radios()) {
    expect(target.props.accessible).toBe(true);
    expect(target.props.accessibilityRole).toBe('radio');
    const content = target.findAllByType(Animated.View).find(view => view.props.importantForAccessibility === 'no-hide-descendants')!;
    expect(content.props.pointerEvents).toBe('none');
    expect(content.props.accessibilityElementsHidden).toBe(true);
    const labels = target.findAllByType(Animated.Text);
    expect(labels).toHaveLength(2);
    expect(labels.filter(label => StyleSheet.flatten(label.props.style).position !== 'absolute')).toHaveLength(1);
    for (const label of labels) {
      expect(label.props.accessible).toBe(false);
      expect(label.props.numberOfLines).toBeUndefined();
      expect(label.props.maxFontSizeMultiplier).toBeUndefined();
      expect(label.props.adjustsFontSizeToFit).not.toBe(true);
    }
    const duplicate = labels.find(label => StyleSheet.flatten(label.props.style).position === 'absolute')!;
    expect(duplicate.props.accessibilityElementsHidden).toBe(true);
    expect(duplicate.props.importantForAccessibility).toBe('no-hide-descendants');
    expect(StyleSheet.flatten(target.props.style).height).toBeUndefined();
  }
});

it('does not replay selection on theme, typing, or query-refetch rerenders', () => {
  mount();
  const input = renderer!.root.findByType(TextInput);
  typed = 'Retained draft'; refetchRevision += 1; mockTheme = darkTheme; update();
  expect(timings).toHaveLength(0);
  expect(renderer!.root.findByType(TextInput)).toBe(input);
  update('week');
  const count = timings.length; const nativeProgress = progress(radio('Week'));
  act(() => nativeProgress.setValue(0.4));
  typed = 'More typing'; refetchRevision += 1; mockTheme = lightTheme; update();
  expect(timings).toHaveLength(count);
  expect(progress(radio('Week'))).toBe(nativeProgress);
  expect(value(nativeProgress)).toBe(0.4);
  expect(input.props.value).toBe('More typing');
});

it('reverses from the current native position and rejects stale completion/value callbacks', () => {
  mount(); update('week');
  const nativeProgress = progress(radio('Week'));
  const forward = timings.find(timing => timing.value === nativeProgress)!;
  act(() => nativeProgress.setValue(0.4)); update('agenda');
  const reverse = timings.filter(timing => timing.value === nativeProgress).at(-1)!;
  expect(reverse.value).toBe(forward.value);
  expect(reverse.config).toMatchObject({ toValue: 0, duration: 72 });
  expect(value(nativeProgress)).toBe(0.4);
  expect(forward.stop).toHaveBeenCalled();
  act(() => forward.complete?.({ finished: true }));
  expect(radio('Agenda').props.accessibilityState.checked).toBe(true);
  const callbacks: Array<(current: number) => void> = [];
  jest.spyOn(nativeProgress, 'stopAnimation').mockImplementation(callback => { if (callback) { callbacks.push(callback); } });
  update('week'); update('agenda');
  const count = timings.length;
  act(() => callbacks[0](0.7));
  expect(timings).toHaveLength(count);
  act(() => callbacks[1](0.3));
  const latest = timings.at(-1)!;
  expect(latest.value).toBe(nativeProgress);
  expect(latest.config).toMatchObject({ toValue: 0, duration: 54 });
  act(() => reverse.complete?.({ finished: true }));
  act(() => renderer!.unmount()); renderer = undefined;
  expect(latest.stop).toHaveBeenCalled();
  act(() => { callbacks[1](0.3); latest.complete?.({ finished: true }); });
  expect(timings).toHaveLength(count + 1);
});

it.each(['reduced', 'blur', 'background', 'inactive', 'disabled'] as const)('snaps accepted selection and press feedback on %s without replay', reason => {
  mount(); const week = radio('Week'); const stalePress = week.props.onPress; const staleFeedback = week.props.onPressIn;
  act(() => staleFeedback());
  const press = timings.at(-1)!;
  act(() => press.value.setValue(0.98));
  update('week');
  const nativeProgress = progress(week);
  const transition = timings.find(timing => timing.value === nativeProgress)!;
  act(() => nativeProgress.setValue(0.5));
  if (reason === 'reduced') { jest.mocked(useReducedMotion).mockReturnValue(true); update(); }
  else if (reason === 'blur') { route(false); }
  else if (reason === 'disabled') { enabled = false; update(); }
  else { application(reason); }
  expect(transition.stop).toHaveBeenCalled();
  expect(press.stop).toHaveBeenCalled();
  expect(value(nativeProgress)).toBe(1);
  expect(value(press.value)).toBe(1);
  expect(value(progress(radio('Agenda')))).toBe(0);
  const count = timings.length;
  act(() => { staleFeedback(); transition.complete?.({ finished: true }); });
  expect(timings).toHaveLength(count);
  if (reason !== 'reduced') {
    act(() => stalePress()); expect(onChange).not.toHaveBeenCalled();
    expect(week.props.disabled).toBe(true);
  }
  jest.mocked(useReducedMotion).mockReturnValue(false); enabled = true; route(true); application('active'); update();
  expect(timings).toHaveLength(count);
  expect(week.props.accessibilityState.checked).toBe(true);
});

it('snaps reduced-motion selection without blocking immediate radio activation', () => {
  jest.mocked(useReducedMotion).mockReturnValue(true); mount();
  act(() => radio('Month').props.onPress());
  expect(onChange).toHaveBeenCalledWith('month');
  update('month');
  expect(value(progress(radio('Month')))).toBe(1);
  expect(timings).toHaveLength(0);
});

it('invalidates a pending native position read after losing the presentation or unmounting', () => {
  mount(); update('week'); const nativeProgress = progress(radio('Week'));
  const callbacks: Array<(current: number) => void> = [];
  jest.spyOn(nativeProgress, 'stopAnimation').mockImplementation(callback => { if (callback) { callbacks.push(callback); } });
  update('agenda'); const count = timings.length; route(false);
  act(() => callbacks[0](0.6)); expect(timings).toHaveLength(count);
  expect(value(nativeProgress)).toBe(0);
  act(() => renderer!.unmount()); renderer = undefined;
  act(() => callbacks[0](0.6)); expect(timings).toHaveLength(count);
});

it('cleans option navigation/app listeners and rejects held feedback and activation after unmount', () => {
  mount(); const week = radio('Week'); const press = week.props.onPress; const feedback = week.props.onPressIn;
  act(() => feedback()); const old = timings.at(-1)!;
  act(() => renderer!.unmount()); renderer = undefined;
  expect(old.stop).toHaveBeenCalled();
  expect(appListeners.size).toBe(0);
  expect([...listeners.values()].every(entries => entries.size === 0)).toBe(true);
  const count = timings.length;
  act(() => { press(); feedback(); old.complete?.({ finished: true }); });
  expect(onChange).not.toHaveBeenCalled(); expect(timings).toHaveLength(count);
});

const FILTER_TIMING = { enterMs: 360, exitMs: 240 };
let overlayActive = false;
function ActivityProbe() { overlayActive = useOverlayActivity(); return null; }
function FilterOwner({ visible }: { visible: boolean }) {
  const [scope, setScope] = useState<'team' | 'mine'>('team');
  return <BottomSheet visible={visible} title="Calendar filters" timing={FILTER_TIMING} onClose={jest.fn()}>
    <CalendarSegmentedControl label="Calendar scope" values={['team', 'mine']} selected={scope} labelFor={viewLabel} onChange={setScope} />
  </BottomSheet>;
}
function filterNode(mounted: boolean, visible: boolean) {
  return <NavigationContext.Provider value={navigation as never}><OverlayProvider><ActivityProbe />
    {mounted ? <FilterOwner visible={visible} /> : null}
  </OverlayProvider></NavigationContext.Provider>;
}
function filterSheet() { return renderer!.root.findAllByType(Animated.View).find(view => StyleSheet.flatten(view.props.style)?.borderTopLeftRadius === 22)!; }

it.each(['exit', 'abandon'] as const)('keeps hosted filters interactive under query-pausing overlay activity and snaps retained children on %s', retirement => {
  mount(filterNode(true, true));
  expect(overlayActive).toBe(true); expect(timings).toHaveLength(0);
  act(() => filterSheet().props.onLayout({ nativeEvent: { layout: { height: 320 } } }));
  const entry = timings.at(-1)!; expect(entry.config.duration).toBe(360); finish(entry);
  const mine = radio('Mine', 'Calendar scope'); const stalePress = mine.props.onPress; const staleFeedback = mine.props.onPressIn;
  expect(mine.props.disabled).toBe(false);
  act(() => mine.props.onPress());
  expect(mine.props.accessibilityState.checked).toBe(true);
  const nativeProgress = progress(mine); const selection = timings.find(timing => timing.value === nativeProgress)!;
  expect(selection.config.duration).toBe(180);
  act(() => { nativeProgress.setValue(0.4); mine.props.onPressIn(); });
  const press = timings.at(-1)!;
  act(() => renderer!.update(filterNode(retirement !== 'abandon', false)));
  expect(selection.stop).toHaveBeenCalled(); expect(press.stop).toHaveBeenCalled();
  expect(value(nativeProgress)).toBe(1); expect(value(press.value)).toBe(1);
  expect(mine.props.disabled).toBe(true); expect(timings.at(-1)!.config.duration).toBe(240);
  const count = timings.length;
  act(() => { stalePress(); staleFeedback(); selection.complete?.({ finished: true }); });
  expect(timings).toHaveLength(count); expect(mine.props.accessibilityState.checked).toBe(true);
  if (retirement === 'exit') {
    act(() => renderer!.update(filterNode(true, true)));
    const selectionCount = timings.filter(timing => timing.value === nativeProgress).length;
    expect(mine.props.disabled).toBe(false); expect(value(nativeProgress)).toBe(1);
    expect(selectionCount).toBe(1);
  }
});
