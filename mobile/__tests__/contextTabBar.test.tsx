import React from 'react';
import { act, create, ReactTestInstance, ReactTestRenderer } from 'react-test-renderer';
import { Animated, Dimensions, Keyboard, Platform, StyleSheet, Text } from 'react-native';
import { BottomTabBarHeightCallbackContext } from '@react-navigation/bottom-tabs';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { useWorkspace } from '../src/features/workspace/WorkspaceContext';
import type { AppContextMode } from '../src/features/workspace/WorkspaceContext';
import { useOverlayActivity } from '../src/components/OverlayProvider';
import { useReducedMotion } from '../src/lib/useReducedMotion';
import { ContextTabBarBinding, ContextTabBarHost, ContextTabBarProvider } from '../src/navigation/ContextTabBarBinding';
import { SharedContextTabBar } from '../src/navigation/SharedContextTabBar';
import { useTheme } from '../src/theme/ThemeProvider';
import { darkTheme, lightTheme } from '../src/theme/tokens';

jest.mock('react-native-safe-area-context', () => jest.requireActual('react-native-safe-area-context/jest/mock').default);
jest.mock('../src/features/workspace/WorkspaceContext', () => ({ useWorkspace: jest.fn() }));
jest.mock('../src/components/OverlayProvider', () => ({ useOverlayActivity: jest.fn(() => false) }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: jest.fn(() => false) }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: jest.fn() }));
jest.mock('lucide-react-native', () => {
  const ReactRuntime = jest.requireActual<typeof import('react')>('react');
  const { View: NativeView } = jest.requireActual<typeof import('react-native')>('react-native');
  return {
    BriefcaseBusiness: (props: object) => ReactRuntime.createElement(NativeView, { ...props, testID: 'bar-work-icon' }),
    Home: (props: object) => ReactRuntime.createElement(NativeView, { ...props, testID: 'bar-personal-icon' }),
  };
});

type ReadableStyle = { [key: string]: unknown; transform?: Record<string, unknown>[] };
type Completion = (result: { finished: boolean }) => void;
type Timing = {
  value: Animated.Value;
  config: Parameters<typeof Animated.timing>[1];
  start: jest.Mock;
  stop: jest.Mock;
  complete?: Completion;
};
type BarProps = React.ComponentProps<typeof SharedContextTabBar>;

const enterWorkspace = jest.fn();
const enterPersonal = jest.fn();
const timings: Timing[] = [];
const keyboardListeners: { event: string; callback: () => void; remove: jest.Mock }[] = [];
const originalWindow = Dimensions.get('window');
let renderer: ReactTestRenderer | undefined;

function workspace(overrides: Partial<ReturnType<typeof useWorkspace>> = {}): ReturnType<typeof useWorkspace> {
  return {
    mode: 'personal', personalEnabled: true, workEnabled: true, canSwitchContext: true,
    pendingDiscard: false, hasPendingSubmission: false, unitPickerVisible: false,
    enterWorkspace, enterPersonal, ...overrides,
  } as ReturnType<typeof useWorkspace>;
}

function tabProps(mode: AppContextMode = 'personal', index = 0): BottomTabBarProps {
  const names = mode === 'personal' ? ['Home', 'Visits', 'Queue', 'Profile'] : ['TeamBoard', 'MyTasks', 'TeamChat', 'TeamCalendar'];
  const labels = mode === 'personal' ? names : ['Team Board', 'My Tasks', 'Team Chat', 'Team Calendar'];
  const routes = names.map((name, i) => ({ key: `${mode}-${name}`, name, params: { item: i } }));
  return {
    state: { type: 'tab', key: `${mode}-tabs`, stale: false, index, routes, routeNames: names, history: [], preloadedRouteKeys: [] },
    descriptors: Object.fromEntries(routes.map((route, i) => [route.key, {
      options: { tabBarLabel: labels[i], tabBarIcon: jest.fn(() => null) },
    }])),
    navigation: { emit: jest.fn(() => ({ defaultPrevented: false })), navigate: jest.fn() },
    insets: { top: 0, right: 0, bottom: 12, left: 0 },
  } as unknown as BottomTabBarProps;
}

function style(node: ReactTestInstance): ReadableStyle {
  return StyleSheet.flatten(node.props.style) ?? {};
}

