import React, { useState } from 'react';
import { AccessibilityInfo, Animated, KeyboardAvoidingView, Modal, PanResponder, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { Action } from '../src/components/Action';
import { BottomSheet } from '../src/components/BottomSheet';
import { ConfirmDialog } from '../src/components/ConfirmDialog';
import { OverlayProvider, useOverlayActivity } from '../src/components/OverlayProvider';
import { Screen } from '../src/components/Screen';
import { TextField } from '../src/components/TextField';
import { IconAction } from '../src/components/IconAction';
import { PageHeader } from '../src/components/PageHeader';
import { useKeyboardVisible } from '../src/lib/useKeyboardVisible';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }) }));
let mockReducedMotion = true;
jest.mock('../src/lib/useReducedMotion', () => ({ useReducedMotion: () => mockReducedMotion }));
jest.mock('../src/lib/useKeyboardVisible', () => ({ useKeyboardVisible: jest.fn(() => false) }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View, useSafeAreaInsets: () => ({ top: 24, right: 0, bottom: 20, left: 0 }) }));
jest.mock('lucide-react-native', () => new Proxy({}, { get: () => () => null }));

let renderer: ReactTestRenderer;
let active = false;
function Probe() { active = useOverlayActivity(); return null; }
function Host({ sheet, dialog, closed }: { sheet: boolean; dialog: boolean; closed: () => void }) {
  return (
    <OverlayProvider>
      <Probe />
      <BottomSheet visible={sheet} title="Unit picker" onClose={jest.fn()} onDismiss={closed}><Text>Units</Text></BottomSheet>
      <ConfirmDialog visible={dialog} title="Discard?" message="Unsaved changes" confirmLabel="Discard" onConfirm={jest.fn()} onCancel={jest.fn()} />
    </OverlayProvider>
  );
}
function visibleModals() { return renderer.root.findAllByType(Modal).filter(node => node.props.visible); }

beforeEach(() => {
  jest.useFakeTimers();
  jest.replaceProperty(Platform, 'OS', 'android');
  mockReducedMotion = true;
  jest.mocked(useKeyboardVisible).mockReturnValue(false);
});
afterEach(() => {
  act(() => renderer?.unmount());
  jest.runOnlyPendingTimers();
  jest.useRealTimers();
  jest.restoreAllMocks();
});

it('serializes a sheet-to-dialog transition until the outgoing native modal closes', () => {
  const closed = jest.fn();
  act(() => { renderer = create(<Host sheet dialog={false} closed={closed} />); });
  expect(visibleModals()).toHaveLength(1);
  expect(active).toBe(true);
  act(() => renderer.update(<Host sheet={false} dialog closed={closed} />));
  expect(closed).not.toHaveBeenCalled();
  expect(visibleModals()).toHaveLength(0);
  act(() => jest.advanceTimersByTime(40));
  expect(closed).toHaveBeenCalledTimes(1);
  expect(visibleModals()).toHaveLength(1);
  expect(active).toBe(true);
  act(() => renderer.update(<Host sheet={false} dialog={false} closed={closed} />));
  act(() => jest.advanceTimersByTime(40));
  expect(visibleModals()).toHaveLength(0);
  expect(active).toBe(false);
});

it('does not show a queued dialog cancelled before its turn', () => {
  const closed = jest.fn();
  act(() => { renderer = create(<Host sheet dialog closed={closed} />); });
  expect(visibleModals()).toHaveLength(1);
  act(() => renderer.update(<Host sheet dialog={false} closed={closed} />));
  act(() => renderer.update(<Host sheet={false} dialog={false} closed={closed} />));
  act(() => jest.advanceTimersByTime(40));
  expect(active).toBe(false);
  expect(visibleModals()).toHaveLength(0);
});

it('keeps required sheets non-dismissible and makes ordinary sheets scrollable', () => {
  const close = jest.fn();
  act(() => { renderer = create(<BottomSheet visible title="Choose a unit" onClose={close} dismissible={false} footer={<Text>Footer</Text>}><Text>Body</Text></BottomSheet>); });
  act(() => visibleModals()[0].props.onRequestClose());
  expect(close).not.toHaveBeenCalled();
  expect(renderer.root.findAllByType(IconAction)).toHaveLength(0);
  const scroll = renderer.root.findByType(ScrollView);
  expect(scroll.props.keyboardShouldPersistTaps).toBe('handled');
  expect(scroll.findAllByType(Text).some(node => node.props.children === 'Footer')).toBe(false);
});

