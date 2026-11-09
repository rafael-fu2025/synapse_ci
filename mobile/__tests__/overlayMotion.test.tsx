import React from 'react';
import { Animated, Modal, PanResponder, Platform, StyleSheet, Text } from 'react-native';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { BottomSheet } from '../src/components/BottomSheet';
import { ConfirmDialog } from '../src/components/ConfirmDialog';
import { OverlayProvider } from '../src/components/OverlayProvider';
import { useReducedMotion } from '../src/lib/useReducedMotion';
import { useKeyboardVisible } from '../src/lib/useKeyboardVisible';
import { useSheetDrag } from '../src/components/useSheetDrag';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }) }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: jest.fn(() => false) }));
jest.mock('../src/lib/useKeyboardVisible', () => ({ useKeyboardVisible: jest.fn(() => false) }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 24, bottom: 20, left: 0, right: 0 }) }));
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));

type Animation = { value: Animated.Value; config: Record<string, unknown>; complete?: (result: { finished: boolean }) => void; stop: jest.Mock };
let renderer: ReactTestRenderer | undefined;
const timings: Animation[] = [];
const springs: Animation[] = [];
function value(node: unknown) { return (node as { __getValue: () => number }).__getValue(); }
function sheet() { return renderer!.root.findAllByType(Animated.View).find(node => StyleSheet.flatten(node.props.style)?.borderTopLeftRadius === 22)!; }
function offset() { return value(StyleSheet.flatten(sheet().props.style).transform[0].translateY); }
function layout(height: number) { act(() => sheet().props.onLayout({ nativeEvent: { layout: { height } } })); }
function mount(node: React.ReactElement) { act(() => { renderer = create(node); }); }
function update(node: React.ReactElement) { act(() => renderer!.update(node)); }
function finish(animation: Animation) { act(() => { animation.value.setValue(animation.config.toValue as number); animation.complete?.({ finished: true }); }); }

beforeEach(() => {
  jest.useFakeTimers(); timings.length = 0; springs.length = 0;
  jest.replaceProperty(Platform, 'OS', 'android');
  jest.mocked(useReducedMotion).mockReturnValue(false);
  jest.mocked(useKeyboardVisible).mockReturnValue(false);
  const mock = (collection: Animation[]) => (animated: Parameters<typeof Animated.timing>[0], config: object) => {
    const animation: Animation = { value: animated as Animated.Value, config: config as Record<string, unknown>, stop: jest.fn() };
    collection.push(animation);
    return { start: (complete?: Animation['complete']) => { animation.complete = complete; }, stop: animation.stop, reset: jest.fn() };
  };
  jest.spyOn(Animated, 'timing').mockImplementation(mock(timings));
  jest.spyOn(Animated, 'spring').mockImplementation(mock(springs));
});
afterEach(() => { act(() => renderer?.unmount()); renderer = undefined; jest.runOnlyPendingTimers(); jest.restoreAllMocks(); jest.useRealTimers(); });

it.each([false, true])('uses dialog-specific native timing for standalone/hosted=%s', hosted => {
  const tree = (visible: boolean) => {
    const dialog = <ConfirmDialog visible={visible} title="Confirm" message="Body" confirmLabel="Done" onConfirm={jest.fn()} onCancel={jest.fn()} />;
    return hosted ? <OverlayProvider>{dialog}</OverlayProvider> : dialog;
  };
  mount(tree(true));
  expect(timings.at(-1)!.config).toMatchObject({ duration: 180, useNativeDriver: true });
  const dialog = renderer!.root.findAllByType(Animated.View).find(node => StyleSheet.flatten(node.props.style)?.borderRadius === 18)!;
  expect(value(StyleSheet.flatten(dialog.props.style).transform[0].scale)).toBe(0.97);
  update(tree(false)); expect(timings.at(-1)!.config.duration).toBe(140);
});

it.each([false, true])('waits for measured sheet height before native entry for hosted=%s and freezes exit geometry', hosted => {
  const tree = (visible: boolean) => {
    const content = <BottomSheet visible={visible} title="Filters" onClose={jest.fn()}><Text>Body</Text></BottomSheet>;
    return hosted ? <OverlayProvider>{content}</OverlayProvider> : content;
  };
  mount(tree(true)); expect(timings).toHaveLength(0);
  layout(0); expect(timings).toHaveLength(0);
  layout(320); expect(timings.at(-1)!.config).toMatchObject({ duration: 240, useNativeDriver: true });
  expect(offset()).toBe(320);
  act(() => timings.at(-1)!.value.setValue(0.5));
  layout(480); expect(offset()).toBe(160);
  finish(timings.at(-1)!); expect(offset()).toBe(0);
  update(tree(false)); expect(timings.at(-1)!.config.duration).toBe(180);
  act(() => timings.at(-1)!.value.setValue(0.5));
  const exiting = offset(); layout(600); expect(offset()).toBe(exiting);
});

