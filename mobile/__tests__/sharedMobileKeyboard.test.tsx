import React from 'react';
import { AppState, AppStateStatus, Keyboard, KeyboardAvoidingView, KeyboardEvent, KeyboardMetrics, Modal, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { act, create, ReactTestInstance, ReactTestRenderer } from 'react-test-renderer';
import { SafeAreaView } from 'react-native-safe-area-context';
import type { EdgeRecord, SafeAreaViewProps } from 'react-native-safe-area-context';
import { darkTheme, lightTheme } from '../src/theme/tokens';
import { Action } from '../src/components/Action';
import { BottomSheet } from '../src/components/BottomSheet';
import { ConfirmDialog } from '../src/components/ConfirmDialog';
import { OverlayProvider } from '../src/components/OverlayProvider';
import { Screen } from '../src/components/Screen';
import { TextField } from '../src/components/TextField';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: mockTheme }) }));
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: () => true }));
jest.mock('react-native-safe-area-context', () => {
  const ReactModule = require('react');
  const { View: NativeView, StyleSheet: NativeStyleSheet } = jest.requireActual('react-native');
  return {
    // Model native additive padding so the assertions catch double or lost insets.
    SafeAreaView: ({ edges, style, ...props }: SafeAreaViewProps) => {
      const base = NativeStyleSheet.flatten(style) ?? {};
      const modes = (Array.isArray(edges)
        ? Object.fromEntries(edges.map(edge => [edge, 'additive']))
        : edges ?? { top: 'additive', right: 'additive', bottom: 'additive', left: 'additive' }) as EdgeRecord;
      const padding = (edge: keyof EdgeRecord, value: number) => modes[edge] === 'additive'
        ? value + mockSafeAreaInsets[edge]
        : modes[edge] === 'maximum' ? Math.max(value, mockSafeAreaInsets[edge]) : value;
      return ReactModule.createElement(NativeView, { ...props, style: [style, {
        paddingTop: padding('top', base.paddingTop ?? base.paddingVertical ?? base.padding ?? 0),
        paddingRight: padding('right', base.paddingRight ?? base.paddingHorizontal ?? base.padding ?? 0),
        paddingBottom: padding('bottom', base.paddingBottom ?? base.paddingVertical ?? base.padding ?? 0),
        paddingLeft: padding('left', base.paddingLeft ?? base.paddingHorizontal ?? base.padding ?? 0),
      }] });
    },
    useSafeAreaInsets: () => mockSafeAreaInsets,
  };
});
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));

let mockTheme = lightTheme;
let mockSafeAreaInsets = { top: 24, bottom: 20, left: 0, right: 0 };

type Input = NonNullable<ReturnType<typeof TextInput.State.currentlyFocusedInput>>;
type Measure = Parameters<Input['measureInWindow']>[0];
type Rect = { x: number; y: number; width: number; height: number };
let renderer: ReactTestRenderer | undefined;
let metrics: KeyboardMetrics | null;
let keyboardShown: boolean;
let focused: Input | undefined;
let inputRect: Rect;
let regionRect: Rect;
let viewportRect: Rect;
let scrollOffset: number;
let keyboardListeners: Map<string, Array<(event: KeyboardEvent) => unknown>>;
let removedListeners: jest.Mock[];
let appListener: (state: AppStateStatus) => void;
let regionMeasure: jest.Mock;
let scrollTo: jest.Mock;
let viewportMeasure: jest.Mock;
let queuedMeasures: Array<() => void>;
let delayMeasure: boolean;
let routeActive: boolean;
let routeBlur: (() => void) | undefined;
let route: React.ContextType<typeof NavigationContext>;
let appStateDescriptor: PropertyDescriptor | undefined;
const layout = (height: number) => ({ nativeEvent: { layout: { width: 480, height, x: 0, y: 0 } } });

function nativeStyle(node: ReactTestInstance) { return StyleSheet.flatten(node.findAllByType(View)[0].props.style); }
function outerSafeArea() { return renderer!.root.findAllByType(SafeAreaView)[0]; }
function footerSafeArea() { return renderer!.root.findAllByType(SafeAreaView).find(node => node.props.testID === 'screen-footer')!; }