it('runs confirmation once, and Android Back cancels rather than confirms', () => {
  const confirm = jest.fn();
  const cancel = jest.fn();
  act(() => { renderer = create(<ConfirmDialog visible title="Cancel event?" message="This remains in history." confirmLabel="Cancel event" onConfirm={confirm} onCancel={cancel} />); });
  const action = renderer.root.findAllByType(Action).find(node => node.props.children === 'Cancel event')!;
  act(() => { action.props.onPress(); action.props.onPress(); });
  expect(confirm).toHaveBeenCalledTimes(1);
  act(() => renderer.update(<ConfirmDialog visible={false} title="Cancel event?" message="This remains in history." confirmLabel="Cancel event" onConfirm={confirm} onCancel={cancel} />));
  act(() => jest.advanceTimersByTime(40));
  act(() => renderer.update(<ConfirmDialog visible title="Cancel event?" message="This remains in history." confirmLabel="Cancel event" onConfirm={confirm} onCancel={cancel} />));
  act(() => visibleModals()[0].props.onRequestClose());
  expect(cancel).toHaveBeenCalledTimes(1);
  expect(confirm).toHaveBeenCalledTimes(1);
});

it('exposes missing and pending action handlers as disabled without replacing labels', () => {
  act(() => { renderer = create(<Action>Unavailable</Action>); });
  let press = renderer.root.findByProps({ accessibilityRole: 'button', disabled: true });
  expect(press.props.accessibilityRole).toBe('button');
  expect(press.props.disabled).toBe(true);
  expect(StyleSheet.flatten(press.props.style({ pressed: false })).opacity).toBe(0.5);
  act(() => renderer.update(<Action loading onPress={jest.fn()}>Save task</Action>));
  press = renderer.root.findByProps({ accessibilityRole: 'button', disabled: true });
  expect(press.props.accessibilityState).toEqual({ disabled: true, busy: true });
  expect(renderer.root.findByType(Text).props.children).toBe('Save task');
});

it('preserves the field instance when focusing and forwards accessibility/keyboard behavior', () => {
  const focus = jest.fn();
  const change = jest.fn();
  act(() => { renderer = create(<TextField label="Checklist item" value="One" onChangeText={change} onFocus={focus} returnKeyType="next" />); });
  const input = renderer.root.findByType(TextInput);
  expect(input.props.accessibilityLabel).toBe('Checklist item');
  act(() => input.props.onFocus({ nativeEvent: {} }));
  expect(focus).toHaveBeenCalledTimes(1);
  expect(renderer.root.findByType(TextInput)).toBe(input);
  act(() => renderer.update(<TextField label="Checklist item" value="Two" onChangeText={change} error="Enter a value" />));
  expect(renderer.root.findByType(TextInput)).toBe(input);
  expect(input.props.accessibilityHint).toBe('Enter a value');
});

it('keeps the form footer outside its scroll and avoids double Android keyboard padding', () => {
  act(() => { renderer = create(<Screen keyboardAware bottomSafeArea footer={<Text>Save</Text>}><TextField label="Title" /></Screen>); });
  expect(renderer.root.findByType(KeyboardAvoidingView).props.enabled).toBe(false);
  expect(renderer.root.findByType(ScrollView).findAllByType(Text).some(node => node.props.children === 'Save')).toBe(false);
  const safe = renderer.root.findAllByType(View).find(node => Array.isArray(node.props.edges))!;
  const footer = renderer.root.findAllByType(View).find(node => node.props.testID === 'screen-footer')!;
  const input = renderer.root.findByType(TextInput);
  const save = footer.findByType(Text);
  expect(safe.props.edges).toEqual(['top']);
  expect(footer.props.edges).toEqual({ bottom: 'additive' });
  expect(StyleSheet.flatten(footer.props.style)).toMatchObject({ paddingVertical: 12, backgroundColor: '#ffffff' });
  jest.mocked(useKeyboardVisible).mockReturnValue(true);
  act(() => renderer.update(<Screen keyboardAware bottomSafeArea footer={<Text>Save</Text>}><TextField label="Title" /></Screen>));
  expect(safe.props.edges).toEqual(['top']);
  expect(footer.props.edges).toEqual({ bottom: 'off' });
  expect(renderer.root.findByType(TextInput)).toBe(input);
  expect(footer.findByType(Text)).toBe(save);
  expect(renderer.root.findAllByType(View).find(node => node.props.testID === 'screen-footer')).toBe(footer);
  expect(renderer.root.findByType(ScrollView).findAllByType(Text).some(node => node.props.children === 'Save')).toBe(false);
});

