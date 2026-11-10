import React from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { TabOwnsBottomInsetContext } from '../src/components/ScreenInsets';
import { Screen } from '../src/components/Screen';
import { useKeyboardVisible } from '../src/lib/useKeyboardVisible';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }) }));
jest.mock('../src/lib/useKeyboardVisible', () => ({ useKeyboardVisible: jest.fn(() => false) }));
jest.mock('react-native-safe-area-context', () => ({
  SafeAreaView: ({ edges, style, children, ...props }: any) => {
    const ReactModule = require('react');
    const { View: SafeView } = require('react-native');
    const bottom = Array.isArray(edges) ? edges.includes('bottom') : edges?.bottom === 'additive';
    return ReactModule.createElement(SafeView, { ...props, edges, style: [style, bottom && { paddingBottom: mockBottomInset }] }, children);
  },
}));

let mockBottomInset = 24;
let renderer: ReactTestRenderer;
function render(node: React.ReactElement) { act(() => { renderer = create(node); }); return renderer.root; }
function safe() { return renderer.root.findAllByType(View).find(node => Array.isArray(node.props.edges))!; }
beforeEach(() => { mockBottomInset = 24; jest.mocked(useKeyboardVisible).mockReturnValue(false); });
afterEach(() => { act(() => renderer?.unmount()); });

it('protects standalone content and follows updated gesture/three-button insets', () => {
  render(<Screen><Text>Content</Text></Screen>);
  expect(safe().props.edges).toEqual(['top', 'bottom']);
  expect(StyleSheet.flatten(safe().props.style).paddingBottom).toBe(24);
  mockBottomInset = 48;
  act(() => renderer.update(<Screen><Text>Content</Text></Screen>));
  expect(StyleSheet.flatten(safe().props.style).paddingBottom).toBe(48);
});

it('defers bottom insets to a containing tab navigator', () => {
  render(<TabOwnsBottomInsetContext.Provider value><Screen><Text>Content</Text></Screen></TabOwnsBottomInsetContext.Provider>);
  expect(safe().props.edges).toEqual(['top']);
  expect(StyleSheet.flatten(safe().props.style).paddingBottom).toBeUndefined();
});

it.each([true, false])('preserves the explicit bottomSafeArea=%s override', bottomSafeArea => {
  render(<TabOwnsBottomInsetContext.Provider value><Screen bottomSafeArea={bottomSafeArea}><Text>Content</Text></Screen></TabOwnsBottomInsetContext.Provider>);
  expect(safe().props.edges).toEqual(bottomSafeArea ? ['top', 'bottom'] : ['top']);
});

it('puts the inset on a standalone pinned footer only and suppresses it while the keyboard is visible', () => {
  const node = () => <Screen footer={<Text>Save</Text>}><Text>Form</Text></Screen>;
  const root = render(node());
  const viewport = root.findByType(ScrollView);
  const footer = root.findAllByType(View).find(item => item.props.testID === 'screen-footer')!;
  expect(safe().props.edges).toEqual(['top']);
  expect(footer.props.edges).toEqual({ bottom: 'additive' });
  expect(StyleSheet.flatten(footer.props.style).paddingBottom).toBe(24);
  jest.mocked(useKeyboardVisible).mockReturnValue(true);
  act(() => renderer.update(node()));
  expect(root.findByType(ScrollView)).toBe(viewport);
  expect(footer.props.edges).toEqual({ bottom: 'off' });
  expect(StyleSheet.flatten(footer.props.style).paddingBottom).toBeUndefined();
  jest.mocked(useKeyboardVisible).mockReturnValue(false);
  act(() => renderer.update(node()));
  expect(footer.props.edges).toEqual({ bottom: 'additive' });
});

it('does not add another footer inset beneath a tab-contained screen', () => {
  render(<TabOwnsBottomInsetContext.Provider value><Screen footer={<Text>Send</Text>}><Text>Chat</Text></Screen></TabOwnsBottomInsetContext.Provider>);
  const footer = renderer.root.findAllByType(View).find(item => item.props.testID === 'screen-footer')!;
  expect(safe().props.edges).toEqual(['top']);
  expect(footer.props.edges).toBeUndefined();
});
