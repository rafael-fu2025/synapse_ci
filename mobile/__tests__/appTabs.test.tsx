import React from 'react';
import { AccessibilityInfo, Animated, Dimensions, Text, TouchableOpacity } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { AppTabs } from '../src/navigation/AppTabs';
import { HomeStack } from '../src/navigation/stacks/HomeStack';
import { VisitsStack } from '../src/navigation/stacks/VisitsStack';
import { QueueStack } from '../src/navigation/stacks/QueueStack';
import { ProfileStack } from '../src/navigation/stacks/ProfileStack';
import { useTheme } from '../src/theme/ThemeProvider';
import { darkTheme, lightTheme } from '../src/theme/tokens';

jest.mock('@react-navigation/bottom-tabs', () => {
  const ReactRuntime = jest.requireActual('react');
  const { View } = jest.requireActual('react-native');
  return {
    createBottomTabNavigator: () => ({
      Navigator: (props: { children: React.ReactNode }) => ReactRuntime.createElement(View, { ...props, testID: 'tab-navigator' }),
      Screen: () => null,
    }),
  };
});
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 24, left: 0 }),
}));
jest.mock('lucide-react-native', () => ({ CalendarDays: () => null, Home: () => null, ListChecks: () => null, UserRound: () => null }));
jest.mock('../src/navigation/stacks/HomeStack', () => ({ HomeStack: () => null }));
jest.mock('../src/navigation/stacks/VisitsStack', () => ({ VisitsStack: () => null }));
jest.mock('../src/navigation/stacks/QueueStack', () => ({ QueueStack: () => null }));
jest.mock('../src/navigation/stacks/ProfileStack', () => ({ ProfileStack: () => null }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: jest.fn() }));

const originalWindow = Dimensions.get('window');
let renderer: ReactTestRenderer | undefined;
let emitPreference: (enabled: boolean) => void;
const remove = jest.fn();

async function render() {
  await act(async () => { renderer = create(<AppTabs />); });
}

function options(name = 'Home') {
  return renderer!.root.findByProps({ testID: 'tab-navigator' }).props.screenOptions({ route: { name } });
}

beforeEach(() => {
  remove.mockReset();
  Dimensions.set({ window: { width: 360, height: 800, scale: 3, fontScale: 1 } });
  jest.mocked(useTheme).mockReturnValue({ theme: lightTheme, mode: 'light', preference: 'system', setPreference: jest.fn() });
  jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(false);
  jest.spyOn(AccessibilityInfo, 'addEventListener').mockImplementation((event, listener) => {
    expect(event).toBe('reduceMotionChanged');
    emitPreference = listener as (enabled: boolean) => void;
    return { remove };
  });
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  Dimensions.set({ window: originalWindow });
  jest.restoreAllMocks();
});