function number(value: unknown): number {
  if (typeof value === 'number') { return value; }
  if (value && typeof (value as { __getValue?: unknown }).__getValue === 'function') {
    return (value as { __getValue: () => number }).__getValue();
  }
  throw new Error('Expected a real Animated.Value/interpolation or a numeric style');
}

function byId(id: string) { return renderer!.root.findByProps({ testID: id }); }
function scale(id: string) { return number(style(byId(id)).transform![0].scale); }
function opacity(id: string) { return number(style(byId(id)).opacity); }

function glyphOpacity(id: string) {
  let node: ReactTestInstance | null = byId(id);
  while (node && style(node).opacity === undefined) { node = node.parent; }
  if (!node) { throw new Error(`Expected an animated glyph around ${id}`); }
  return number(style(node).opacity);
}

function caption(text: string) {
  return renderer!.root.findAllByType(Animated.Text).find(node => node.props.children === text)!;
}

function workDisc() {
  return byId('context-cta-disc').findAll(node => style(node).backgroundColor === '#ffffff' && style(node).opacity !== undefined)[0];
}

function expectLayers(reveal: number, personal: number, work: number, personalCta: number, workCta: number) {
  expect(scale('context-bar-reveal')).toBeCloseTo(reveal, 6);
  expect(opacity('context-personal-tabs')).toBeCloseTo(personal, 6);
  expect(opacity('context-work-tabs')).toBeCloseTo(work, 6);
  expect(glyphOpacity('bar-work-icon')).toBeCloseTo(personalCta, 6);
  expect(glyphOpacity('bar-personal-icon')).toBeCloseTo(workCta, 6);
  expect(number(style(workDisc()).opacity)).toBeCloseTo(workCta, 6);
  expect(number(style(caption('Work')).opacity)).toBeCloseTo(personal, 6);
  expect(number(style(caption('Personal')).opacity)).toBeCloseTo(work, 6);
}

function expectFixedCta(center = 180) {
  const host = style(byId('context-tab-bar'));
  const action = style(byId('context-switcher'));
  const ring = style(byId('context-cta-ring'));
  expect(host).toMatchObject({ paddingTop: 26, marginTop: -26, overflow: 'visible' });
  expect(action).toMatchObject({ position: 'absolute', top: 0, width: 76, left: center - 38 });
  expect(number(action.minHeight)).toBeGreaterThanOrEqual(44);
  expect(ring).toMatchObject({ width: 64, height: 64, borderRadius: 32, padding: 4 });
  expect(style(byId('context-cta-disc'))).toMatchObject({ width: 56, height: 56, borderRadius: 28 });
  expect(style(byId('context-cta-slot'))).toMatchObject({ width: 76 });
  // The disc starts 4px inside its ring, 26px above the surface: raised by 22px.
  expect(number(action.top) + number(ring.padding) - number(host.paddingTop)).toBe(-22);
  expect(host.transform).toBeUndefined();
  expect(action.transform).toBeUndefined();
  expect(ring.transform).toBeUndefined();
  expect(style(byId('context-cta-disc')).transform!.map(transform => Object.keys(transform))).toEqual([['scale']]);
  const transforms = renderer!.root.findByType(SharedContextTabBar).findAll(node => style(node).transform !== undefined);
  expect(transforms.every(node => style(node).transform!.every(transform => Object.keys(transform).every(key => key === 'scale')))).toBe(true);
}

function mount(props: BarProps = tabProps()) {
  act(() => { renderer = create(<SharedContextTabBar {...props} />); });
}

function update(mode: AppContextMode, props: BarProps, overrides: Partial<ReturnType<typeof useWorkspace>> = {}) {
  jest.mocked(useWorkspace).mockReturnValue(workspace({ mode, ...overrides }));
  act(() => renderer!.update(<SharedContextTabBar {...props} />));
}

