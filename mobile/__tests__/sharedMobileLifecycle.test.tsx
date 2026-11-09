import React from 'react';
import { AccessibilityInfo, Animated, Keyboard, KeyboardEvent, Modal, Platform, StyleSheet, Text } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { BottomSheet } from '../src/components/BottomSheet';
import { ConfirmDialog } from '../src/components/ConfirmDialog';
import { OverlayProvider, useOverlayActivity } from '../src/components/OverlayProvider';
import { useKeyboardVisible } from '../src/lib/useKeyboardVisible';
import { useReducedMotion } from '../src/lib/useReducedMotion';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }) }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 24, bottom: 20, left: 0, right: 0 }) }));
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));

type Subscription = { remove: jest.Mock; emit: (value: boolean) => void };
type KeyboardSubscription = { remove: jest.Mock; emit: () => void };
type Animation = {
  value: Animated.Value;
  config: Parameters<typeof Animated.timing>[1];
  start: jest.Mock;
  stop: jest.Mock;
  reset: jest.Mock;
  callback?: (result: { finished: boolean }) => void;
};
let renderer: ReactTestRenderer | undefined;
let reducedValue: boolean | undefined;
let keyboardValue: boolean | undefined;
let activity = false;
let resolvePreference: (value: boolean) => void;
let rejectPreference: (error: Error) => void;
let preferenceSubscriptions: Subscription[];
let keyboardSubscriptions: Map<string, KeyboardSubscription[]>;
let animations: Animation[];

function PreferenceProbe() { reducedValue = useReducedMotion(); return null; }
function KeyboardProbe() { keyboardValue = useKeyboardVisible(); return null; }
function ActivityProbe() { activity = useOverlayActivity(); return null; }
function host(sheet: boolean, dialog: boolean, closed: () => void) {
  return <OverlayProvider><ActivityProbe /><BottomSheet visible={sheet} title="Sheet" onClose={jest.fn()} onDismiss={closed}><Text>Body</Text></BottomSheet><ConfirmDialog visible={dialog} title="Dialog" message="Message" confirmLabel="Confirm" onConfirm={jest.fn()} onCancel={jest.fn()} /></OverlayProvider>;
}
function visibleModals() { return renderer!.root.findAllByType(Modal).filter(node => node.props.visible); }
function measureSheet() {
  const surface = renderer!.root.findAllByType(Animated.View).find(node => StyleSheet.flatten(node.props.style)?.borderTopLeftRadius === 22)!;
  act(() => surface.props.onLayout({ nativeEvent: { layout: { height: 320 } } }));
}
function finishAnimation(index: number, finished = true) { act(() => animations[index].callback?.({ finished })); }

beforeEach(() => {
  jest.useFakeTimers();
  jest.replaceProperty(Platform, 'OS', 'android');
  reducedValue = undefined;
  keyboardValue = undefined;
  activity = false;
  preferenceSubscriptions = [];
  keyboardSubscriptions = new Map();
  animations = [];
  const preference = new Promise<boolean>((resolve, reject) => { resolvePreference = resolve; rejectPreference = reject; });
  jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockReturnValue(preference);
  jest.spyOn(AccessibilityInfo, 'addEventListener').mockImplementation((event, listener) => {
    const subscription = { remove: jest.fn(), emit: (value: boolean) => (listener as (next: boolean) => void)(value) };
    if (event === 'reduceMotionChanged') { preferenceSubscriptions.push(subscription); }
    return subscription;
  });
  jest.spyOn(Keyboard, 'isVisible').mockReturnValue(false);
  jest.spyOn(Keyboard, 'addListener').mockImplementation((event, listener) => {
    const subscription = { remove: jest.fn(), emit: () => listener({} as KeyboardEvent) };
    keyboardSubscriptions.set(event, [...(keyboardSubscriptions.get(event) ?? []), subscription]);
    return subscription;
  });
  jest.spyOn(Animated, 'timing').mockImplementation((value, config) => {
    const animation: Animation = { value: value as Animated.Value, config, start: jest.fn(), stop: jest.fn(), reset: jest.fn() };
    animation.start.mockImplementation((callback?: Animation['callback']) => { animation.callback = callback; });
    animations.push(animation);
    return animation;
  });
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  act(() => jest.runOnlyPendingTimers());
  jest.restoreAllMocks();
  jest.useRealTimers();
});