it.each([false, true])('applies an optional sheet timing only to its measured presenter for hosted=%s', hosted => {
  const timing = { enterMs: 360, exitMs: 240 };
  const tree = (visible: boolean, override = true) => {
    const content = <BottomSheet visible={visible} timing={override ? timing : undefined} title="Calendar filters" onClose={jest.fn()}><Text>Body</Text></BottomSheet>;
    return hosted ? <OverlayProvider>{content}</OverlayProvider> : content;
  };
  mount(tree(true));
  expect(timings).toHaveLength(0);
  layout(320);
  const entry = timings.at(-1)!;
  expect(entry.config).toMatchObject({ duration: 360, useNativeDriver: true, isInteraction: false });
  act(() => entry.value.setValue(0.4));
  const nativeOffset = offset();
  update(tree(false));
  const exit = timings.at(-1)!;
  expect(exit.config).toMatchObject({ toValue: 0, duration: 240 });
  expect(offset()).toBe(nativeOffset);
  update(tree(true));
  expect(exit.stop).toHaveBeenCalled();
  const reopened = timings.at(-1)!;
  expect(reopened.value).toBe(entry.value);
  expect(reopened.config).toMatchObject({ toValue: 1, duration: 360 });
  expect(offset()).toBe(nativeOffset);
  act(() => exit.complete?.({ finished: true }));
  expect(renderer!.root.findByType(Modal).props.visible).toBe(true);
  finish(reopened);
  update(tree(false)); finish(timings.at(-1)!);
  act(() => jest.advanceTimersByTime(40));
  update(tree(true, false)); layout(320);
  expect(timings.at(-1)!.config.duration).toBe(240);
  finish(timings.at(-1)!); update(tree(false, false));
  expect(timings.at(-1)!.config.duration).toBe(180);
});

it.each(['android', 'ios'] as const)('keeps the custom filter exit lease ahead of queued default dialog presentation on %s', os => {
  jest.replaceProperty(Platform, 'OS', os);
  const closed = jest.fn();
  const tree = (filters: boolean, confirm: boolean) => <OverlayProvider>
    <BottomSheet visible={filters} timing={{ enterMs: 360, exitMs: 240 }} title="Calendar filters" onClose={jest.fn()} onDismiss={closed}><Text>Body</Text></BottomSheet>
    <ConfirmDialog visible={confirm} title="Queued dialog" message="Waiting" confirmLabel="Done" onConfirm={jest.fn()} onCancel={jest.fn()} />
  </OverlayProvider>;
  mount(tree(true, false)); layout(320); finish(timings.at(-1)!);
  const modal = renderer!.root.findByType(Modal); const dismissed = modal.props.onDismiss;
  update(tree(false, true));
  const exit = timings.at(-1)!;
  expect(exit.config.duration).toBe(240);
  expect(renderer!.root.findAllByType(Modal).filter(node => node.props.visible)).toHaveLength(1);
  expect(renderer!.root.findAllByType(Text).some(node => node.props.children === 'Queued dialog')).toBe(false);
  finish(exit);
  expect(renderer!.root.findAllByType(Modal).filter(node => node.props.visible)).toHaveLength(0);
  expect(closed).not.toHaveBeenCalled();
  if (os === 'ios') { act(() => dismissed()); }
  else { act(() => jest.advanceTimersByTime(40)); }
  expect(closed).toHaveBeenCalledTimes(1);
  expect(renderer!.root.findAllByType(Modal).filter(node => node.props.visible)).toHaveLength(1);
  expect(renderer!.root.findAllByType(Text).some(node => node.props.children === 'Queued dialog')).toBe(true);
  expect(timings.at(-1)!.config.duration).toBe(180);
  act(() => { dismissed(); exit.complete?.({ finished: true }); });
  expect(closed).toHaveBeenCalledTimes(1);
  expect(renderer!.root.findByType(Modal).props.visible).toBe(true);
});

it('allows close before layout and rejects a stale readiness callback', () => {
  const tree = (visible: boolean) => <BottomSheet visible={visible} title="Filters" onClose={jest.fn()}><Text>Body</Text></BottomSheet>;
  mount(tree(true));
  const ready = sheet().props.onLayout;
  update(tree(false)); expect(timings.at(-1)!.config.duration).toBe(180);
  const count = timings.length;
  act(() => ready({ nativeEvent: { layout: { height: 320 } } })); expect(timings).toHaveLength(count);
});