function field() { return renderer!.root.findByType(TextInput); }
function viewport() { return renderer!.root.findAllByType(ScrollView)[0]; }
function focusField(offset = 0) {
  scrollOffset = offset;
  act(() => viewport().props.onScroll({ nativeEvent: { contentOffset: { x: 0, y: offset } } }));
  focused = {
    isConnected: true,
    focus: jest.fn(),
    measureInWindow: jest.fn((callback: Measure) => {
      const run = () => callback(inputRect.x, inputRect.y - scrollOffset, inputRect.width, inputRect.height);
      if (delayMeasure) { queuedMeasures.push(run); } else { run(); }
    }),
  } as unknown as Input;
  act(() => field().props.onFocus({ nativeEvent: { target: 123 } }));
  return focused;
}
function frames() { act(() => jest.advanceTimersByTime(50)); }
function emit(show: boolean) {
  keyboardShown = show;
  metrics = show ? { screenX: 0, screenY: 600, width: 480, height: 368 } : null;
  const event = { endCoordinates: metrics ?? { screenX: 0, screenY: 968, width: 480, height: 0 } } as KeyboardEvent;
  act(() => {
    if (show) { keyboardListeners.get('keyboardWillShow')?.forEach(listener => listener(event)); }
    keyboardListeners.get(show ? 'keyboardDidShow' : 'keyboardDidHide')!.forEach(listener => listener(event));
  });
}
function renderScreen(extraOffset = 16, dialog = false) {
  const host = <OverlayProvider><NavigationContext.Provider value={route}><Screen keyboardAware bottomSafeArea header={<Text>Header</Text>} footer={<Action onPress={jest.fn()}>Create task</Action>}><Text>Long content</Text><TextField label="Description" keyboardRevealOffset={extraOffset} /><Action onPress={jest.fn()}>Add comment</Action></Screen></NavigationContext.Provider><ConfirmDialog visible={dialog} title="Discard?" message="Message" confirmLabel="Discard" onConfirm={jest.fn()} onCancel={jest.fn()} /></OverlayProvider>;
  act(() => {
    if (renderer) { renderer.update(host); } else { renderer = create(host); }
  });
  act(() => {
    viewport().props.onLayout(layout(viewportRect.height));
    viewport().props.onContentSizeChange(480, 1600);
  });
}

beforeEach(() => {
  jest.useFakeTimers();
  mockTheme = lightTheme;
  mockSafeAreaInsets = { top: 24, bottom: 20, left: 0, right: 0 };
  metrics = null;
  keyboardShown = false;
  focused = undefined;
  inputRect = { x: 18, y: 760, width: 444, height: 80 };
  regionRect = { x: 0, y: 100, width: 480, height: 800 };
  viewportRect = { x: 18, y: 110, width: 444, height: 670 };
  scrollOffset = 0;
  queuedMeasures = [];
  delayMeasure = false;
  keyboardListeners = new Map();
  removedListeners = [];
  routeActive = true;
  routeBlur = undefined;
  route = { isFocused: () => routeActive, addListener: (_event: string, listener: () => void) => { routeBlur = listener; return jest.fn(); } } as unknown as React.ContextType<typeof NavigationContext>;
  jest.replaceProperty(require('react-native').Platform, 'OS', 'android');
  appStateDescriptor = Object.getOwnPropertyDescriptor(AppState, 'currentState');
  Object.defineProperty(AppState, 'currentState', { configurable: true, writable: true, value: 'active' });
  jest.spyOn(AppState, 'addEventListener').mockImplementation((_event, listener) => { appListener = listener; return { remove: jest.fn() }; });
  jest.spyOn(Keyboard, 'isVisible').mockImplementation(() => keyboardShown);
  jest.spyOn(Keyboard, 'metrics').mockImplementation(() => metrics);
  jest.spyOn(Keyboard, 'addListener').mockImplementation((event, listener) => {
    keyboardListeners.set(event, [...(keyboardListeners.get(event) ?? []), listener]);
    const remove = jest.fn(); removedListeners.push(remove); return { remove };
  });
  jest.spyOn(TextInput.State, 'currentlyFocusedInput').mockImplementation(() => focused);
  jest.spyOn(require('react-native'), 'findNodeHandle').mockImplementation(() => 123);
  regionMeasure = jest.fn((callback: Measure) => callback(regionRect.x, regionRect.y, regionRect.width, regionRect.height));
  jest.spyOn(View.prototype as unknown as React.ComponentRef<typeof View>, 'measureInWindow').mockImplementation(regionMeasure);
  viewportMeasure = jest.fn((callback: Measure) => callback(viewportRect.x, viewportRect.y, viewportRect.width, viewportRect.height));
  jest.spyOn(ScrollView.prototype as unknown as React.ComponentRef<typeof ScrollView>, 'getNativeScrollRef').mockReturnValue({ measureInWindow: viewportMeasure } as unknown as React.ComponentRef<typeof ScrollView>);
  scrollTo = jest.fn((options: { y: number }) => { scrollOffset = options.y; });
  jest.spyOn(ScrollView.prototype as unknown as React.ComponentRef<typeof ScrollView>, 'scrollTo').mockImplementation(scrollTo);
});
afterEach(() => {
  act(() => renderer?.unmount()); renderer = undefined;
  act(() => jest.runOnlyPendingTimers());
  if (appStateDescriptor) { Object.defineProperty(AppState, 'currentState', appStateDescriptor); }
  jest.restoreAllMocks();
  jest.useRealTimers();
});

