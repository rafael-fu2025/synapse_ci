import React from 'react';
import { NativeModules, Platform } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { SystemNavigationBarProvider, dimNavigationBar, navigationBarDarkIcons, useSystemNavigationBar, type NavigationBarSurface } from '../src/components/SystemNavigationBar';
import { darkTheme, lightTheme } from '../src/theme/tokens';

jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: mockTheme }) }));
let mockTheme = lightTheme;
const setAppearance = jest.fn();
let renderer: ReactTestRenderer;
function Surface({ kind, enabled = true }: { kind: NavigationBarSurface; enabled?: boolean }) { useSystemNavigationBar(kind, enabled); return null; }
function render(children: React.ReactNode = null) { act(() => { renderer = create(<SystemNavigationBarProvider>{children}</SystemNavigationBarProvider>); }); }
function update(children: React.ReactNode = null) { act(() => { renderer.update(<SystemNavigationBarProvider>{children}</SystemNavigationBarProvider>); }); }
beforeEach(() => {
  jest.replaceProperty(Platform, 'OS', 'android');
  NativeModules.SystemNavigationBar = { setAppearance };
  mockTheme = lightTheme; setAppearance.mockClear();
});
afterEach(() => { act(() => renderer?.unmount()); delete NativeModules.SystemNavigationBar; jest.restoreAllMocks(); });

it('uses dark icons on light surfaces and light icons on dark or brand surfaces', () => {
  expect(navigationBarDarkIcons(lightTheme.canvas)).toBe(true);
  expect(navigationBarDarkIcons(lightTheme.surface)).toBe(true);
  expect(navigationBarDarkIcons(lightTheme.brand)).toBe(false);
  expect(navigationBarDarkIcons(darkTheme.canvas)).toBe(false);
  render();
  expect(setAppearance).toHaveBeenLastCalledWith(lightTheme.canvas, true, lightTheme.canvas, true);
  mockTheme = darkTheme; update();
  expect(setAppearance).toHaveBeenLastCalledWith(darkTheme.canvas, false, darkTheme.canvas, false);
});

it('tracks accepted tab surfaces, keyboard hiding, and restores the default after unmount', () => {
  render(<Surface kind="personal" />);
  expect(setAppearance).toHaveBeenLastCalledWith(lightTheme.surface, true, lightTheme.surface, true);
  update(<Surface kind="workspace" />);
  expect(setAppearance).toHaveBeenLastCalledWith(lightTheme.brand, false, lightTheme.brand, false);
  update(<Surface kind="workspace" enabled={false} />);
  expect(setAppearance).toHaveBeenLastCalledWith(lightTheme.canvas, true, lightTheme.canvas, true);
  update(<Surface kind="workspace" />); update();
  expect(setAppearance).toHaveBeenLastCalledWith(lightTheme.canvas, true, lightTheme.canvas, true);
});

it('styles modal windows separately and restores the activity appearance after dismissal', () => {
  const node = (modal?: NavigationBarSurface) => <><Surface kind="workspace" />{modal ? <Surface kind={modal} /> : null}</>;
  render(node('sheet'));
  expect(setAppearance).toHaveBeenLastCalledWith(lightTheme.brand, false, lightTheme.surface, true);
  update(node('dialog'));
  const dimmed = dimNavigationBar(lightTheme.brand);
  expect(setAppearance).toHaveBeenLastCalledWith(lightTheme.brand, false, dimmed, false);
  update(node());
  expect(setAppearance).toHaveBeenLastCalledWith(lightTheme.brand, false, lightTheme.brand, false);
});

it('releases retained route styling on blur and reapplies it on focus', () => {
  let focused = true;
  const listeners = new Map<string, () => void>();
  const navigation = { isFocused: () => focused, addListener: (event: string, callback: () => void) => { listeners.set(event, callback); return () => listeners.delete(event); } };
  render(<NavigationContext.Provider value={navigation as never}><Surface kind="workspace" /></NavigationContext.Provider>);
  expect(setAppearance).toHaveBeenLastCalledWith(lightTheme.brand, false, lightTheme.brand, false);
  act(() => { focused = false; listeners.get('blur')!(); });
  expect(setAppearance).toHaveBeenLastCalledWith(lightTheme.canvas, true, lightTheme.canvas, true);
  act(() => { focused = true; listeners.get('focus')!(); });
  expect(setAppearance).toHaveBeenLastCalledWith(lightTheme.brand, false, lightTheme.brand, false);
});

it('does not call Android native styling on iOS', () => {
  jest.replaceProperty(Platform, 'OS', 'ios');
  render(<Surface kind="workspace" />);
  expect(setAppearance).not.toHaveBeenCalled();
});

it('allows an older native binary without the optional appearance bridge', () => {
  delete NativeModules.SystemNavigationBar;
  expect(() => render(<Surface kind="workspace" />)).not.toThrow();
});