it('keeps a disabled Back control visible with accessible state', () => {
  const back = jest.fn();
  act(() => { renderer = create(<PageHeader title="Saving" onBack={back} backDisabled />); });
  const button = renderer.root.findByProps({ accessibilityRole: 'button', disabled: true });
  expect(button.props.disabled).toBe(true);
  expect(button.props.accessibilityState.disabled).toBe(true);
  expect(button.props.onPress).toBeUndefined();
});

it('does not re-present a reopened sheet during the native-dismissal gap', () => {
  const closed = jest.fn();
  act(() => { renderer = create(<Host sheet dialog={false} closed={closed} />); });
  act(() => renderer.update(<Host sheet={false} dialog closed={closed} />));
  act(() => renderer.update(<Host sheet dialog closed={closed} />));
  expect(visibleModals()).toHaveLength(0);
  expect(closed).not.toHaveBeenCalled();
  act(() => jest.advanceTimersByTime(40));
  expect(closed).toHaveBeenCalledTimes(1);
  expect(visibleModals()).toHaveLength(1);
  expect(renderer.root.findAllByType(Text).some(node => node.props.children === 'Discard?')).toBe(true);
});

it('ignores stale native callbacks from an earlier presentation cycle on iOS', () => {
  jest.replaceProperty(Platform, 'OS', 'ios');
  const closed = jest.fn();
  act(() => { renderer = create(<Host sheet dialog={false} closed={closed} />); });
  const firstDismiss = visibleModals()[0].props.onDismiss;
  act(() => renderer.update(<Host sheet={false} dialog={false} closed={closed} />));
  expect(active).toBe(true);
  act(() => firstDismiss());
  expect(closed).toHaveBeenCalledTimes(1);
  act(() => renderer.update(<Host sheet dialog={false} closed={closed} />));
  const secondDismiss = visibleModals()[0].props.onDismiss;
  act(() => renderer.update(<Host sheet={false} dialog={false} closed={closed} />));
  act(() => firstDismiss());
  expect(closed).toHaveBeenCalledTimes(1);
  expect(active).toBe(true);
  act(() => { secondDismiss(); secondDismiss(); });
  expect(closed).toHaveBeenCalledTimes(2);
  expect(active).toBe(false);
});

it('retains the outgoing native host when its requesting component unmounts', () => {
  const closed = jest.fn();
  const host = (sheetMounted: boolean) => (
    <OverlayProvider>
      <Probe />
      {sheetMounted ? <BottomSheet visible title="Outgoing sheet" onClose={jest.fn()} onDismiss={closed}><Text>Body</Text></BottomSheet> : null}
      <ConfirmDialog visible title="Queued dialog" message="Waiting" confirmLabel="Confirm" onConfirm={jest.fn()} onCancel={jest.fn()} />
    </OverlayProvider>
  );
  act(() => { renderer = create(host(true)); });
  act(() => renderer.update(host(false)));
  expect(visibleModals()).toHaveLength(0);
  expect(closed).not.toHaveBeenCalled();
  expect(active).toBe(true);
  act(() => jest.advanceTimersByTime(40));
  expect(closed).not.toHaveBeenCalled();
  expect(visibleModals()).toHaveLength(1);
  expect(renderer.root.findAllByType(Text).some(node => node.props.children === 'Queued dialog')).toBe(true);
});