describe.each([lightTheme, darkTheme])('Screen footer safe area in $mode mode', theme => {
  beforeEach(() => { mockTheme = theme; });

  it.each([20, 0])('moves the %s-point bottom inset onto the footer surface without changing total padding or the header/body', inset => {
    mockSafeAreaInsets.bottom = inset;
    renderScreen();
    const outer = outerSafeArea();
    const footer = footerSafeArea();
    expect(renderer!.root.findAllByType(SafeAreaView)).toHaveLength(2);
    expect(outer.props.edges).toEqual(['top']);
    expect(nativeStyle(outer)).toMatchObject({ flex: 1, backgroundColor: theme.canvas, paddingTop: 24, paddingBottom: 0 });
    expect(footer.props.edges).toEqual({ bottom: 'additive' });
    expect(nativeStyle(footer)).toMatchObject({
      flexShrink: 0, backgroundColor: theme.surface, borderTopWidth: 1, borderTopColor: theme.line,
      paddingTop: 12, paddingBottom: 12 + inset, paddingLeft: 18, paddingRight: 18,
    });
    expect(nativeStyle(outer).paddingBottom + nativeStyle(footer).paddingBottom).toBe(12 + inset);
    const header = renderer!.root.findAllByType(Text).find(node => node.props.children === 'Header')!;
    let headerParent = header.parent;
    while (headerParent && headerParent !== outer) {
      expect(headerParent.type).not.toBe(ScrollView);
      expect(headerParent.type).not.toBe(KeyboardAvoidingView);
      headerParent = headerParent.parent;
    }
    expect(headerParent === outer).toBe(true);
    expect(viewport().findAllByType(Text).some(node => node.props.children === 'Long content')).toBe(true);
    expect(StyleSheet.flatten(viewport().props.contentContainerStyle)).toMatchObject({ flexGrow: 1 });
    const body = viewport().findAllByType(View).find(node => StyleSheet.flatten(node.props.style)?.paddingHorizontal === 18)!;
    expect(StyleSheet.flatten(body.props.style)).toMatchObject({ flex: 1, paddingHorizontal: 18, paddingTop: 18, paddingBottom: 20 });
    expect(viewport().findAllByType(Action).some(node => node.props.children === 'Create task')).toBe(false);
  });

  it('adds the inset after caller surface and per-edge spacing overrides instead of replacing them', () => {
    act(() => { renderer = create(<Screen bottomSafeArea footer={<Text>Actions</Text>}
      footerStyle={[{ backgroundColor: theme.surfaceWarm, paddingVertical: 16 }, { paddingBottom: 7, paddingHorizontal: 24 }]}><Text>Body</Text></Screen>); });
    expect(nativeStyle(outerSafeArea()).paddingBottom).toBe(0);
    expect(nativeStyle(footerSafeArea())).toMatchObject({ backgroundColor: theme.surfaceWarm, paddingTop: 16, paddingBottom: 27, paddingLeft: 24, paddingRight: 24 });
    expect(footerSafeArea().props.edges).toEqual({ bottom: 'additive' });
  });

  it.each([true, false])('leaves non-opt-in footers unchanged with scroll=%s, including a fixed composer', scroll => {
    act(() => { renderer = create(<Screen keyboardAware scroll={scroll} header={<Text>Header</Text>}
      bodyStyle={{ backgroundColor: theme.surfaceWarm, paddingBottom: 5 }}
      footer={<TextField label="Composer" value="Unsent message" />} footerStyle={{ backgroundColor: theme.surfaceWarm, paddingBottom: 9 }}><Text>List viewport</Text></Screen>); });
    const footer = renderer!.root.findAllByType(View).find(node => node.props.testID === 'screen-footer')!;
    const input = field();
    const body = renderer!.root.findAllByType(Text).find(node => node.props.children === 'List viewport')!.parent!;
    expect(renderer!.root.findAllByType(SafeAreaView)).toHaveLength(1);
    expect(outerSafeArea().props.edges).toEqual(['top']);
    expect(nativeStyle(outerSafeArea()).paddingBottom).toBe(0);
    expect(footer.props.edges).toBeUndefined();
    expect(StyleSheet.flatten(footer.props.style)).toMatchObject({ backgroundColor: theme.surfaceWarm, paddingVertical: 12, paddingBottom: 9 });
    if (!scroll) { expect(StyleSheet.flatten(body.props.style)).toMatchObject({ backgroundColor: theme.surfaceWarm, paddingBottom: 5 }); }
    emit(true); frames(); emit(false); frames();
    expect(field()).toBe(input);
    expect(field().props.value).toBe('Unsent message');
    expect(renderer!.root.findAllByType(View).find(node => node.props.testID === 'screen-footer')).toBe(footer);
    expect(nativeStyle(outerSafeArea()).paddingBottom).toBe(0);
    expect(renderer!.root.findAllByType(Text).find(node => node.props.children === 'List viewport')!.parent).toBe(body);
  });

  it.each([true, false])('preserves no-footer bottomSafeArea=%s behavior before, during, and after the keyboard', bottomSafeArea => {
    act(() => { renderer = create(<Screen keyboardAware bottomSafeArea={bottomSafeArea} header={<Text>Header</Text>}><TextField label="Description" /></Screen>); });
    const input = field();
    expect(renderer!.root.findAllByType(SafeAreaView)).toHaveLength(1);
    expect(outerSafeArea().props.edges).toEqual(bottomSafeArea ? ['top', 'bottom'] : ['top']);
    expect(nativeStyle(outerSafeArea())).toMatchObject({ backgroundColor: theme.canvas, paddingTop: 24, paddingBottom: bottomSafeArea ? 20 : 0 });
    expect(renderer!.root.findAllByProps({ testID: 'screen-footer' })).toHaveLength(0);
    emit(true); frames();
    expect(outerSafeArea().props.edges).toEqual(['top']);
    expect(nativeStyle(outerSafeArea()).paddingBottom).toBe(0);
    expect(field()).toBe(input);
    emit(false); frames();
    expect(nativeStyle(outerSafeArea()).paddingBottom).toBe(bottomSafeArea ? 20 : 0);
    expect(field()).toBe(input);
  });

  it.each(['android', 'ios'])('removes only the footer inset on %s keyboard show without remounting focused content or pinned actions', os => {
    jest.replaceProperty(require('react-native').Platform, 'OS', os);
    renderScreen(72);
    const input = field();
    const nativeInput = focusField();
    const footer = footerSafeArea();
    const pinnedAction = footer.findByType(Action);
    const header = renderer!.root.findAllByType(Text).find(node => node.props.children === 'Header');
    const scrollView = viewport();
    emit(true); frames();
    expect(outerSafeArea().props.edges).toEqual(['top']);
    expect(nativeStyle(outerSafeArea()).paddingBottom).toBe(0);
    expect(footerSafeArea()).toBe(footer);
    expect(footer.props.edges).toEqual({ bottom: 'off' });
    expect(nativeStyle(footer)).toMatchObject({ backgroundColor: theme.surface, paddingTop: 12, paddingBottom: 12 });
    expect(field()).toBe(input);
    expect(TextInput.State.currentlyFocusedInput()).toBe(nativeInput);
    expect(viewport()).toBe(scrollView);
    expect(renderer!.root.findAllByType(Text).find(node => node.props.children === 'Header')).toBe(header);
    expect(footer.findByType(Action)).toBe(pinnedAction);
    expect(scrollTo).toHaveBeenLastCalledWith({ x: 0, y: 312, animated: false });
    emit(false); frames();
    expect(footerSafeArea()).toBe(footer);
    expect(footer.props.edges).toEqual({ bottom: 'additive' });
    expect(nativeStyle(footer).paddingBottom).toBe(32);
    expect(field()).toBe(input);
    expect(TextInput.State.currentlyFocusedInput()).toBe(nativeInput);
    expect(footer.findByType(Action)).toBe(pinnedAction);
  });
});