function sample(timing: Timing, value: number) {
  expect(timing.value).toBeInstanceOf(Animated.Value);
  act(() => timing.value.setValue(value));
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers();
  timings.length = 0;
  keyboardListeners.length = 0;
  Dimensions.set({ window: { width: 360, height: 800, scale: 3, fontScale: 1 } });
  jest.mocked(useWorkspace).mockReturnValue(workspace());
  jest.mocked(useTheme).mockReturnValue({ theme: lightTheme } as ReturnType<typeof useTheme>);
  jest.mocked(useReducedMotion).mockReturnValue(false);
  jest.mocked(useOverlayActivity).mockReturnValue(false);
  jest.spyOn(Keyboard, 'isVisible').mockReturnValue(false);
  jest.spyOn(Keyboard, 'addListener').mockImplementation((event, callback) => {
    const listener = { event, callback: callback as () => void, remove: jest.fn() };
    keyboardListeners.push(listener);
    return { remove: listener.remove };
  });
  // Only scheduling is controlled. Values, interpolation and animated views are real.
  jest.spyOn(Animated, 'timing').mockImplementation((value, config) => {
    const timing: Timing = {
      value: value as Animated.Value, config,
      start: jest.fn((complete?: Completion) => { timing.complete = complete; }),
      stop: jest.fn(),
    };
    timings.push(timing);
    return { start: timing.start, stop: timing.stop, reset: jest.fn() } as Animated.CompositeAnimation;
  });
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  act(() => Dimensions.set({ window: originalWindow }));
  jest.useRealTimers();
  jest.restoreAllMocks();
});

it('anchors the fixed disc/ring above the surface without reserving the raised area as a bottom gap', () => {
  const reportHeight = jest.fn();
  const props = tabProps();
  act(() => { renderer = create(<BottomTabBarHeightCallbackContext.Provider value={reportHeight}><SharedContextTabBar {...props} /></BottomTabBarHeightCallbackContext.Provider>); });
  expectFixedCta();
  expect(style(byId('context-bar-surface'))).toMatchObject({ paddingTop: 2, paddingBottom: 12 });
  act(() => byId('context-tab-bar').props.onLayout({ nativeEvent: { layout: { height: 102 } } }));
  expect(reportHeight).toHaveBeenLastCalledWith(76);
  expect(renderer!.root.findAll(node => node.props.accessibilityRole === 'tab' && typeof node.props.onPress === 'function')).toHaveLength(4);
  expect(timings).toHaveLength(0);
});

it('supports large-font labels and equal safe-area row padding while keeping the CTA circle fixed', () => {
  Dimensions.set({ window: { width: 360, height: 800, scale: 3, fontScale: 2 } });
  const personal = { ...tabProps(), insets: { top: 0, left: 24, right: 8, bottom: 30 } };
  const work = tabProps('workspace');
  mount({ ...personal, contextBindings: { personal, workspace: work } });
  const rowHeight = 37 + Math.ceil(14 * 2) * 2;
  const rows = byId('context-personal-tabs').parent!;
  for (const id of ['context-personal-tabs', 'context-work-tabs']) {
    const visual = byId(id);
    expect(style(visual)).toMatchObject({ paddingLeft: 32, paddingRight: 16 });
    expect(style(rows)).toMatchObject({ paddingLeft: 32, paddingRight: 16 });
    expect(visual.findAll(node => style(node).flexDirection === 'row' && style(node).minHeight === rowHeight).length).toBeGreaterThan(0);
  }
  expect(style(byId('context-switcher')).minHeight).toBe(64 + 2 + 28);
  expectFixedCta(188);
});