describe('bottom-tab transitions', () => {
  it('enables a short native navigator slide on all four tabs without resetting state', async () => {
    await render();
    for (const name of ['Home', 'Visits', 'Queue', 'Profile']) {
      expect(options(name)).toMatchObject({
        animation: 'shift', lazy: false, freezeOnBlur: true,
        transitionSpec: { animation: 'timing', config: { duration: 240, easing: expect.any(Function) } },
      });
    }
    const screens = React.Children.toArray(renderer!.root.findByProps({ testID: 'tab-navigator' }).props.children) as React.ReactElement<{ name: string; component: unknown }>[];
    expect(screens.map(screen => [screen.props.name, screen.props.component])).toEqual([
      ['Home', HomeStack], ['Visits', VisitsStack], ['Queue', QueueStack], ['Profile', ProfileStack],
    ]);
  });

  it.each([[-1, -360], [-0.5, -180], [0, 0], [0.5, 180], [1, 360]])('keeps scenes opaque at progress %s while sliding to %s', async (value, offset) => {
    await render();
    for (const name of ['Home', 'Visits', 'Queue', 'Profile']) {
      const progress = new Animated.Value(value);
      const { sceneStyle } = options(name).sceneStyleInterpolator({ current: { progress } });
      expect(sceneStyle.opacity).toBeUndefined();
      expect(sceneStyle.transform[0].translateX.__getValue()).toBe(offset);
    }
  });

  it('uses the current window width after resizing instead of exposing a partial-width container', async () => {
    await render();
    act(() => Dimensions.set({ window: { width: 800, height: 360, scale: 3, fontScale: 1 } }));
    const { sceneStyle } = options().sceneStyleInterpolator({ current: { progress: new Animated.Value(1) } });
    expect(sceneStyle.transform[0].translateX.__getValue()).toBe(800);
  });

  it('replaces the oversized borderless ripple with opacity feedback without losing tab actions', async () => {
    await render();
    const onPress = jest.fn();
    const onLongPress = jest.fn();
    const children = <Text>Visits</Text>;
    const button = options('Visits').tabBarButton({
      onPress, onLongPress, children, accessibilityLabel: 'Visits', role: 'tab',
      'aria-selected': true, style: { flex: 1 }, android_ripple: { borderless: true },
    });
    expect(button.type).toBe(TouchableOpacity);
    expect(button.props.activeOpacity).toBe(0.7);
    expect(button.props.android_ripple).toBeUndefined();
    expect(button.props).toMatchObject({ accessibilityLabel: 'Visits', role: 'tab', 'aria-selected': true, style: { flex: 1 } });
    expect(button.props.children).toBe(children);
    button.props.onPress();
    button.props.onLongPress();
    expect(onPress).toHaveBeenCalledTimes(1);
    expect(onLongPress).toHaveBeenCalledTimes(1);
  });

  it.each([lightTheme, darkTheme])('preserves active icon colors and safe-area sizing in $mode mode', async theme => {
    jest.mocked(useTheme).mockReturnValue({ theme, mode: theme.mode, preference: 'system', setPreference: jest.fn() });
    await render();
    expect(options().tabBarActiveTintColor).toBe(theme.brandIcon);
    expect(options().tabBarStyle).toContainEqual({ height: 80, paddingBottom: 24 });
    expect(options().sceneStyle).toEqual({ flex: 1, backgroundColor: theme.canvas, overflow: 'hidden' });
  });

  it('disables transitions when Reduce Motion is enabled', async () => {
    jest.mocked(AccessibilityInfo.isReduceMotionEnabled).mockResolvedValue(true);
    await render();
    expect(options()).toMatchObject({ animation: 'none', transitionSpec: { config: { duration: 0 } } });
    expect(options().sceneStyleInterpolator).toBeUndefined();
  });

  it('responds to live Reduce Motion changes', async () => {
    await render();
    act(() => emitPreference(true));
    expect(options()).toMatchObject({ animation: 'none', transitionSpec: { config: { duration: 0 } } });
    act(() => emitPreference(false));
    expect(options()).toMatchObject({ animation: 'shift', transitionSpec: { config: { duration: 240 } } });
  });

  it('waits for the initial preference and does not overwrite a newer accessibility event', async () => {
    let resolve!: (enabled: boolean) => void;
    jest.mocked(AccessibilityInfo.isReduceMotionEnabled).mockImplementationOnce(() => new Promise(value => { resolve = value; }));
    await render();
    expect(options().animation).toBe('none');
    act(() => emitPreference(true));
    await act(async () => resolve(false));
    expect(options().animation).toBe('none');
  });

  it('fails safely without motion if the preference check is unavailable', async () => {
    jest.mocked(AccessibilityInfo.isReduceMotionEnabled).mockRejectedValueOnce(new Error('Unavailable'));
    await render();
    expect(options()).toMatchObject({ animation: 'none', transitionSpec: { config: { duration: 0 } } });
  });

  it('removes its subscription and ignores preference promises settling after unmount', async () => {
    let resolve!: (enabled: boolean) => void;
    jest.mocked(AccessibilityInfo.isReduceMotionEnabled).mockImplementationOnce(() => new Promise(value => { resolve = value; }));
    await render();
    act(() => renderer!.unmount());
    renderer = undefined;
    expect(remove).toHaveBeenCalledTimes(1);
    await act(async () => resolve(false));
  });
});