it('reveals only actual input overflow using local viewport geometry and inline-action clearance', () => {
  renderScreen(72);
  const input = focusField();
  emit(true);
  frames();
  expect(viewportMeasure).toHaveBeenCalled();
  expect(input.measureInWindow).toHaveBeenCalled();
  expect(scrollTo).toHaveBeenLastCalledWith({ x: 0, y: 312, animated: false }); // 760 + 80 + 72 - 600
  expect(renderer!.root.findByType(KeyboardAvoidingView).props.enabled).toBe(false);
  expect(viewport().findAllByType(Action).some(node => node.props.children === 'Create task')).toBe(false);
});

it.each([['non-resizing edge-to-edge', 800, 300], ['working adjustResize', 500, 0], ['partial overlap', 570, 70]] as const)('compensates only measured footer overlap on %s', (_name, regionHeight, expected) => {
  regionRect.height = regionHeight;
  renderScreen();
  emit(true);
  frames();
  const margin = renderer!.root.findAllByType(View).map(node => StyleSheet.flatten(node.props.style)?.marginBottom).filter(value => typeof value === 'number');
  expect(margin).toContain(expected);
  emit(false);
  frames();
  expect(renderer!.root.findAllByType(View).map(node => StyleSheet.flatten(node.props.style)?.marginBottom).filter(value => typeof value === 'number')).toContain(0);
});