it('starts conservatively, resolves native motion preference and updates on live events', async () => {
  act(() => { renderer = create(<PreferenceProbe />); });
  expect(reducedValue).toBe(true);
  await act(async () => resolvePreference(false));
  expect(reducedValue).toBe(false);
  act(() => preferenceSubscriptions[0].emit(true));
  expect(reducedValue).toBe(true);
  act(() => preferenceSubscriptions[0].emit(false));
  expect(reducedValue).toBe(false);
});

it('does not let a slow initial preference overwrite a newer native event', async () => {
  act(() => { renderer = create(<PreferenceProbe />); });
  act(() => preferenceSubscriptions[0].emit(false));
  await act(async () => resolvePreference(true));
  expect(reducedValue).toBe(false);
});

it('removes preference listener and ignores late native work after unmount', async () => {
  act(() => { renderer = create(<PreferenceProbe />); });
  act(() => renderer!.unmount());
  renderer = undefined;
  expect(preferenceSubscriptions[0].remove).toHaveBeenCalledTimes(1);
  act(() => preferenceSubscriptions[0].emit(false));
  await act(async () => resolvePreference(false));
  expect(reducedValue).toBe(true);
});

it('keeps reduced motion when the native preference promise rejects', async () => {
  act(() => { renderer = create(<PreferenceProbe />); });
  await act(async () => rejectPreference(new Error('Unavailable preference')));
  expect(reducedValue).toBe(true);
});

it.each(['android', 'ios'] as const)('initializes native keyboard visibility and removes both live listeners on %s', os => {
  jest.replaceProperty(Platform, 'OS', os);
  jest.mocked(Keyboard.isVisible).mockReturnValue(true);
  act(() => { renderer = create(<KeyboardProbe />); });
  expect(keyboardValue).toBe(true);
  const show = os === 'ios' ? 'keyboardWillShow' : 'keyboardDidShow';
  expect([...keyboardSubscriptions.keys()]).toEqual([show, 'keyboardDidHide']);
  act(() => keyboardSubscriptions.get('keyboardDidHide')![0].emit());
  expect(keyboardValue).toBe(false);
  act(() => keyboardSubscriptions.get(show)![0].emit());
  expect(keyboardValue).toBe(true);
  act(() => renderer!.unmount());
  renderer = undefined;
  for (const subscription of [...keyboardSubscriptions.values()].flat()) { expect(subscription.remove).toHaveBeenCalledTimes(1); }
  act(() => keyboardSubscriptions.get('keyboardDidHide')![0].emit());
  expect(keyboardValue).toBe(true);
});

it('resamples a keyboard change between render and listener installation', () => {
  jest.mocked(Keyboard.isVisible).mockReturnValueOnce(false).mockReturnValue(true);
  act(() => { renderer = create(<KeyboardProbe />); });
  expect(keyboardValue).toBe(true);
});

it('does not overwrite a keyboard event emitted synchronously while subscribing', () => {
  jest.mocked(Keyboard.addListener).mockImplementation((event, listener) => {
    if (event === 'keyboardDidShow') { listener({} as KeyboardEvent); }
    return { remove: jest.fn() };
  });
  act(() => { renderer = create(<KeyboardProbe />); });
  expect(keyboardValue).toBe(true);
});

