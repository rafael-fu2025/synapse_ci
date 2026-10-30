import React from 'react';
import { Keyboard, KeyboardEvent, Linking, Platform, ScrollView, StyleSheet, Text, TextInput } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { ApiError } from '../src/api/client';
import { Action } from '../src/components/Action';
import { TextField } from '../src/components/TextField';
import { LoginCarousel } from '../src/features/auth/components/LoginCarousel';
import { SignInScreen } from '../src/features/auth/screens/SignInScreen';
import { useAuth } from '../src/store/auth';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('../src/features/auth/components/LoginCarousel', () => ({ LoginCarousel: jest.fn(() => null) }));
jest.mock('../src/theme/ThemeProvider', () => ({
  useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }),
}));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View }));
jest.mock('react-native/Libraries/Components/Keyboard/KeyboardAvoidingView', () => ({
  __esModule: true,
  default: jest.requireActual('react-native').View,
}));
jest.mock('lucide-react-native', () => ({
  Eye: () => null,
  EyeOff: () => null,
  ShieldCheck: () => null,
  TriangleAlert: () => null,
}));

const destinations = [
  ['Guidance Center on Facebook', 'https://www.facebook.com/profile.php?id=100063778576372'],
  ['Foundation University on Facebook', 'https://www.facebook.com/foundationu.edu'],
  ['Health Services on Facebook', 'https://www.facebook.com/profile.php?id=100057107094031'],
] as const;
const signIn = jest.fn<Promise<void>, [string, string]>();
const focusPassword = jest.fn();
const keyboardListeners = new Map<string, { emit: () => void; remove: jest.Mock }>();
let renderer: ReactTestRenderer | undefined;

function renderSignIn() {
  act(() => {
    renderer = create(<SignInScreen />);
  });
}

function fields() {
  return renderer!.root.findAllByType(TextInput);
}

function fillCredentials() {
  act(() => {
    fields()[0].props.onChangeText(' student@foundationu.com ');
    fields()[1].props.onChangeText(' private password ');
  });
}

function eye(label = 'Show password') {
  return renderer!.root.findAllByProps({ accessibilityLabel: label }, { deep: false })[0];
}

function social(label: string) {
  return renderer!.root.findAllByProps({ accessibilityLabel: label }, { deep: false })[0];
}

function carouselRow() {
  return renderer!.root.findByProps({ testID: 'login-carousel-row' });
}

function hasText(text: string) {
  return renderer!.root.findAllByType(Text).some(node => node.props.children === text);
}

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  keyboardListeners.clear();
  focusPassword.mockClear();
  signIn.mockReset().mockResolvedValue(undefined);
  jest.mocked(LoginCarousel).mockClear();
  jest.replaceProperty(Platform, 'OS', 'android');
  jest.spyOn(Keyboard, 'isVisible').mockReturnValue(false);
  jest.spyOn(Keyboard, 'addListener').mockImplementation((event, listener) => {
    const subscription = { emit: () => listener({} as KeyboardEvent), remove: jest.fn() };
    keyboardListeners.set(event, subscription);
    return subscription;
  });
  jest.spyOn(Linking, 'openURL').mockReset().mockResolvedValue(undefined);
  jest.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('Unexpected network request'));
  jest.mocked(useAuth).mockReturnValue({
    status: 'signedOut',
    user: null,
    signIn,
    signOut: jest.fn(),
    acknowledgeExpired: jest.fn(),
  });
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  expect(globalThis.fetch).not.toHaveBeenCalled();
  jest.restoreAllMocks();
  jest.useRealTimers();
});