it('moves scroll=false fixed composer only by real Android overlap and never issues a scroll', () => {
  act(() => { renderer = create(<NavigationContext.Provider value={route}><Screen keyboardAware scroll={false} footer={<Text>Composer</Text>}><Text>List viewport</Text></Screen></NavigationContext.Provider>); });
  emit(true);
  frames();
  expect(renderer!.root.findAllByType(ScrollView)).toHaveLength(0);
  expect(scrollTo).not.toHaveBeenCalled();
  expect(renderer!.root.findAllByType(View).some(node => StyleSheet.flatten(node.props.style)?.marginBottom === 300)).toBe(true);
});

it('rechecks on real viewport resize but does not reset position on quiet content/prop updates', () => {
  inputRect.y = 370;
  renderScreen();
  focusField(120);
  emit(true);
  frames();
  expect(scrollTo).not.toHaveBeenCalled();
  renderScreen();
  frames();
  expect(scrollTo).not.toHaveBeenCalled();
  viewportRect.height = 200;
  act(() => viewport().props.onLayout(layout(200)));
  frames();
  expect(scrollTo).toHaveBeenCalledTimes(1);
  expect(scrollOffset).toBeGreaterThan(120);
  act(() => viewport().props.onLayout(layout(200)));
  frames();
  expect(scrollTo).toHaveBeenCalledTimes(1);
});