it('stabilizes activity consumers and preserves hosted child state when request props update', () => {
  let renders = 0;
  function Child() {
    const [value, setValue] = useState('');
    return <TextInput testID="hosted-input" value={value} onChangeText={setValue} />;
  }
  function Owner({ title }: { title: string }) {
    useOverlayActivity();
    renders += 1;
    return <BottomSheet visible title={title} onClose={jest.fn()}><Child /></BottomSheet>;
  }
  const host = (title: string) => <OverlayProvider><Owner title={title} /></OverlayProvider>;
  act(() => { renderer = create(host('First')); });
  expect(renders).toBeLessThan(8);
  const input = renderer.root.findByType(TextInput);
  act(() => input.props.onChangeText('Retained value'));
  act(() => renderer.update(host('Updated')));
  expect(renderer.root.findByType(TextInput)).toBe(input);
  expect(input.props.value).toBe('Retained value');
  expect(renderer.root.findAllByType(Text).some(node => node.props.children === 'Updated')).toBe(true);
  expect(renders).toBeLessThan(10);
});

it('waits for the iOS native callback and does not cancel its completion when reopened', () => {
  jest.replaceProperty(Platform, 'OS', 'ios');
  const closed = jest.fn();
  act(() => { renderer = create(<Host sheet dialog={false} closed={closed} />); });
  const callback = visibleModals()[0].props.onDismiss;
  act(() => callback()); // Not a real close yet.
  expect(closed).not.toHaveBeenCalled();
  act(() => renderer.update(<Host sheet={false} dialog closed={closed} />));
  act(() => renderer.update(<Host sheet dialog closed={closed} />));
  act(() => jest.advanceTimersByTime(200));
  expect(active).toBe(true);
  expect(visibleModals()).toHaveLength(0);
  expect(closed).not.toHaveBeenCalled();
  act(() => { callback(); callback(); });
  expect(closed).toHaveBeenCalledTimes(1);
  expect(visibleModals()).toHaveLength(1);
  expect(renderer.root.findAllByType(Text).some(node => node.props.children === 'Discard?')).toBe(true);
});

it('invalidates late native callbacks on a session-bound provider unmount', () => {
  jest.replaceProperty(Platform, 'OS', 'ios');
  const closed = jest.fn();
  const host = (identity: string) => <OverlayProvider key={identity}><BottomSheet visible title={`Private ${identity}`} onClose={jest.fn()} onDismiss={closed}><Text>Private body</Text></BottomSheet></OverlayProvider>;
  act(() => { renderer = create(host('user-one')); });
  const stale = visibleModals()[0].props.onDismiss;
  act(() => renderer.update(host('user-two')));
  act(() => { stale(); jest.advanceTimersByTime(50); });
  expect(closed).not.toHaveBeenCalled();
  expect(visibleModals()).toHaveLength(1);
  expect(renderer.root.findAllByType(Text).some(node => node.props.children === 'Private user-one')).toBe(false);
  act(() => renderer.unmount());
  act(() => stale());
  expect(closed).not.toHaveBeenCalled();
});

it('captures native input focus only at presentation and never restores it behind the next overlay', () => {
  const first = { focus: jest.fn() } as unknown as ReturnType<typeof TextInput.State.currentlyFocusedInput>;
  const second = { focus: jest.fn() } as unknown as ReturnType<typeof TextInput.State.currentlyFocusedInput>;
  let current = first;
  const capture = jest.spyOn(TextInput.State, 'currentlyFocusedInput').mockImplementation(() => current);
  jest.spyOn(require('react-native'), 'findNodeHandle').mockImplementation(() => 123);
  const focusTitle = jest.spyOn(AccessibilityInfo, 'setAccessibilityFocus');
  function HostFocus({ sheet, dialog }: { sheet: boolean; dialog: boolean }) {
    return <OverlayProvider><BottomSheet visible={sheet} title="Sheet" onClose={jest.fn()}><Text>Body</Text></BottomSheet><ConfirmDialog visible={dialog} title="Dialog" message="Message" confirmLabel="Confirm" onConfirm={jest.fn()} onCancel={jest.fn()} /></OverlayProvider>;
  }
  act(() => { renderer = create(<HostFocus sheet dialog />); });
  expect(capture).toHaveBeenCalledTimes(1); // Queued dialog has not captured anything.
  act(() => visibleModals()[0].props.onShow());
  expect(focusTitle).toHaveBeenCalledWith(123);
  current = second;
  act(() => visibleModals()[0].props.onRequestClose());
  act(() => renderer.update(<HostFocus sheet={false} dialog />));
  act(() => jest.advanceTimersByTime(40));
  expect(first!.focus).not.toHaveBeenCalled();
  expect(capture).toHaveBeenCalledTimes(2);
  act(() => visibleModals()[0].props.onRequestClose());
  act(() => renderer.update(<HostFocus sheet={false} dialog={false} />));
  act(() => jest.advanceTimersByTime(40));
  act(() => jest.advanceTimersByTime(40));
  expect(second!.focus).toHaveBeenCalledTimes(1);
  expect(first!.focus).not.toHaveBeenCalled();
});