it.each([lightTheme, darkTheme])('samples the one real reveal Value and every opacity boundary on the $mode theme', theme => {
  jest.mocked(useTheme).mockReturnValue({ theme } as ReturnType<typeof useTheme>);
  const personal = tabProps();
  const work = tabProps('workspace');
  const bindings = { personal, workspace: work };
  mount({ ...personal, contextBindings: bindings });
  expectLayers(0, 1, 0, 1, 0);
  update('workspace', { ...work, contextBindings: bindings });
  expect(timings).toHaveLength(1);
  expect(timings[0].config).toMatchObject({ toValue: 1, duration: 400, useNativeDriver: true });
  const samples = [
    [-0.1, 0, 1, 0, 1, 0], [0, 0, 1, 0, 1, 0], [0.12, 0, 1, 0, 1, 0],
    [0.16, 0.04 / 0.7, 1, 0, 1, 0], [0.25, 0.13 / 0.7, 0.23 / 0.32, 0, 1, 0],
    [0.32, 0.2 / 0.7, 0.5, 0, 0.86, 0.14], [0.47, 0.5, 0.01 / 0.32, 0, 0.56, 0.44],
    [0.48, 0.36 / 0.7, 0, 0, 0.54, 0.46], [0.5, 0.38 / 0.7, 0, 0, 0.5, 0.5],
    [0.58, 0.46 / 0.7, 0, 0, 0.34, 0.66], [0.74, 0.62 / 0.7, 0, 0.5, 0.02, 0.98],
    [0.75, 0.63 / 0.7, 0, 0.17 / 0.32, 0, 1], [0.82, 1, 0, 0.75, 0, 1],
    [0.9, 1, 0, 1, 0, 1], [1, 1, 0, 1, 0, 1], [1.1, 1, 0, 1, 0, 1],
  ];
  for (const [progress, reveal, personalOpacity, workOpacity, personalCta, workCta] of samples) {
    sample(timings[0], progress);
    expectLayers(reveal, personalOpacity, workOpacity, personalCta, workCta);
    expectFixedCta();
    expect(scale('context-cta-disc')).toBe(1);
  }
  const clip = byId('context-bar-reveal').parent!;
  expect(style(clip)).toMatchObject({ backgroundColor: theme.surface, overflow: 'hidden' });
  expect(style(byId('context-bar-reveal')).backgroundColor).toBe('#800000');
  expect(byId('context-cta-disc').findAll(node => style(node).backgroundColor === theme.brand).length).toBeGreaterThan(0);
  expect(style(byId('context-cta-ring')).backgroundColor).toBe(theme.canvas);
  expect(style(caption('Work')).color).toBe(theme.brandText);
  expect(style(caption('Personal')).color).toBe('#ffffff');
  expect(byId('bar-work-icon').props).toMatchObject({ size: 24, color: '#ffffff' });
  expect(byId('bar-personal-icon').props).toMatchObject({ size: 24, color: theme.brand });
  expect(byId('context-work-tabs').findAllByType(Text).map(node => style(node).color)).toEqual(['#ffffff', '#e9c9c9', '#e9c9c9', '#e9c9c9']);
  expect(byId('context-personal-tabs').findAllByType(Text).map(node => style(node).color)).toEqual([theme.brandIcon, theme.ink500, theme.ink500, theme.ink500]);
  for (const id of ['context-personal-tabs', 'context-work-tabs']) {
    expect(byId(id).props).toMatchObject({ pointerEvents: 'none', accessibilityElementsHidden: true, importantForAccessibility: 'no-hide-descendants' });
  }
});

it('recomputes a centered, corner-covering reveal after measured width/height and asymmetric inset changes', () => {
  const personal = tabProps();
  const work = tabProps('workspace');
  mount(personal);
  update('workspace', work);
  sample(timings[0], 1);
  for (const measurement of [{ width: 360, height: 76, left: 0, right: 0, bottom: 12 }, { width: 800, height: 94, left: 24, right: 8, bottom: 30 }]) {
    const props = { ...work, insets: { top: 0, left: measurement.left, right: measurement.right, bottom: measurement.bottom } };
    update('workspace', props);
    act(() => byId('context-bar-surface').props.onLayout({ nativeEvent: { layout: { width: measurement.width, height: measurement.height } } }));
    const centerX = (measurement.width + measurement.left - measurement.right) / 2;
    const radius = Math.hypot(Math.max(centerX, measurement.width - centerX), measurement.height - 6) + 1;
    const reveal = style(byId('context-bar-reveal'));
    expectFixedCta(centerX);
    expect(number(reveal.width)).toBeCloseTo(radius * 2, 6);
    expect(number(reveal.height)).toBeCloseTo(radius * 2, 6);
    expect(number(reveal.borderRadius)).toBeCloseTo(radius, 6);
    expect(number(reveal.left) + radius).toBeCloseTo(centerX, 6);
    expect(number(reveal.top) + radius).toBeCloseTo(6, 6);
    for (const [x, y] of [[0, 0], [measurement.width, 0], [0, measurement.height], [measurement.width, measurement.height]]) {
      expect(Math.hypot(x - centerX, y - 6)).toBeLessThan(radius);
    }
    expect(style(byId('context-bar-surface')).paddingBottom).toBe(measurement.bottom);
  }
  expect(timings).toHaveLength(1);
  expectLayers(1, 0, 1, 0, 1);
});