it('drops delayed measurements on blur, route switch, detached input and session unmount', () => {
  renderScreen();
  delayMeasure = true;
  const input = focusField();
  emit(true);
  frames();
  act(() => field().props.onBlur({ nativeEvent: { target: 123 } }));
  act(() => queuedMeasures.splice(0).forEach(run => run()));
  expect(scrollTo).not.toHaveBeenCalled();
  focusField(); frames();
  routeActive = false; routeBlur?.();
  act(() => queuedMeasures.splice(0).forEach(run => run()));
  expect(scrollTo).not.toHaveBeenCalled();
  routeActive = true;
  focused = input;
  (input as unknown as { isConnected: boolean }).isConnected = false;
  act(() => field().props.onFocus({ nativeEvent: { target: 123 } }));
  frames();
  expect(scrollTo).not.toHaveBeenCalled();
  act(() => renderer!.unmount()); renderer = undefined;
  act(() => queuedMeasures.splice(0).forEach(run => run()));
  expect(scrollTo).not.toHaveBeenCalled();
  removedListeners.forEach(remove => expect(remove).toHaveBeenCalledTimes(1));
});

it('does not scroll hidden routes, background app or a Screen blocked by another overlay', () => {
  routeActive = false;
  renderScreen();
  focusField(); emit(true); frames();
  expect(scrollTo).not.toHaveBeenCalled();
  routeActive = true;
  Object.defineProperty(AppState, 'currentState', { configurable: true, writable: true, value: 'background' });
  appListener('background');
  act(() => field().props.onFocus({ nativeEvent: { target: 123 } })); frames();
  expect(scrollTo).not.toHaveBeenCalled();
  Object.defineProperty(AppState, 'currentState', { configurable: true, writable: true, value: 'active' });
  renderScreen(16, true);
  act(() => field().props.onFocus({ nativeEvent: { target: 123 } })); frames();
  expect(scrollTo).not.toHaveBeenCalled();
});

it('registers raw native search focus bubbling through the viewport, not only TextField focus', () => {
  renderScreen();
  const input = focusField();
  act(() => field().props.onBlur({ nativeEvent: { target: 123 } }));
  focused = input;
  act(() => viewport().props.onFocus({ nativeEvent: { target: 123 } }));
  emit(true); frames();
  expect(scrollTo).toHaveBeenLastCalledWith({ x: 0, y: 256, animated: false });
});

it('restored focus after cancel reveals again without losing input/ref identity', () => {
  renderScreen(72);
  const nativeInput = focusField(); emit(true); frames();
  const existingInput = field();
  renderScreen(72, true);
  emit(false); frames();
  focused = undefined;
  scrollOffset = 0;
  act(() => viewport().props.onScroll({ nativeEvent: { contentOffset: { x: 0, y: 0 } } }));
  (nativeInput.focus as jest.Mock).mockImplementation(() => {
    focused = nativeInput;
    field().props.onFocus({ nativeEvent: { target: 123 } });
  });
  act(() => renderer!.root.findByType(Modal).props.onRequestClose());
  renderScreen(72, false);
  frames(); frames();
  expect(nativeInput.focus).toHaveBeenCalledTimes(1);
  emit(true); frames();
  expect(field()).toBe(existingInput);
  expect(scrollTo).toHaveBeenLastCalledWith({ x: 0, y: 312, animated: false });
});

it('supports focused search inside a sheet and cancels reveal as its presentation closes', () => {
  act(() => { renderer = create(<OverlayProvider><BottomSheet visible title="Search" onClose={jest.fn()}><TextField label="Search tasks" /></BottomSheet></OverlayProvider>); });
  act(() => { viewport().props.onLayout(layout(670)); viewport().props.onContentSizeChange(480, 1600); });
  focusField(); emit(true); frames();
  expect(scrollTo).toHaveBeenCalled();
  const count = scrollTo.mock.calls.length;
  act(() => renderer!.update(<OverlayProvider><BottomSheet visible={false} title="Search" onClose={jest.fn()}><TextField label="Search tasks" /></BottomSheet></OverlayProvider>));
  frames();
  expect(scrollTo).toHaveBeenCalledTimes(count);
});