describe('cardless sign-in layout and keyboard lifecycle', () => {
  it('reserves 30% for the carousel independently of native ScrollView flex defaults', () => {
    renderSignIn();
    const scroll = renderer!.root.findByType(ScrollView);
    const carouselLayout = StyleSheet.flatten(carouselRow().props.style);
    const formLayout = StyleSheet.flatten([
      // Native ScrollView composes these defaults before the caller's style.
      { flexGrow: 1, flexShrink: 1 },
      scroll.props.style,
    ]);
    expect(carouselLayout).toMatchObject({ height: '30%', flexGrow: 0, flexShrink: 0 });
    expect(formLayout).toMatchObject({ flexBasis: 0, flexGrow: 1, flexShrink: 1, minHeight: 0 });
    expect(carouselLayout.flex).toBeUndefined();
    expect(formLayout.flex).toBeUndefined();
    expect(carouselRow().parent).toBe(scroll.parent);
    expect(scroll.props.keyboardShouldPersistTaps).toBe('handled');
    expect(scroll.props.keyboardDismissMode).toBe('on-drag');

    const form = renderer!.root.findByProps({ testID: 'login-form' });
    for (const style of [form.props.style, scroll.props.style, scroll.props.contentContainerStyle]) {
      const flat = StyleSheet.flatten(style);
      expect(flat.borderWidth ?? 0).toBe(0);
      expect(flat.elevation ?? 0).toBe(0);
      expect(flat.shadowOpacity ?? 0).toBe(0);
      expect(flat.shadowRadius ?? 0).toBe(0);
      expect(flat.boxShadow).toBeUndefined();
    }
    expect(renderer!.root.findByType(LoginCarousel).props.active).toBe(true);
  });

  it.each(['android', 'ios'] as const)('hides and restores the carousel without losing credentials on %s', os => {
    jest.replaceProperty(Platform, 'OS', os);
    renderSignIn();
    fillCredentials();
    act(() => eye().props.onPress());
    const showEvent = os === 'ios' ? 'keyboardWillShow' : 'keyboardDidShow';
    expect([...keyboardListeners.keys()]).toEqual([showEvent, 'keyboardDidHide']);

    for (let cycle = 0; cycle < 2; cycle += 1) {
      act(() => keyboardListeners.get(showEvent)!.emit());
      expect(StyleSheet.flatten(carouselRow().props.style).display).toBe('none');
      expect(carouselRow().props.accessibilityElementsHidden).toBe(true);
      expect(carouselRow().props.importantForAccessibility).toBe('no-hide-descendants');
      expect(renderer!.root.findByType(LoginCarousel).props.active).toBe(false);
      expect(StyleSheet.flatten(renderer!.root.findByType(ScrollView).props.contentContainerStyle).justifyContent).toBe('flex-start');

      act(() => keyboardListeners.get('keyboardDidHide')!.emit());
      expect(StyleSheet.flatten(carouselRow().props.style).display).toBeUndefined();
      expect(carouselRow().props.accessibilityElementsHidden).toBe(false);
      expect(carouselRow().props.importantForAccessibility).toBe('auto');
      expect(renderer!.root.findByType(LoginCarousel).props.active).toBe(true);
      expect(StyleSheet.flatten(renderer!.root.findByType(ScrollView).props.contentContainerStyle).justifyContent).toBe('center');
      expect(fields().map(node => node.props.value)).toEqual([' student@foundationu.com ', ' private password ']);
      expect(fields()[1].props.secureTextEntry).toBe(false);
    }
    expect(signIn).not.toHaveBeenCalled();
  });

  it('starts with a paused, hidden carousel if the keyboard is already visible', () => {
    jest.mocked(Keyboard.isVisible).mockReturnValue(true);
    renderSignIn();
    expect(StyleSheet.flatten(carouselRow().props.style).display).toBe('none');
    expect(renderer!.root.findByType(LoginCarousel).props.active).toBe(false);
    act(() => keyboardListeners.get('keyboardDidHide')!.emit());
    expect(renderer!.root.findByType(LoginCarousel).props.active).toBe(true);
  });

  it.each(['android', 'ios'] as const)('removes both keyboard subscriptions on %s unmount', os => {
    jest.replaceProperty(Platform, 'OS', os);
    renderSignIn();
    const subscriptions = [...keyboardListeners.values()];
    expect(subscriptions).toHaveLength(2);
    act(() => renderer!.unmount());
    renderer = undefined;
    subscriptions.forEach(subscription => expect(subscription.remove).toHaveBeenCalledTimes(1));
  });
});