it('reverses from the actual Value with distance-scaled timing and ignores stale completions', () => {
  const personal = tabProps();
  const work = tabProps('workspace');
  mount(personal);
  update('workspace', work);
  const forward = timings[0];
  sample(forward, 0.6);
  update('personal', personal);
  const reverse = timings[1];
  expect(forward.stop).toHaveBeenCalled();
  expect(reverse.value).toBe(forward.value);
  expect(reverse.config).toMatchObject({ toValue: 0, duration: 240, useNativeDriver: true });
  act(() => forward.complete?.({ finished: true }));
  expect(number(reverse.value)).toBe(0.6);
  sample(reverse, 0.2);
  update('workspace', work);
  const resumed = timings[2];
  expect(reverse.stop).toHaveBeenCalled();
  expect(resumed.value).toBe(forward.value);
  expect(resumed.config).toMatchObject({ toValue: 1, duration: 320, useNativeDriver: true });
  act(() => { forward.complete?.({ finished: false }); reverse.complete?.({ finished: true }); });
  expect(number(resumed.value)).toBe(0.2);
  const stops = resumed.stop.mock.calls.length;
  act(() => renderer!.unmount());
  renderer = undefined;
  expect(resumed.stop).toHaveBeenCalledTimes(stops + 1);
});

it('bounds a near-end reversal and does not animate an already settled endpoint', () => {
  const personal = tabProps();
  const work = tabProps('workspace');
  mount(personal);
  update('workspace', work);
  sample(timings[0], 0.08);
  update('personal', personal);
  expect(timings[1].config).toMatchObject({ toValue: 0, duration: 160, useNativeDriver: true });
  sample(timings[1], 0);
  update('personal', { ...personal, insets: { ...personal.insets, bottom: 0 } });
  expect(timings).toHaveLength(2);
  expect(style(byId('context-bar-surface')).paddingBottom).toBe(6);
});

it('drops delayed native stop lookups from superseded effects and from an unmounted bar', () => {
  const personal = tabProps();
  const work = tabProps('workspace');
  mount(personal);
  update('workspace', work);
  const value = timings[0].value;
  sample(timings[0], 0.6);
  const lookups: ((position: number) => void)[] = [];
  jest.spyOn(value, 'stopAnimation').mockImplementation(callback => { if (callback) { lookups.push(callback); } });
  update('personal', personal);
  update('workspace', work);
  expect(lookups).toHaveLength(2);
  const count = timings.length;
  act(() => lookups[0](0.6));
  expect(timings).toHaveLength(count);
  act(() => lookups[1](0.6));
  expect(timings).toHaveLength(count + 1);
  expect(timings[count].config).toMatchObject({ toValue: 1, duration: 160, useNativeDriver: true });
  update('personal', personal);
  const pending = lookups[2];
  act(() => renderer!.unmount());
  renderer = undefined;
  act(() => pending(0.6));
  expect(timings).toHaveLength(count + 1);
});

it('snaps to the accepted endpoint on live reduced motion and stops only the active reveal', () => {
  const personal = tabProps();
  const work = tabProps('workspace');
  jest.mocked(useReducedMotion).mockReturnValue(true);
  mount(personal);
  update('workspace', work);
  expect(timings).toHaveLength(0);
  expectLayers(1, 0, 1, 0, 1);
  jest.mocked(useReducedMotion).mockReturnValue(false);
  update('personal', personal);
  sample(timings[0], 0.4);
  act(() => byId('context-switcher').props.onPressIn());
  const cachedPress = byId('context-switcher').props;
  sample(timings[1], 0.94);
  expect(scale('context-cta-disc')).toBe(0.94);
  jest.mocked(useReducedMotion).mockReturnValue(true);
  update('personal', personal);
  expect(timings[0].stop).toHaveBeenCalled();
  expect(number(timings[0].value)).toBe(0);
  expectLayers(0, 1, 0, 1, 0);
  expect(scale('context-cta-disc')).toBe(1);
  act(() => { cachedPress.onPressIn(); cachedPress.onPressOut(); });
  expect(timings).toHaveLength(2);
  expect(scale('context-cta-disc')).toBe(1);
  expectFixedCta();
});

