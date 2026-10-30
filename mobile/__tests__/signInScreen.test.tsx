import React from 'react';
import { ActivityIndicator, StyleSheet, Text, TextInput } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { ApiError, NetworkError } from '../src/api/client';
import { Action } from '../src/components/Action';
import { SignInScreen } from '../src/features/auth/screens/SignInScreen';
import { useSignInCooldown } from '../src/features/auth/useSignInCooldown';
import { useAuth } from '../src/store/auth';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('../src/theme/ThemeProvider', () => ({
  useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }),
}));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View }));
jest.mock('lucide-react-native', () => ({ Eye: () => null, EyeOff: () => null, ShieldCheck: () => null, TriangleAlert: () => null }));
jest.mock('../src/features/auth/components/LoginCarousel', () => ({ LoginCarousel: () => null }));

let renderer: ReactTestRenderer | undefined;
let cooldown: ReturnType<typeof useSignInCooldown>;
const signIn = jest.fn<Promise<void>, [string, string]>();

function CooldownHarness() {
  cooldown = useSignInCooldown();
  return null;
}

function render(element: React.ReactElement) {
  act(() => {
    renderer = create(element);
  });
}

function renderSignIn() {
  render(<SignInScreen />);
  act(() => {
    const fields = renderer!.root.findAllByType(TextInput);
    fields[0].props.onChangeText(' student@university.edu ');
    fields[1].props.onChangeText('password');
  });
}

function action() {
  return renderer!.root.findByType(Action);
}

function hasText(text: string) {
  return renderer!.root.findAllByType(Text).some(node => node.props.children === text);
}

function rateLimit(retryAfterSeconds: number | null) {
  return Object.assign(new ApiError(429, {
    code: 'rate_limited',
    message: 'Too many requests.',
    fields: { identifier: ['Server rate limit'] },
  }), { retryAfterSeconds });
}

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['queueMicrotask'] });
  jest.setSystemTime(new Date('2026-01-01T00:00:00Z'));
  signIn.mockReset().mockResolvedValue(undefined);
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
  jest.useRealTimers();
});

describe('useSignInCooldown', () => {
  it.each([
    [null, 300],
    [2.5, 3],
    [0, 0],
    [-1, 300],
    [NaN, 300],
    [Infinity, 300],
  ])('uses a safe wait for Retry-After %s', (seconds, expected) => {
    render(<CooldownHarness />);
    act(() => {
      cooldown.startCooldown(seconds);
      expect(cooldown.isCoolingDown()).toBe(expected > 0);
    });
    expect(cooldown.remainingSeconds).toBe(expected);
    act(() => jest.advanceTimersByTime(expected * 1000));
    expect(cooldown.remainingSeconds).toBe(0);
    expect(cooldown.isCoolingDown()).toBe(false);
    expect(jest.getTimerCount()).toBe(0);
    expect(signIn).not.toHaveBeenCalled();
  });

  it('derives remaining time from the deadline after forward and backward clock jumps', () => {
    render(<CooldownHarness />);
    act(() => cooldown.startCooldown(10));
    act(() => jest.advanceTimersByTime(1000));
    expect(cooldown.remainingSeconds).toBe(9);

    jest.setSystemTime(Date.now() + 5000);
    act(() => jest.advanceTimersByTime(1000));
    expect(cooldown.remainingSeconds).toBe(3);

    jest.setSystemTime(Date.now() - 4000);
    act(() => jest.advanceTimersByTime(1000));
    expect(cooldown.remainingSeconds).toBe(6);

    jest.setSystemTime(Date.now() + 10000);
    expect(cooldown.isCoolingDown()).toBe(false);
    act(() => jest.runOnlyPendingTimers());
    expect(cooldown.remainingSeconds).toBe(0);
    expect(jest.getTimerCount()).toBe(0);
    expect(signIn).not.toHaveBeenCalled();
  });

  it('cleans up its timer on unmount', () => {
    render(<CooldownHarness />);
    act(() => cooldown.startCooldown(300));
    expect(jest.getTimerCount()).toBe(1);
    act(() => renderer!.unmount());
    renderer = undefined;
    expect(jest.getTimerCount()).toBe(0);
    act(() => jest.advanceTimersByTime(300000));
    expect(signIn).not.toHaveBeenCalled();
  });
});

