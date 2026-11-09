import React from 'react';
import { Animated, StyleSheet, Text } from 'react-native';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { Action } from '../src/components/Action';
import { IconAction } from '../src/components/IconAction';
import { ChatScrollButton } from '../src/features/team-chat/components/ChatScrollButton';
import { useReducedMotion } from '../src/lib/useReducedMotion';
import { useNativeStackMotionOptions } from '../src/navigation/useNativeStackMotionOptions';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }) }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: jest.fn(() => false) }));
jest.mock('lucide-react-native', () => ({ ArrowDown: () => null }));

type Timing = { value: Animated.Value; config: Parameters<typeof Animated.timing>[1]; complete?: (result: { finished: boolean }) => void; stop: jest.Mock };
let renderer: ReactTestRenderer | undefined;
const timings: Timing[] = [];

function actionTarget() { return renderer!.root.findAll(node => node.props.accessibilityRole === 'button' && typeof node.props.style === 'function')[0]; }
function mount(node: React.ReactElement) { act(() => { renderer = create(node); }); }
function update(node: React.ReactElement) { act(() => renderer!.update(node)); }

beforeEach(() => {
  jest.mocked(useReducedMotion).mockReturnValue(false);
  timings.length = 0;
  jest.spyOn(Animated, 'timing').mockImplementation((value, config) => {
    const timing: Timing = { value: value as Animated.Value, config, stop: jest.fn() };
    timings.push(timing);
    return { start: (callback?: Timing['complete']) => { timing.complete = callback; }, stop: timing.stop, reset: jest.fn() };
  });
});
afterEach(() => { act(() => renderer?.unmount()); renderer = undefined; jest.restoreAllMocks(); });

it('scales action content natively without moving the target or delaying activation', () => {
  const press = jest.fn();
  mount(<Action onPress={press}>Save</Action>);
  const target = actionTarget();
  expect(StyleSheet.flatten(target.props.style({ pressed: true })).transform).toBeUndefined();
  expect(StyleSheet.flatten(target.props.style({ pressed: false })).minHeight).toBe(44);
  act(() => target.props.onPressIn());
  expect(timings.at(-1)!.config).toMatchObject({ toValue: 0.98, duration: 80, useNativeDriver: true, isInteraction: false });
  act(() => target.props.onPress()); expect(press).toHaveBeenCalledTimes(1);
  act(() => target.props.onPressOut());
  expect(timings.at(-1)!.config).toMatchObject({ toValue: 1, duration: 120 });
  expect(timings[0].stop).toHaveBeenCalled();
});

it.each(['disabled', 'loading', 'reduced'] as const)('resets a held action after %s and rejects stale presses', state => {
  const press = jest.fn();
  const node = () => <Action onPress={press} disabled={state === 'disabled' && changed} loading={state === 'loading' && changed}>Save</Action>;
  let changed = false;
  mount(node());
  const held = actionTarget().props.onPressIn;
  act(() => held());
  const timing = timings.at(-1)!;
  act(() => timing.value.setValue(0.98));
  changed = true;
  if (state === 'reduced') { jest.mocked(useReducedMotion).mockReturnValue(true); }
  update(node());
  expect((timing.value as unknown as { __getValue: () => number }).__getValue()).toBe(1);
  const count = timings.length;
  act(() => { held(); timing.complete?.({ finished: true }); });
  expect(timings).toHaveLength(count);
  if (state !== 'reduced') { expect(actionTarget().props.onPress).toBeUndefined(); }
});

it('ignores late native value reads after a rapid press reversal and unmount', () => {
  mount(<Action onPress={jest.fn()}>Save</Action>);
  const target = actionTarget();
  act(() => target.props.onPressIn());
  const value = timings[0].value;
  const callbacks: Array<(value: number) => void> = [];
  jest.spyOn(value, 'stopAnimation').mockImplementation(callback => { if (callback) { callbacks.push(callback); } });
  act(() => { target.props.onPressIn(); target.props.onPressOut(); });
  const count = timings.length;
  act(() => callbacks[0](0.99)); expect(timings).toHaveLength(count);
  act(() => callbacks[1](0.99)); expect(timings.at(-1)!.config.toValue).toBe(1);
  const stale = target.props.onPressIn;
  act(() => renderer!.unmount()); renderer = undefined;
  const finalCount = timings.length;
  act(() => stale()); expect(timings).toHaveLength(finalCount);
});

it('keeps loading content mounted and icon feedback immediate', () => {
  mount(<Action onPress={jest.fn()}>Save</Action>);
  const text = renderer!.root.findByType(Text);
  update(<Action onPress={jest.fn()} loading>Save</Action>);
  expect(renderer!.root.findByType(Text)).toBe(text);
  expect(actionTarget().props.accessibilityState.busy).toBe(true);
  update(<IconAction label="Account" icon={<Text>Icon</Text>} onPress={jest.fn()} />);
  expect(StyleSheet.flatten(actionTarget().props.style({ pressed: true }))).toMatchObject({ width: 44, height: 44, opacity: 0.7 });
});

it('fades the chat arrow natively, disables its visual exit and reverses stale completion', () => {
  const press = jest.fn();
  const node = (visible: boolean) => <ChatScrollButton visible={visible} disabled={false} busy={false} history={false} onPress={press} />;
  mount(node(false)); update(node(true));
  expect(timings.at(-1)!.config).toMatchObject({ duration: 120, useNativeDriver: true, isInteraction: false, toValue: 1 });
  const target = actionTarget();
  act(() => target.props.onPress()); expect(press).toHaveBeenCalledTimes(1);
  update(node(false));
  const exit = timings.at(-1)!;
  expect(actionTarget().props.disabled).toBe(true);
  act(() => target.props.onPress()); expect(press).toHaveBeenCalledTimes(1);
  update(node(true));
  act(() => exit.complete?.({ finished: true }));
  expect(actionTarget().props.disabled).toBe(false);
  update(node(false));
  act(() => timings.at(-1)!.complete?.({ finished: true }));
  expect(renderer!.toJSON()).toBeNull();
});

it('removes arrow motion immediately when live reduction is enabled', () => {
  mount(<ChatScrollButton visible disabled={false} busy={false} history={false} onPress={jest.fn()} />);
  jest.mocked(useReducedMotion).mockReturnValue(true);
  update(<ChatScrollButton visible={false} disabled={false} busy={false} history={false} onPress={jest.fn()} />);
  expect(renderer!.toJSON()).toBeNull();
});

it('retains platform native-stack motion normally and removes it under Reduce Motion', () => {
  let options: ReturnType<typeof useNativeStackMotionOptions>;
  function Probe() { options = useNativeStackMotionOptions(); return null; }
  mount(<Probe />); expect(options!).toEqual({ headerShown: false, animation: 'default' });
  jest.mocked(useReducedMotion).mockReturnValue(true);
  update(<Probe />); expect(options!).toEqual({ headerShown: false, animation: 'none' });
});