it('animates only press scale on a separate Value without moving or rotating the CTA or screen', () => {
  mount();
  update('workspace', tabProps('workspace'));
  sample(timings[0], 0.5);
  const before = scale('context-bar-reveal');
  act(() => byId('context-switcher').props.onPressIn());
  const press = timings[1];
  expect(press.value).not.toBe(timings[0].value);
  expect(press.config).toMatchObject({ toValue: 0.94, duration: 100, useNativeDriver: true });
  sample(press, 0.94);
  expect(scale('context-cta-disc')).toBe(0.94);
  expect(scale('context-bar-reveal')).toBe(before);
  expectFixedCta();
  act(() => byId('context-switcher').props.onPressOut());
  expect(timings[2].value).toBe(press.value);
  expect(timings[2].config).toMatchObject({ toValue: 1, duration: 100, useNativeDriver: true });
  sample(timings[2], 1);
  expect(scale('context-cta-disc')).toBe(1);
});

it('hides the entire bar for real keyboard events and restores the same reveal endpoint with listener cleanup', () => {
  const reportHeight = jest.fn();
  const props = tabProps();
  function tree() { return <BottomTabBarHeightCallbackContext.Provider value={reportHeight}><SharedContextTabBar {...props} /></BottomTabBarHeightCallbackContext.Provider>; }
  act(() => { renderer = create(tree()); });
  jest.mocked(useWorkspace).mockReturnValue(workspace({ mode: 'workspace' }));
  act(() => renderer!.update(tree()));
  const bar = renderer!.root.findByType(SharedContextTabBar);
  const action = byId('context-switcher').props;
  const tab = renderer!.root.findByProps({ accessibilityLabel: 'Visits' }).props;
  const show = keyboardListeners.find(listener => listener.event === (Platform.OS === 'ios' ? 'keyboardWillShow' : 'keyboardDidShow'))!;
  const hide = keyboardListeners.find(listener => listener.event === 'keyboardDidHide')!;
  act(() => byId('context-tab-bar').props.onLayout({ nativeEvent: { layout: { height: 102 } } }));
  expect(reportHeight).toHaveBeenLastCalledWith(76);
  act(() => show.callback());
  expect(renderer!.toJSON()).toBeNull();
  expect(reportHeight).toHaveBeenLastCalledWith(0);
  expect(renderer!.root.findByType(SharedContextTabBar)).toBe(bar);
  act(() => { tab.onPress(); tab.onLongPress(); action.onPress(); action.onPressIn(); action.onPressOut(); });
  expect(props.navigation.emit).not.toHaveBeenCalled();
  expect(props.navigation.navigate).not.toHaveBeenCalled();
  expect(enterWorkspace).not.toHaveBeenCalled();
  expect(enterPersonal).not.toHaveBeenCalled();
  expect(timings).toHaveLength(1);
  sample(timings[0], 1);
  act(() => hide.callback());
  expectLayers(1, 0, 1, 0, 1);
  expectFixedCta();
  act(() => byId('context-tab-bar').props.onLayout({ nativeEvent: { layout: { height: 102 } } }));
  expect(reportHeight).toHaveBeenLastCalledWith(76);
  expect(timings).toHaveLength(1);
  act(() => renderer!.unmount());
  renderer = undefined;
  expect(show.remove).toHaveBeenCalledTimes(1);
  expect(hide.remove).toHaveBeenCalledTimes(1);
});

it('starts hidden when the keyboard is already visible and reserves no CTA slot for a single-context account', () => {
  jest.mocked(Keyboard.isVisible).mockReturnValue(true);
  jest.mocked(useWorkspace).mockReturnValue(workspace({ canSwitchContext: false }));
  mount();
  expect(renderer!.toJSON()).toBeNull();
  act(() => keyboardListeners.find(listener => listener.event === 'keyboardDidHide')!.callback());
  expect(byId('context-tab-bar')).toBeTruthy();
  expect(style(byId('context-tab-bar')).paddingTop).toBe(0);
  expect(number(style(byId('context-tab-bar')).marginTop)).toEqual(expect.closeTo(0));
  for (const testID of ['context-switcher', 'context-cta-ring', 'context-cta-disc', 'context-cta-slot']) {
    expect(renderer!.root.findAllByProps({ testID })).toHaveLength(0);
  }
  expect(renderer!.root.findAll(node => node.props.accessibilityRole === 'tab' && typeof node.props.onPress === 'function')).toHaveLength(4);
});