describe('SignInScreen', () => {
  it('labels the login field as ID number without mentioning email', () => {
    render(<SignInScreen />);
    expect(hasText('ID number')).toBe(true);
    expect(hasText('ID number or email')).toBe(false);
    expect(renderer!.root.findAllByType(TextInput)[0].props.placeholder).toBe('Enter your ID number');
  });
  it('blocks same-tick duplicate submits and releases the guard after success', async () => {
    let resolveSignIn!: () => void;
    signIn.mockImplementationOnce(() => new Promise<void>(resolve => {
      resolveSignIn = resolve;
    }));
    renderSignIn();
    const submit = action().props.onPress;
    act(() => {
      submit();
      submit();
    });
    expect(signIn).toHaveBeenCalledTimes(1);
    expect(signIn).toHaveBeenCalledWith('student@university.edu', 'password');
    expect(action().props.loading).toBe(true);
    expect(action().findByProps({ accessible: true }).props.accessibilityState).toMatchObject({ disabled: true, busy: true });

    await act(async () => resolveSignIn());
    expect(action().props.loading).toBe(false);
    await act(async () => action().props.onPress());
    expect(signIn).toHaveBeenCalledTimes(2);
  });

  it('keeps the 429 countdown across identifier edits and only retries on a new tap after expiry', async () => {
    signIn.mockRejectedValueOnce(rateLimit(2));
    renderSignIn();
    const submit = action().props.onPress;
    await act(async () => {
      await submit();
      // Even an old handler must see the cooldown before React commits disabled.
      await submit();
    });
    expect(signIn).toHaveBeenCalledTimes(1);
    expect(hasText('Too many sign-in attempts. Please try again in 0:02.')).toBe(true);
    expect(hasText('Server rate limit')).toBe(false);
    expect(action().props.disabled).toBe(true);
    expect(action().findByProps({ accessible: true }).props.accessibilityState).toMatchObject({ disabled: true, busy: false });

    act(() => renderer!.root.findAllByType(TextInput)[0].props.onChangeText('other@university.edu'));
    await act(async () => action().props.onPress());
    expect(signIn).toHaveBeenCalledTimes(1);
    act(() => jest.advanceTimersByTime(1000));
    expect(hasText('Too many sign-in attempts. Please try again in 0:01.')).toBe(true);
    act(() => jest.advanceTimersByTime(1000));
    expect(action().props.disabled).toBe(false);
    expect(jest.getTimerCount()).toBe(0);
    expect(signIn).toHaveBeenCalledTimes(1);

    await act(async () => action().props.onPress());
    expect(signIn).toHaveBeenCalledTimes(2);
    expect(signIn).toHaveBeenLastCalledWith('other@university.edu', 'password');
  });

  it('uses a five-minute fallback without Retry-After and never authenticates automatically', async () => {
    signIn.mockRejectedValueOnce(rateLimit(null));
    renderSignIn();
    await act(async () => action().props.onPress());
    expect(hasText('Too many sign-in attempts. Please try again in 5:00.')).toBe(true);
    act(() => jest.advanceTimersByTime(299000));
    expect(action().props.disabled).toBe(true);
    expect(hasText('Too many sign-in attempts. Please try again in 0:01.')).toBe(true);
    act(() => jest.advanceTimersByTime(1000));
    expect(action().props.disabled).toBe(false);
    expect(signIn).toHaveBeenCalledTimes(1);
  });

  it.each([
    [new ApiError(401, { code: 'invalid_credentials', message: 'Incorrect password.' }), 'Incorrect password.'],
    [new ApiError(422, { code: 'validation_failed', message: 'Review fields.', fields: { identifier: ['Invalid ID.'] } }), 'Invalid ID.'],
    [new ApiError(503, { code: 'unavailable', message: 'Service is unavailable.' }), 'Service is unavailable.'],
    [new NetworkError('Cannot reach the server.'), 'Cannot reach the server.'],
    [new Error('unexpected'), 'Sign-in is unavailable right now. Please try again.'],
  ])('preserves error semantics and releases the guard (case %#)', async (error, message) => {
    signIn.mockRejectedValueOnce(error);
    renderSignIn();
    await act(async () => action().props.onPress());
    expect(hasText(message)).toBe(true);
    expect(action().props.disabled).toBe(false);
    expect(action().props.loading).toBe(false);
    expect(jest.getTimerCount()).toBe(0);
    await act(async () => action().props.onPress());
    expect(signIn).toHaveBeenCalledTimes(2);
  });

  it('does not leave a timer behind when a pending request gets a 429 after unmount', async () => {
    let rejectSignIn!: (error: Error) => void;
    signIn.mockImplementationOnce(() => new Promise<void>((_, reject) => {
      rejectSignIn = reject;
    }));
    renderSignIn();
    act(() => { action().props.onPress(); });
    act(() => renderer!.unmount());
    renderer = undefined;
    await act(async () => rejectSignIn(rateLimit(300)));
    expect(jest.getTimerCount()).toBe(0);
    expect(signIn).toHaveBeenCalledTimes(1);
  });
});

describe('Action', () => {
  it('preserves default visuals and exposes disabled/busy states', () => {
    const onPress = jest.fn();
    render(<Action onPress={onPress}>Sign in</Action>);
    const button = () => renderer!.root.findByProps({ accessible: true });
    const style = () => StyleSheet.flatten(button().props.style);
    expect(button().props.accessibilityState).toMatchObject({ disabled: false, busy: false });
    expect(style().opacity).toBe(1);

    act(() => renderer!.update(<Action onPress={onPress} disabled>Sign in</Action>));
    expect(button().props.accessibilityState).toMatchObject({ disabled: true, busy: false });
    expect(style().opacity).toBe(0.5);

    act(() => renderer!.update(<Action onPress={onPress} loading>Sign in</Action>));
    expect(button().props.accessibilityState).toMatchObject({ disabled: true, busy: true });
    expect(style().opacity).toBe(0.75);
    expect(renderer!.root.findAllByType(ActivityIndicator)).toHaveLength(1);

    act(() => renderer!.update(<Action>Sign in</Action>));
    expect(button().props.accessibilityState).toMatchObject({ disabled: true, busy: false });
    expect(onPress).not.toHaveBeenCalled();
  });
});