it.each([
  [false, 'layout'], [true, 'layout'], [false, 'fallback'], [true, 'fallback'],
] as const)('replays readiness from a pre-hide exit on same-cycle reopen for hosted=%s, source=%s', (hosted, source) => {
  const closed = jest.fn();
  const tree = (visible: boolean) => {
    const content = <BottomSheet visible={visible} title="Filters" onClose={jest.fn()} onDismiss={closed}><Text>Body</Text></BottomSheet>;
    return hosted ? <OverlayProvider>{content}</OverlayProvider> : content;
  };
  mount(tree(true));
  const surface = sheet();
  expect(timings).toHaveLength(0);
  update(tree(false));
  const exit = timings.at(-1)!;
  const count = timings.length;
  if (source === 'layout') { layout(320); }
  else {
    act(() => renderer!.root.findByType(Modal).props.onShow());
    act(() => jest.advanceTimersByTime(40));
  }
  expect(timings).toHaveLength(count);
  const travel = source === 'layout' ? 320 : offset();
  update(tree(true));
  expect(sheet()).toBe(surface);
  expect(exit.stop).toHaveBeenCalled();
  const entry = timings.at(-1)!;
  expect(entry.config).toMatchObject({ toValue: 1, duration: 240, useNativeDriver: true });
  expect(offset()).toBe(travel);
  act(() => exit.complete?.({ finished: true }));
  expect(renderer!.root.findByType(Modal).props.visible).toBe(true);
  expect(closed).not.toHaveBeenCalled();
  finish(entry);
  expect(offset()).toBe(0);
});

it('uses a guarded next-frame fallback after native show without a sheet measurement', () => {
  mount(<BottomSheet visible title="Filters" onClose={jest.fn()}><Text>Body</Text></BottomSheet>);
  act(() => renderer!.root.findByType(Modal).props.onShow());
  act(() => jest.advanceTimersByTime(40));
  expect(timings.at(-1)!.config.duration).toBe(240);
});

let dragResult: ReturnType<typeof useSheetDrag>;
let dragOptions: Parameters<typeof useSheetDrag>[0];
function DragProbe() { dragResult = useSheetDrag(dragOptions); return null; }
function dragConfig() {
  // PanResponder's handlers are exercised through the captured create config.
  return responderConfigs.at(-1)!;
}
const responderConfigs: Parameters<typeof import('react-native').PanResponder.create>[0][] = [];
function setupDrag() {
  responderConfigs.length = 0;
  jest.spyOn(PanResponder, 'create').mockImplementation((config: typeof responderConfigs[number]) => {
    responderConfigs.push(config); return { panHandlers: {} } as ReturnType<typeof PanResponder.create>;
  });
  dragOptions = { visible: true, settled: true, dismissible: true, abandoned: false, keyboard: false, reduced: false, travelHeight: 400, onClose: jest.fn(), requestRestore: jest.fn() };
  mount(<DragProbe />);
}
function begin(dy = 0) { act(() => { dragConfig().onPanResponderGrant!({} as never, { dy } as never); }); }
function move(dy: number) { act(() => { dragConfig().onPanResponderMove!({} as never, { dy } as never); }); }
function release(dy: number, vy = 0) { act(() => { dragConfig().onPanResponderRelease!({} as never, { dy, vy } as never); }); }

it('retains the released drag offset and exits without resetting it to zero', () => {
  setupDrag(); begin(); move(120); release(120);
  expect(dragOptions.onClose).toHaveBeenCalledTimes(1); expect(value(dragResult.drag)).toBe(120);
  dragOptions = { ...dragOptions, visible: false }; update(<DragProbe />);
  act(() => jest.advanceTimersByTime(40)); expect(value(dragResult.drag)).toBe(120); expect(springs).toHaveLength(0);
});

it('returns a rejected close and unlocks another attempt; delayed acceptance stops recovery', () => {
  setupDrag(); begin(); move(120); release(120);
  act(() => jest.advanceTimersByTime(40)); expect(springs.at(-1)!.config).toMatchObject({ toValue: 0, useNativeDriver: true, overshootClamping: true });
  finish(springs.at(-1)!);
  begin(); move(100); release(100); expect(dragOptions.onClose).toHaveBeenCalledTimes(2);
  act(() => jest.advanceTimersByTime(40));
  const recovery = springs.at(-1)!; act(() => recovery.value.setValue(50));
  dragOptions = { ...dragOptions, visible: false }; update(<DragProbe />);
  expect(recovery.stop).toHaveBeenCalled(); expect(value(dragResult.drag)).toBe(50);
});

it('re-grabs a returning sheet from its current native value without jumping', () => {
  setupDrag(); begin(); move(60); release(60);
  const recovery = springs.at(-1)!; act(() => recovery.value.setValue(30));
  begin(10); move(20); expect(value(dragResult.drag)).toBe(40);
  expect(recovery.stop).toHaveBeenCalled();
});

it('keeps the responder stable and invalidates drag when keyboard/access changes', () => {
  setupDrag(); begin(); move(100);
  dragOptions = { ...dragOptions, keyboard: true }; update(<DragProbe />);
  release(100, 1); expect(dragOptions.onClose).not.toHaveBeenCalled(); expect(responderConfigs).toHaveLength(1);
  dragOptions = { ...dragOptions, keyboard: false, abandoned: true }; update(<DragProbe />);
  begin(); move(100); release(100, 1); expect(dragOptions.onClose).not.toHaveBeenCalled();
});