it('uses current CTA actions/locks but rejects a callback from the previous context mode', () => {
  const personal = tabProps();
  mount(personal);
  const held = byId('context-switcher').props.onPress;
  const freshEnter = jest.fn();
  update('personal', personal, { enterWorkspace: freshEnter, hasPendingSubmission: true });
  act(() => held());
  expect(freshEnter).not.toHaveBeenCalled();
  update('personal', personal, { enterWorkspace: freshEnter });
  act(() => { held(); held(); });
  expect(freshEnter).toHaveBeenCalledTimes(1);
  expect(enterWorkspace).not.toHaveBeenCalled();
  update('workspace', tabProps('workspace'));
  act(() => held());
  expect(freshEnter).toHaveBeenCalledTimes(1);
  expect(enterPersonal).not.toHaveBeenCalled();
  // An accepted mode change releases the throttle before its 240ms window.
  act(() => byId('context-switcher').props.onPress());
  expect(enterPersonal).toHaveBeenCalledTimes(1);
});

it('rejects old navigation, inactive-context and revoked-route tab callbacks', () => {
  const personal = tabProps();
  mount(personal);
  const held = renderer!.root.findByProps({ accessibilityLabel: 'Visits' }).props;
  const replacement = tabProps();
  update('personal', replacement);
  act(() => { held.onPress(); held.onLongPress(); });
  expect(personal.navigation.emit).not.toHaveBeenCalled();
  expect(personal.navigation.navigate).not.toHaveBeenCalled();
  const current = renderer!.root.findByProps({ accessibilityLabel: 'Visits' }).props;
  act(() => current.onPress());
  expect(replacement.navigation.navigate).toHaveBeenCalledWith('Visits', { item: 1 });
  const work = tabProps('workspace');
  update('workspace', work);
  act(() => current.onPress());
  expect(replacement.navigation.navigate).toHaveBeenCalledTimes(1);
  const chat = renderer!.root.findByProps({ accessibilityLabel: 'Team Chat' }).props;
  update('workspace', { ...work, state: { ...work.state, routes: work.state.routes.filter(route => route.name !== 'TeamChat') } });
  act(() => { chat.onPress(); chat.onLongPress(); });
  expect(work.navigation.emit).not.toHaveBeenCalled();
  expect(work.navigation.navigate).not.toHaveBeenCalled();
});

it.each(['available', 'pendingDiscard', 'hasPendingSubmission', 'unitPickerVisible', 'overlay'] as const)('blocks existing tab and CTA callbacks when %s locks the bar', lock => {
  const props = tabProps();
  mount(props);
  const tab = renderer!.root.findByProps({ accessibilityLabel: 'Visits' }).props;
  const action = byId('context-switcher').props.onPress;
  jest.mocked(useOverlayActivity).mockReturnValue(lock === 'overlay');
  update('personal', { ...props, available: lock !== 'available' }, lock === 'pendingDiscard' ? { pendingDiscard: true } : lock === 'hasPendingSubmission' ? { hasPendingSubmission: true } : lock === 'unitPickerVisible' ? { unitPickerVisible: true } : {});
  expect(byId('context-switcher').props.accessibilityState.disabled).toBe(true);
  expect(renderer!.root.findByProps({ accessibilityLabel: 'Visits' }).props.accessibilityState.disabled).toBe(true);
  act(() => { tab.onPress(); tab.onLongPress(); action(); });
  expect(props.navigation.emit).not.toHaveBeenCalled();
  expect(props.navigation.navigate).not.toHaveBeenCalled();
  expect(enterWorkspace).not.toHaveBeenCalled();
});

it.each([0, 1])('invalidates a held tab press on state replacement and routes with fresh focus after index %i', index => {
  const props = tabProps('personal', index);
  mount(props);
  const held = renderer!.root.findByProps({ accessibilityLabel: 'Visits' }).props.onPress;
  update('personal', { ...props, state: { ...props.state, index: 1 - index } });
  act(() => held());
  expect(props.navigation.emit).not.toHaveBeenCalled();
  expect(props.navigation.navigate).not.toHaveBeenCalled();
  act(() => renderer!.root.findByProps({ accessibilityLabel: 'Visits' }).props.onPress());
  expect(props.navigation.emit).toHaveBeenCalledWith({ type: 'tabPress', target: 'personal-Visits', canPreventDefault: true });
  if (index === 0) { expect(props.navigation.navigate).not.toHaveBeenCalled(); }
  else { expect(props.navigation.navigate).toHaveBeenCalledWith('Visits', { item: 1 }); }
});