it('animates native-driver presentation and exit while retaining occupancy until the hidden commit', async () => {
  await act(async () => resolvePreference(false));
  const closed = jest.fn();
  await act(async () => { renderer = create(host(true, false, closed)); });
  measureSheet();
  expect(animations).toHaveLength(1);
  expect(animations[0].config).toMatchObject({ toValue: 1, duration: 240, useNativeDriver: true });
  finishAnimation(0);
  act(() => renderer!.update(host(false, true, closed)));
  expect(animations).toHaveLength(2);
  expect(animations[1].config).toMatchObject({ toValue: 0, duration: 180, useNativeDriver: true });
  expect(visibleModals()).toHaveLength(1);
  expect(activity).toBe(true);
  finishAnimation(1);
  expect(visibleModals()).toHaveLength(0);
  expect(closed).not.toHaveBeenCalled();
  await act(async () => jest.advanceTimersByTime(40));
  expect(closed).toHaveBeenCalledTimes(1);
  expect(visibleModals()).toHaveLength(1);
  expect(activity).toBe(true);
});

it('reverses a pending animated close without a ghost dismissal or granting the queued dialog', async () => {
  await act(async () => resolvePreference(false));
  const closed = jest.fn();
  await act(async () => { renderer = create(host(true, false, closed)); });
  measureSheet();
  finishAnimation(0);
  act(() => renderer!.update(host(false, true, closed)));
  const staleExit = animations[1];
  act(() => renderer!.update(host(true, true, closed)));
  expect(staleExit.stop).toHaveBeenCalled();
  act(() => staleExit.callback?.({ finished: true }));
  expect(visibleModals()).toHaveLength(1);
  expect(renderer!.root.findAllByType(Text).some(node => node.props.children === 'Dialog')).toBe(false);
  expect(closed).not.toHaveBeenCalled();
  act(() => renderer!.update(host(false, true, closed)));
  finishAnimation(animations.length - 1);
  await act(async () => jest.advanceTimersByTime(40));
  expect(closed).toHaveBeenCalledTimes(1);
  expect(renderer!.root.findAllByType(Text).some(node => node.props.children === 'Dialog')).toBe(true);
});

it('stops an in-flight exit when reduced motion changes and dismisses once immediately', async () => {
  await act(async () => resolvePreference(false));
  const closed = jest.fn();
  await act(async () => { renderer = create(host(true, false, closed)); });
  measureSheet();
  finishAnimation(0);
  act(() => renderer!.update(host(false, false, closed)));
  const exit = animations[1];
  act(() => preferenceSubscriptions[0].emit(true));
  expect(exit.stop).toHaveBeenCalled();
  expect(visibleModals()).toHaveLength(0);
  act(() => exit.callback?.({ finished: true }));
  act(() => jest.advanceTimersByTime(40));
  expect(closed).toHaveBeenCalledTimes(1);
  expect(activity).toBe(false);
});

it('does not restart presentation animations for native keyboard events', async () => {
  await act(async () => resolvePreference(false));
  await act(async () => { renderer = create(host(true, false, jest.fn())); });
  measureSheet();
  const count = animations.length;
  act(() => keyboardSubscriptions.get('keyboardDidShow')!.forEach(subscription => subscription.emit()));
  act(() => keyboardSubscriptions.get('keyboardDidHide')!.forEach(subscription => subscription.emit()));
  expect(animations).toHaveLength(count);
  expect(visibleModals()).toHaveLength(1);
});

it('stops motion and all native listeners and rejects late completion after provider unmount', async () => {
  await act(async () => resolvePreference(false));
  const closed = jest.fn();
  await act(async () => { renderer = create(host(true, false, closed)); });
  measureSheet();
  const enter = animations[0];
  act(() => renderer!.unmount());
  renderer = undefined;
  expect(enter.stop).toHaveBeenCalled();
  preferenceSubscriptions.forEach(subscription => expect(subscription.remove).toHaveBeenCalledTimes(1));
  for (const subscription of [...keyboardSubscriptions.values()].flat()) { expect(subscription.remove).toHaveBeenCalledTimes(1); }
  act(() => enter.callback?.({ finished: true }));
  expect(closed).not.toHaveBeenCalled();
});