it('rejects callbacks from a same-request requeue and resets confirmation action state', () => {
  const confirm = jest.fn();
  const cancel = jest.fn();
  const closed = jest.fn();
  const host = (visible: boolean) => <OverlayProvider><ConfirmDialog visible={visible} title="Same dialog" message="Message" confirmLabel="Confirm" onConfirm={confirm} onCancel={cancel} onDismiss={closed} /></OverlayProvider>;
  act(() => { renderer = create(host(true)); });
  const stale = visibleModals()[0].props.onDismiss;
  act(() => renderer.root.findAllByType(Action)[0].props.onPress());
  act(() => renderer.update(host(false)));
  act(() => renderer.update(host(true)));
  expect(visibleModals()).toHaveLength(0);
  act(() => jest.advanceTimersByTime(40));
  expect(closed).toHaveBeenCalledTimes(1);
  act(() => renderer.root.findAllByType(Action)[0].props.onPress());
  expect(confirm).toHaveBeenCalledTimes(2);
  act(() => renderer.update(host(false)));
  act(() => stale());
  expect(closed).toHaveBeenCalledTimes(1);
  act(() => jest.advanceTimersByTime(40));
  expect(closed).toHaveBeenCalledTimes(2);
});

it('does not restore detached or abandoned native inputs', () => {
  const input = { focus: jest.fn() } as unknown as ReturnType<typeof TextInput.State.currentlyFocusedInput>;
  jest.spyOn(TextInput.State, 'currentlyFocusedInput').mockReturnValue(input);
  jest.spyOn(require('react-native'), 'findNodeHandle').mockReturnValue(null);
  const close = jest.fn();
  const host = (shown: boolean) => <BottomSheet visible={shown} title="Sheet" onClose={close}><Text>Body</Text></BottomSheet>;
  act(() => { renderer = create(host(true)); });
  act(() => visibleModals()[0].props.onRequestClose());
  act(() => renderer.update(host(false)));
  act(() => jest.advanceTimersByTime(40));
  act(() => jest.advanceTimersByTime(40));
  expect(input!.focus).not.toHaveBeenCalled();
});

it.each(['android', 'ios'] as const)('bounds sheet body to keyboard-resized available space without double insets on %s', os => {
  jest.replaceProperty(Platform, 'OS', os);
  const host = () => <BottomSheet visible title="Long content" onClose={jest.fn()} footer={<Text>Fixed footer</Text>}><Text>Scrollable body</Text></BottomSheet>;
  act(() => { renderer = create(host()); });
  const modal = visibleModals()[0];
  expect(modal.props.navigationBarTranslucent).toBe(true);
  const avoiding = renderer.root.findByType(KeyboardAvoidingView);
  expect(avoiding.props.enabled).toBe(os === 'ios');
  expect(avoiding.props.behavior).toBe(os === 'ios' ? 'padding' : undefined);
  const available = renderer.root.findAllByType(View).find(node => typeof node.props.onLayout === 'function' && node.props.collapsable === false)!;
  act(() => available.props.onLayout({ nativeEvent: { layout: { height: 300 } } }));
  const sheet = renderer.root.findAllByType(Animated.View).find(node => StyleSheet.flatten(node.props.style)?.borderTopLeftRadius === 22)!;
  expect(StyleSheet.flatten(sheet.props.style)).toMatchObject({ maxHeight: 252, paddingBottom: 20 });
  jest.mocked(useKeyboardVisible).mockReturnValue(true);
  act(() => renderer.update(host()));
  expect(StyleSheet.flatten(sheet.props.style).paddingBottom).toBe(8);
  const body = renderer.root.findByType(ScrollView);
  expect(body.props.automaticallyAdjustKeyboardInsets).toBe(false);
  expect(StyleSheet.flatten(body.props.style)).toMatchObject({ minHeight: 0, flexShrink: 1, flexGrow: 0 });
  expect(body.findAllByType(Text).some(node => node.props.children === 'Fixed footer')).toBe(false);
});