it('invalidates held tab/CTA handlers and the debounce timer when the bar unmounts', () => {
  const props = tabProps();
  mount(props);
  const contextProps = byId('context-switcher').props;
  const action = contextProps.onPress;
  const tab = renderer!.root.findByProps({ accessibilityLabel: 'Visits' }).props;
  const timeout = jest.spyOn(globalThis, 'setTimeout');
  const clearTimeoutSpy = jest.spyOn(globalThis, 'clearTimeout');
  act(() => action());
  const debounce = timeout.mock.results[timeout.mock.calls.findIndex((call: unknown[]) => call[1] === 240)].value;
  expect(enterWorkspace).toHaveBeenCalledTimes(1);
  act(() => renderer!.unmount());
  renderer = undefined;
  expect(clearTimeoutSpy).toHaveBeenCalledWith(debounce);
  jest.clearAllMocks();
  const animationCount = timings.length;
  act(() => { tab.onPress(); tab.onLongPress(); action(); contextProps.onPressIn(); contextProps.onPressOut(); });
  expect(timings).toHaveLength(animationCount);
  expect(props.navigation.emit).not.toHaveBeenCalled();
  expect(props.navigation.navigate).not.toHaveBeenCalled();
  expect(enterWorkspace).not.toHaveBeenCalled();
});

it('publishes fresh binding props, releases missing modes, and never replaces the persistent host during lease changes', () => {
  const personal = tabProps();
  const work = tabProps('workspace');
  function tree(personalBinding: BottomTabBarProps | null, workBinding: BottomTabBarProps | null, mode: AppContextMode) {
    return <ContextTabBarProvider>
      {personalBinding ? <ContextTabBarBinding key="personal" mode="personal" {...personalBinding} /> : null}
      {workBinding ? <ContextTabBarBinding key="workspace" mode="workspace" {...workBinding} /> : null}
      <ContextTabBarHost mode={mode} />
    </ContextTabBarProvider>;
  }
  act(() => { renderer = create(tree(personal, work, 'personal')); });
  const host = renderer!.root.findByType(SharedContextTabBar);
  expect(renderer!.root.findAllByType(SharedContextTabBar)).toHaveLength(1);
  expect(renderer!.root.findAllByType(ContextTabBarBinding).every(binding => binding.children.length === 0)).toBe(true);
  const replacement = { ...tabProps('personal', 1), insets: { top: 4, left: 10, right: 2, bottom: 20 } };
  act(() => renderer!.update(tree(replacement, work, 'personal')));
  expect(renderer!.root.findByType(SharedContextTabBar)).toBe(host);
  expect(host.props).toMatchObject({ state: replacement.state, descriptors: replacement.descriptors, navigation: replacement.navigation, insets: replacement.insets, available: true });
  jest.mocked(useWorkspace).mockReturnValue(workspace({ mode: 'workspace' }));
  act(() => renderer!.update(tree(null, work, 'workspace')));
  expect(renderer!.root.findByType(SharedContextTabBar)).toBe(host);
  expect(host.props.contextBindings.personal).toBeUndefined();
  const held = renderer!.root.findByProps({ accessibilityLabel: 'Team Chat' }).props;
  const action = byId('context-switcher').props.onPress;
  act(() => renderer!.update(tree(null, null, 'workspace')));
  expect(host.props.available).toBe(false);
  expect(byId('context-switcher').props.disabled).toBe(true);
  act(() => { held.onPress(); held.onLongPress(); action(); });
  expect(work.navigation.emit).not.toHaveBeenCalled();
  expect(enterPersonal).not.toHaveBeenCalled();
  const newWork = tabProps('workspace', 2);
  act(() => renderer!.update(tree(null, newWork, 'workspace')));
  expect(renderer!.root.findByType(SharedContextTabBar)).toBe(host);
  expect(host.props.available).toBe(true);
  expect(host.props.state).toBe(newWork.state);
  act(() => held.onPress());
  expect(work.navigation.navigate).not.toHaveBeenCalled();
  act(() => renderer!.root.findByProps({ accessibilityLabel: 'My Tasks' }).props.onPress());
  expect(newWork.navigation.navigate).toHaveBeenCalledWith('MyTasks', { item: 1 });
});

it('retains the direct-bar fallback for a standalone inner navigator without a registry', () => {
  act(() => { renderer = create(<ContextTabBarBinding mode="personal" {...tabProps()} />); });
  expect(renderer!.root.findAllByType(SharedContextTabBar)).toHaveLength(1);
  expectFixedCta();
});