describe('password visibility and submission', () => {
  it('toggles Show/Hide through the real TextField without changing either value', () => {
    renderSignIn();
    fillCredentials();
    expect(fields()[1].props.secureTextEntry).toBe(true);
    expect(eye().props.disabled).toBe(false);
    expect(eye().props.accessibilityState).toEqual({ disabled: false });
    expect(StyleSheet.flatten(fields()[1].props.style).paddingRight).toBeGreaterThanOrEqual(44);

    const inputRef = renderer!.root.findAllByType(TextField)[1].props.inputRef;
    expect(fields()[1].props.ref).toBe(inputRef);
    // The native preset does not attach an input instance to the forwarded ref.
    inputRef.current = { focus: focusPassword };
    act(() => fields()[0].props.onSubmitEditing());
    expect(focusPassword).toHaveBeenCalledTimes(1);
    act(() => eye().props.onPress());
    expect(fields()[1].props.secureTextEntry).toBe(false);
    expect(eye('Hide password').props.disabled).toBe(false);
    act(() => eye('Hide password').props.onPress());
    expect(fields()[1].props.secureTextEntry).toBe(true);
    expect(focusPassword).toHaveBeenCalledTimes(3);
    expect(fields().map(node => node.props.value)).toEqual([' student@foundationu.com ', ' private password ']);
    expect(signIn).not.toHaveBeenCalled();
  });

  it('submits once from the password keyboard and disables the eye and inputs while pending', async () => {
    let resolveSignIn!: () => void;
    signIn.mockImplementationOnce(() => new Promise<void>(resolve => { resolveSignIn = resolve; }));
    renderSignIn();
    fillCredentials();
    expect(fields()[1].props.returnKeyType).toBe('go');
    const submitFromKeyboard = fields()[1].props.onSubmitEditing;
    const submitFromButton = renderer!.root.findByType(Action).props.onPress;
    act(() => {
      submitFromKeyboard();
      submitFromKeyboard();
      submitFromButton();
    });
    expect(signIn).toHaveBeenCalledTimes(1);
    expect(signIn).toHaveBeenCalledWith('student@foundationu.com', ' private password ');
    expect(eye().props.disabled).toBe(true);
    expect(eye().props.accessibilityState).toEqual({ disabled: true });
    expect(fields().every(node => node.props.editable === false)).toBe(true);
    expect(fields()[1].props.secureTextEntry).toBe(true);
    expect(renderer!.root.findByType(Action).props.loading).toBe(true);

    await act(async () => resolveSignIn());
    expect(eye().props.disabled).toBe(false);
    expect(fields().every(node => node.props.editable === true)).toBe(true);
    expect(fields()[1].props.value).toBe(' private password ');
    expect(renderer!.root.findByType(Action).props.loading).toBe(false);
    await act(async () => fields()[1].props.onSubmitEditing());
    expect(signIn).toHaveBeenCalledTimes(2);
  });

  it('retains required-field guards for keyboard submission', async () => {
    renderSignIn();
    await act(async () => fields()[1].props.onSubmitEditing());
    expect(signIn).not.toHaveBeenCalled();
    expect(hasText('Enter your ID number or university email.')).toBe(true);
    expect(hasText('Enter your password.')).toBe(true);
  });

  it('does not bypass a 429 cooldown through the password keyboard or a visibility toggle', async () => {
    signIn.mockRejectedValueOnce(new ApiError(429, { code: 'rate_limited', message: 'Please wait.' }, 2));
    renderSignIn();
    fillCredentials();
    const originalSubmit = fields()[1].props.onSubmitEditing;
    await act(async () => originalSubmit());
    expect(hasText('Too many sign-in attempts. Please try again in 0:02.')).toBe(true);
    act(() => eye().props.onPress());
    await act(async () => {
      await originalSubmit();
      await fields()[1].props.onSubmitEditing();
    });
    expect(signIn).toHaveBeenCalledTimes(1);
    act(() => jest.advanceTimersByTime(2000));
    expect(signIn).toHaveBeenCalledTimes(1);
    expect(renderer!.root.findByType(Action).props.disabled).toBe(false);
    await act(async () => fields()[1].props.onSubmitEditing());
    expect(signIn).toHaveBeenCalledTimes(2);
  });
});

describe('public social destinations', () => {
  it.each(destinations)('opens the exact HTTPS destination for %s', async (label, url) => {
    renderSignIn();
    const link = social(label);
    expect(link.props.accessibilityRole).toBe('link');
    expect(url.startsWith('https://')).toBe(true);
    await act(async () => link.props.onPress());
    expect(Linking.openURL).toHaveBeenCalledTimes(1);
    expect(Linking.openURL).toHaveBeenCalledWith(url);
    expect(signIn).not.toHaveBeenCalled();
  });

  it.each(['reject', 'throw'] as const)('shows feedback rather than leaking a %s from Linking', async failure => {
    if (failure === 'reject') {
      jest.mocked(Linking.openURL).mockRejectedValueOnce(new Error('No URL handler'));
    } else {
      jest.mocked(Linking.openURL).mockImplementationOnce(() => { throw new Error('Native linking failure'); });
    }
    renderSignIn();
    fillCredentials();
    await act(async () => social(destinations[0][0]).props.onPress());
    const alert = renderer!.root.findAllByType(Text).find(node => node.props.accessibilityRole === 'alert');
    expect(alert?.props.children).toBe('Facebook could not be opened. Please try again.');
    expect(fields().map(node => node.props.value)).toEqual([' student@foundationu.com ', ' private password ']);
    await act(async () => social(destinations[1][0]).props.onPress());
    expect(hasText('Facebook could not be opened. Please try again.')).toBe(false);
    expect(Linking.openURL).toHaveBeenLastCalledWith(destinations[1][1]);
    expect(signIn).not.toHaveBeenCalled();
  });

  it('handles a link rejection after the screen has unmounted', async () => {
    let rejectLink!: (error: Error) => void;
    jest.mocked(Linking.openURL).mockImplementationOnce(() => new Promise((_, reject) => { rejectLink = reject; }));
    renderSignIn();
    act(() => social(destinations[0][0]).props.onPress());
    act(() => renderer!.unmount());
    renderer = undefined;
    await act(async () => rejectLink(new Error('Link failed after navigation')));
    expect(Linking.openURL).toHaveBeenCalledTimes(1);
    expect(signIn).not.toHaveBeenCalled();
  });
});