it('omits the misleading swipe handle on required sheets and limits ordinary gestures to the handle', () => {
  const close = jest.fn();
  const responder = { panHandlers: { onStartShouldSetResponder: jest.fn() } } as unknown as ReturnType<typeof PanResponder.create>;
  const spy = jest.spyOn(PanResponder, 'create').mockReturnValue(responder);
  const host = (required: boolean) => <BottomSheet visible title="Choose" dismissible={!required} onClose={close}><Text>Body</Text></BottomSheet>;
  act(() => { renderer = create(host(true)); });
  expect(renderer.root.findAllByType(View).filter(node => node.props.onStartShouldSetResponder === responder.panHandlers.onStartShouldSetResponder)).toHaveLength(0);
  act(() => renderer.update(host(false)));
  const handle = renderer.root.findAllByType(View).filter(node => node.props.onStartShouldSetResponder === responder.panHandlers.onStartShouldSetResponder);
  expect(handle).toHaveLength(1);
  expect(renderer.root.findByType(ScrollView).props.onStartShouldSetResponder).not.toBe(responder.panHandlers.onStartShouldSetResponder);
  const config = spy.mock.calls[spy.mock.calls.length - 1][0];
  const gesture = { dy: 90, dx: 2, vy: 1 } as Parameters<NonNullable<typeof config.onPanResponderRelease>>[1];
  const event = {} as Parameters<NonNullable<typeof config.onPanResponderRelease>>[0];
  act(() => { config.onPanResponderGrant!(event, { ...gesture, dy: 0 }); config.onPanResponderMove!(event, gesture); config.onPanResponderRelease!(event, gesture); config.onPanResponderRelease!(event, gesture); });
  expect(close).toHaveBeenCalledTimes(1);
});

it('locks confirmation and all dismiss routes while loading but supports a controlled retry after failure', () => {
  const confirm = jest.fn();
  const cancel = jest.fn();
  const host = (loading: boolean) => <ConfirmDialog visible title="Confirm?" message="Message" confirmLabel="Submit" onConfirm={confirm} onCancel={cancel} loading={loading} />;
  act(() => { renderer = create(host(false)); });
  const submit = () => renderer.root.findAllByType(Action).find(node => node.props.children === 'Submit')!.props.onPress();
  act(() => { submit(); submit(); });
  expect(confirm).toHaveBeenCalledTimes(1);
  act(() => renderer.update(host(true)));
  act(() => { submit(); visibleModals()[0].props.onRequestClose(); });
  renderer.root.findAllByType(Pressable).forEach(node => expect(node.props.disabled).toBe(true));
  expect(confirm).toHaveBeenCalledTimes(1);
  expect(cancel).not.toHaveBeenCalled();
  act(() => renderer.update(host(false)));
  act(() => submit());
  expect(confirm).toHaveBeenCalledTimes(2);
});

it('forwards refresh control and opts into iOS keyboard avoidance without changing the legacy shell', () => {
  jest.replaceProperty(Platform, 'OS', 'ios');
  const refresh = <RefreshControl refreshing={false} onRefresh={jest.fn()} />;
  act(() => { renderer = create(<Screen keyboardAware bottomSafeArea header={<Text>Header</Text>} footer={<Text>Footer</Text>} refreshControl={refresh}><Text>Body</Text></Screen>); });
  expect(renderer.root.findByType(ScrollView).props.refreshControl).toBe(refresh);
  expect(renderer.root.findByType(ScrollView).props.automaticallyAdjustKeyboardInsets).toBe(false);
  expect(renderer.root.findByType(KeyboardAvoidingView).props.behavior).toBe('padding');
  expect(renderer.root.findByType(KeyboardAvoidingView).props.enabled).toBe(true);
  act(() => renderer.update(<Screen bottomSafeArea={false}><Text>Legacy body</Text></Screen>));
  expect(renderer.root.findAllByType(KeyboardAvoidingView)).toHaveLength(0);
  expect(renderer.root.findByType(ScrollView).props.keyboardDismissMode).toBeUndefined();
  expect(renderer.root.findAllByType(View).find(node => node.props.edges)!.props.edges).toEqual(['top']);
});
