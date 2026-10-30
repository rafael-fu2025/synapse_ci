import React from 'react';
import { Image, Platform, SectionList, StyleSheet, Text } from 'react-native';
import { LogOut } from 'lucide-react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { launchImageLibrary } from 'react-native-image-picker';
import { useQueryClient } from '@tanstack/react-query';
import { useNavigation } from '@react-navigation/native';
import { ApiError, authorizedFetch, getAccessToken, request } from '../src/api/client';
import { removePhoto, uploadPhoto } from '../src/api/endpoints/health';
import type { Appointment } from '../src/api/endpoints/appointments';
import type { User } from '../src/api/types';
import { Action } from '../src/components/Action';
import { EmptyState } from '../src/components/EmptyState';
import { ProfilePhotoScreen } from '../src/features/profile/screens/ProfilePhotoScreen';
import { RecordsScreen } from '../src/features/profile/screens/RecordsScreen';
import { SettingsScreen } from '../src/features/profile/screens/SettingsScreen';
import { PrivacySecurityScreen } from '../src/features/profile/screens/PrivacySecurityScreen';
import { AppearanceScreen } from '../src/features/profile/screens/AppearanceScreen';
import { useAppointments } from '../src/features/appointments/hooks';
import { useAuth } from '../src/store/auth';
import { useTheme } from '../src/theme/ThemeProvider';
import { darkTheme, lightTheme } from '../src/theme/tokens';
import { version } from '../package.json';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'https://api.test' } }));
jest.mock('../src/api/client', () => ({
  ...jest.requireActual('../src/api/client'),
  authorizedFetch: jest.fn(), getAccessToken: jest.fn(), request: jest.fn(),
}));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/api/endpoints/health', () => ({
  ...jest.requireActual('../src/api/endpoints/health'),
  uploadPhoto: jest.fn(jest.requireActual('../src/api/endpoints/health').uploadPhoto),
  removePhoto: jest.fn(),
}));
jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: jest.fn() }));
jest.mock('../src/features/appointments/hooks', () => ({ useAppointments: jest.fn() }));
jest.mock('@tanstack/react-query', () => ({ useQueryClient: jest.fn() }));
jest.mock('@react-navigation/native', () => ({
  useNavigation: jest.fn(),
  useFocusEffect: (effect: () => (() => void)) => jest.requireActual('react').useEffect(effect, [effect]),
}));
jest.mock('react-native-image-picker', () => ({ launchImageLibrary: jest.fn() }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View }));
jest.mock('react-native-linear-gradient', () => jest.requireActual('react-native').View);
jest.mock('lucide-react-native', () => ({
  ArrowLeft: () => null, Camera: () => null, ImagePlus: () => null, Trash2: () => null,
  ChevronRight: () => null, FileText: () => null, ShieldCheck: () => null,
  Smartphone: () => null, Moon: () => null, LogOut: () => null, Database: () => null,
  Lock: () => null, KeyRound: () => null, Check: () => null, Monitor: () => null, Sun: () => null,
}));

let renderer: ReactTestRenderer | undefined;
const invalidateQueries = jest.fn().mockResolvedValue(undefined);
const signOut = jest.fn().mockResolvedValue(undefined);
const navigate = jest.fn();
const setPreference = jest.fn();
const refetch = jest.fn().mockResolvedValue(undefined);
const user: User = {
  id: 12, person_id: 20, tenant_id: 1, username: 'student', email: null,
  name: 'Student', avatar_initials: 'ST', identifier: '2026-001', role: 'student',
  is_teaching: false, permissions: [],
};

function render(element: React.ReactElement) {
  act(() => { renderer = create(element); });
}
function hasText(text: string) {
  return renderer!.root.findAllByType(Text).some(node => {
    const children = Array.isArray(node.props.children) ? node.props.children : [node.props.children];
    return children.filter((child: unknown) => typeof child === 'string' || typeof child === 'number').join('') === text;
  });
}
function button(label: string) {
  return renderer!.root.findAll(node => node.props.accessibilityLabel === label && typeof node.props.onPress === 'function')[0];
}
function image() {
  return renderer!.root.findByType(Image);
}
function pickerAsset(type = 'image/jpeg', fileSize = 1024) {
  return { uri: 'file:///private/photo', type, fileSize, fileName: 'untrusted.heic' };
}
function appointments(data: Appointment[] = [], options = {}) {
  jest.mocked(useAppointments).mockReturnValue({
    data: { data }, isLoading: false, isError: false, isFetching: false, refetch, ...options,
  } as unknown as ReturnType<typeof useAppointments>);
}
function appointment(id: number, date: string, status: Appointment['status'] = 'completed'): Appointment {
  return {
    id, date, status, unit: id % 2 ? 'clinic' : 'guidance', type: 'follow_up',
    starts_at: `${date}T09:00:00+08:00`, ends_at: `${date}T10:00:00+08:00`,
    reason: null, cancellation_reason: null, provider_name: null, version: 1,
  };
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.mocked(getAccessToken).mockReturnValue('private-access-token');
  jest.mocked(authorizedFetch).mockResolvedValue({ ok: true } as Response);
  jest.mocked(request).mockResolvedValue({});
  jest.mocked(removePhoto).mockResolvedValue(undefined);
  jest.mocked(launchImageLibrary).mockResolvedValue({ assets: [pickerAsset()] });
  jest.mocked(useAuth).mockReturnValue({
    status: 'signedIn', user, signIn: jest.fn(), signOut, acknowledgeExpired: jest.fn(),
  });
  jest.mocked(useTheme).mockReturnValue({ theme: lightTheme, mode: 'light', preference: 'system', setPreference });
  jest.mocked(useQueryClient).mockReturnValue({ invalidateQueries } as unknown as ReturnType<typeof useQueryClient>);
  jest.mocked(useNavigation).mockReturnValue({ navigate, goBack: jest.fn() } as unknown as ReturnType<typeof useNavigation>);
  appointments();
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  jest.restoreAllMocks();
});

describe('ProfilePhotoScreen', () => {
  it.each([
    ['image/jpeg', 'jpg'], ['image/png', 'png'], ['image/webp', 'webp'],
  ])('uploads %s through the shared helper with truthful multipart metadata and invalidates me', async (type, extension) => {
    jest.mocked(launchImageLibrary).mockResolvedValueOnce({ assets: [pickerAsset(type, 10 * 1024 * 1024)] });
    const append = jest.spyOn(FormData.prototype, 'append');
    render(<ProfilePhotoScreen />);
    const previousUri = image().props.source.uri;
    await act(async () => button('Choose from library').props.onPress());
    expect(uploadPhoto).toHaveBeenCalledTimes(1);
    expect(uploadPhoto).toHaveBeenCalledWith({ uri: 'file:///private/photo', name: `photo.${extension}`, type });
    expect(authorizedFetch).toHaveBeenCalledTimes(1);
    const [path, init] = jest.mocked(authorizedFetch).mock.calls[0];
    expect(path).toBe('api/v1/me/photo');
    expect(init.method).toBe('POST');
    expect(append).toHaveBeenCalledWith('photo', {
      uri: 'file:///private/photo', name: `photo.${extension}`, type,
    });
    expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['me'] });
    expect(image().props.source.uri).not.toBe(previousUri);
    expect(button('Choose from library').props.disabled).toBe(false);
  });

  it.each([
    [{ assets: [pickerAsset('image/heic')] }, 'Choose a JPEG, PNG, or WebP image.'],
    [{ assets: [pickerAsset('image/gif')] }, 'Choose a JPEG, PNG, or WebP image.'],
    [{ assets: [{ ...pickerAsset(), type: undefined }] }, 'Choose a JPEG, PNG, or WebP image.'],
    [{ assets: [pickerAsset('image/png', 10 * 1024 * 1024 + 1)] }, 'Choose a photo no larger than 10 MB.'],
    [{ assets: [{ ...pickerAsset(), fileSize: undefined }] }, 'The photo size could not be verified. Please choose another photo.'],
    [{ assets: [pickerAsset('image/jpeg', 0)] }, 'The photo size could not be verified. Please choose another photo.'],
    [{ assets: [pickerAsset('image/jpeg', NaN)] }, 'The photo size could not be verified. Please choose another photo.'],
    [{}, 'No photo was selected. Please choose a photo again.'],
    [{ errorCode: 'permission' as const }, 'Allow photo library access in your device settings, then try again.'],
    [{ errorCode: 'others' as const, errorMessage: 'private diagnostic' }, 'The photo library could not be opened. Try again.'],
  ])('rejects invalid selection %# before network I/O', async (result, message) => {
    jest.mocked(launchImageLibrary).mockResolvedValueOnce(result);
    render(<ProfilePhotoScreen />);
    await act(async () => button('Choose from library').props.onPress());
    expect(hasText(message)).toBe(true);
    expect(hasText('private diagnostic')).toBe(false);
    expect(uploadPhoto).not.toHaveBeenCalled();
    expect(authorizedFetch).not.toHaveBeenCalled();
    expect(invalidateQueries).not.toHaveBeenCalled();
    expect(button('Remove photo').props.disabled).toBe(false);
  });

  it('treats cancellation as a no-op and releases the mutation guard', async () => {
    jest.mocked(launchImageLibrary).mockResolvedValueOnce({ didCancel: true, assets: [pickerAsset()] });
    render(<ProfilePhotoScreen />);
    await act(async () => button('Choose from library').props.onPress());
    expect(renderer!.root.findAllByProps({ accessibilityRole: 'alert' })).toHaveLength(0);
    expect(uploadPhoto).not.toHaveBeenCalled();
    expect(authorizedFetch).not.toHaveBeenCalled();
    await act(async () => button('Choose from library').props.onPress());
    expect(authorizedFetch).toHaveBeenCalledTimes(1);
  });

  it('catches a rejected picker promise without leaking its diagnostic and allows retry', async () => {
    jest.mocked(launchImageLibrary).mockRejectedValueOnce(new Error('private-access-token'));
    render(<ProfilePhotoScreen />);
    await act(async () => button('Choose from library').props.onPress());
    expect(hasText('The photo could not be selected or uploaded. Try again.')).toBe(true);
    expect(hasText('private-access-token')).toBe(false);
    expect(authorizedFetch).not.toHaveBeenCalled();
    await act(async () => button('Choose from library').props.onPress());
    expect(authorizedFetch).toHaveBeenCalledTimes(1);
  });

  it('blocks same-tick picker and remove duplicates while the picker is open', async () => {
    let resolvePicker!: (result: { didCancel: boolean }) => void;
    jest.mocked(launchImageLibrary).mockImplementationOnce(() => new Promise(resolve => { resolvePicker = resolve; }));
    render(<ProfilePhotoScreen />);
    const upload = button('Choose from library').props.onPress;
    const remove = button('Remove photo').props.onPress;
    let pending!: Promise<void>;
    act(() => { pending = upload(); upload(); remove(); });
    expect(launchImageLibrary).toHaveBeenCalledTimes(1);
    expect(removePhoto).not.toHaveBeenCalled();
    expect(button('Choose from library').props.accessibilityState).toEqual({ disabled: true, busy: true });
    expect(button('Remove photo').props.disabled).toBe(true);
    await act(async () => { resolvePicker({ didCancel: true }); await pending; });
    await act(async () => remove());
    expect(removePhoto).toHaveBeenCalledTimes(1);
    expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['me'] });
    expect(renderer!.root.findAllByType(Image)).toHaveLength(0);
    expect(hasText('ST')).toBe(true);
  });

  it.each(['owner change', 'unmount'])('does not upload a late picker selection after %s', async scenario => {
    let resolvePicker!: (result: { assets: ReturnType<typeof pickerAsset>[] }) => void;
    jest.mocked(launchImageLibrary).mockImplementationOnce(() => new Promise(resolve => { resolvePicker = resolve; }));
    render(<ProfilePhotoScreen />);
    let pending!: Promise<void>;
    act(() => { pending = button('Choose from library').props.onPress(); });
    if (scenario === 'unmount') {
      act(() => renderer!.unmount());
      renderer = undefined;
    } else {
      jest.mocked(useAuth).mockReturnValue({ ...useAuth(), user: { ...user, id: 99 } });
      act(() => renderer!.update(<ProfilePhotoScreen />));
    }
    await act(async () => { resolvePicker({ assets: [pickerAsset()] }); await pending; });
    expect(uploadPhoto).not.toHaveBeenCalled();
    expect(authorizedFetch).not.toHaveBeenCalled();
    expect(invalidateQueries).not.toHaveBeenCalled();
  });

  it('blocks duplicate removes and uploads during removal', async () => {
    let resolveRemove!: () => void;
    jest.mocked(removePhoto).mockImplementationOnce(() => new Promise(resolve => { resolveRemove = resolve; }));
    render(<ProfilePhotoScreen />);
    const remove = button('Remove photo').props.onPress;
    const upload = button('Choose from library').props.onPress;
    let pending!: Promise<void>;
    act(() => { pending = remove(); remove(); upload(); });
    expect(removePhoto).toHaveBeenCalledTimes(1);
    expect(launchImageLibrary).not.toHaveBeenCalled();
    await act(async () => { resolveRemove(); await pending; });
    expect(button('Remove photo').props.disabled).toBe(false);
  });

  it('keeps upload and removal failures separate from successful mutations', async () => {
    jest.mocked(authorizedFetch).mockResolvedValueOnce({
      ok: false, status: 422, json: async () => ({ error: { code: 'validation_failed', message: 'Image is too large to process.' } }),
    } as Response);
    render(<ProfilePhotoScreen />);
    await act(async () => button('Choose from library').props.onPress());
    expect(hasText('Image is too large to process.')).toBe(true);
    jest.mocked(removePhoto).mockRejectedValueOnce(new ApiError(503, { code: 'unavailable', message: 'Try removing it later.' }));
    await act(async () => button('Remove photo').props.onPress());
    expect(hasText('Try removing it later.')).toBe(true);
    expect(invalidateQueries).not.toHaveBeenCalled();
    expect(renderer!.root.findAllByType(Image)).toHaveLength(1);
  });

  it('isolates native disk cache URLs by owner and session without credentials in the URL', () => {
    render(<ProfilePhotoScreen />);
    const source = image().props.source;
    expect(source.uri).toContain('owner=1-12');
    expect(source.uri).toContain('session=');
    expect(source.uri).not.toContain('private-access-token');
    expect(source.headers.Authorization).toBe('Bearer private-access-token');
    expect(source.cache).toBe('reload');
    act(() => renderer!.unmount());
    render(<ProfilePhotoScreen />);
    const secondUri = image().props.source.uri;
    expect(secondUri).not.toBe(source.uri);
    // A fresh login as the same user must also have a different namespace.
    jest.mocked(useAuth).mockReturnValue({ ...useAuth(), user: { ...user } });
    act(() => renderer!.update(<ProfilePhotoScreen />));
    expect(image().props.source.uri).not.toBe(secondUri);
    jest.mocked(useAuth).mockReturnValue({ ...useAuth(), user: { ...user, id: 99 } });
    act(() => renderer!.update(<ProfilePhotoScreen />));
    expect(image().props.source.uri).toContain('owner=1-99');
    expect(image().props.source.uri).not.toBe(secondUri);
  });

  it('recovers stale native image bearer headers through the authenticated refresh path', async () => {
    render(<ProfilePhotoScreen />);
    const previousUri = image().props.source.uri;
    jest.mocked(request).mockImplementationOnce(async () => {
      jest.mocked(getAccessToken).mockReturnValue('rotated-token');
      return {};
    });
    await act(async () => image().props.onError());
    expect(request).toHaveBeenCalledWith('api/v1/me');
    expect(image().props.source.headers.Authorization).toBe('Bearer rotated-token');
    expect(image().props.source.uri).not.toBe(previousUri);
    expect(image().props.source.uri).not.toContain('rotated-token');
    await act(async () => image().props.onError());
    expect(renderer!.root.findAllByType(Image)).toHaveLength(0);
    expect(hasText('ST')).toBe(true);
  });

  it('does not render private images without an access token', () => {
    jest.mocked(getAccessToken).mockReturnValue(null);
    render(<ProfilePhotoScreen />);
    expect(renderer!.root.findAllByType(Image)).toHaveLength(0);
    expect(hasText('ST')).toBe(true);
  });
});

describe('RecordsScreen', () => {
  it('distinguishes a real loading failure from empty data and offers retry', async () => {
    appointments([], { isError: true });
    render(<RecordsScreen />);
    expect(renderer!.root.findByType(EmptyState).props.title).toBe('Could not load appointment history');
    await act(async () => renderer!.root.findByType(Action).props.onPress());
    expect(refetch).toHaveBeenCalledTimes(1);
    appointments();
    act(() => renderer!.update(<RecordsScreen />));
    expect(renderer!.root.findByType(EmptyState).props.title).toBe('No completed appointments yet');
  });

  it('groups completed appointments in memoized virtualized sections, not clinical summaries', () => {
    const data = [
      appointment(1, '2026-10-14'), appointment(2, '2026-10-10'), appointment(3, '2026-09-05'),
      appointment(4, '2026-10-20', 'requested'), appointment(5, '2026-10-21', 'scheduled'),
      appointment(6, '2026-10-22', 'cancelled'),
    ];
    appointments(data);
    render(<RecordsScreen />);
    expect(useAppointments).toHaveBeenCalledWith({ service: 'all', status: 'completed', sort: 'date_desc' });
    const sections = renderer!.root.findByType(SectionList).props.sections;
    expect(sections).toEqual([
      { month: '2026-10', data: data.slice(0, 2) }, { month: '2026-09', data: [data[2]] },
    ]);
    expect(hasText('Completed appointment history')).toBe(true);
    expect(hasText('Clinic appointment')).toBe(true);
    expect(hasText('Guidance appointment')).toBe(true);
    expect(hasText('Clinic visit')).toBe(false);
    expect(hasText('OCTOBER 2026')).toBe(true);
    expect(hasText('OCT')).toBe(true);
    const buttons = renderer!.root.findAll(node => node.props.accessibilityRole === 'button' && typeof node.props.onPress === 'function');
    expect(new Set(buttons.map(node => node.props.accessibilityLabel))).toEqual(new Set(['Go back']));
    act(() => renderer!.update(<RecordsScreen />));
    expect(renderer!.root.findByType(SectionList).props.sections).toBe(sections);
  });

  it('does not show requests as completed records even if the server returns them', () => {
    appointments([appointment(1, '2026-10-20', 'requested')]);
    render(<RecordsScreen />);
    expect(renderer!.root.findByType(EmptyState).props.title).toBe('No completed appointments yet');
  });
});

describe('SettingsScreen and PrivacySecurityScreen', () => {
  it.each([lightTheme, darkTheme])('uses a solid maroon sign-out button with white content in $mode mode', theme => {
    jest.mocked(useTheme).mockReturnValue({ theme, mode: theme.mode, preference: 'system', setPreference });
    render(<SettingsScreen />);
    const signOutButton = button('Sign out');
    expect(StyleSheet.flatten(signOutButton.props.style).backgroundColor).toBe('#800000');
    const label = signOutButton.findAllByType(Text).find(node => node.props.children === 'Sign out')!;
    expect(StyleSheet.flatten(label.props.style).color).toBe('#ffffff');
    expect(signOutButton.findByType(LogOut).props.color).toBe('#ffffff');
  });
  it('uses the actual package version and device OS, preserves supported routes and sign-out', async () => {
    jest.replaceProperty(Platform, 'OS', 'ios');
    render(<SettingsScreen />);
    expect(hasText(`SYNAPSE Mobile · v${version}`)).toBe(true);
    expect(hasText('iOS · refresh token secured on this device')).toBe(true);
    act(() => button('Appearance').props.onPress());
    act(() => button('Privacy & security').props.onPress());
    expect(navigate.mock.calls).toEqual([['Appearance'], ['PrivacySecurity']]);
    expect(hasText('Notifications')).toBe(false);
    expect(hasText('Help & support')).toBe(false);
    await act(async () => button('Sign out').props.onPress());
    expect(signOut).toHaveBeenCalledTimes(1);
  });

  it('shows truthful session facts and a working device sign-out instead of unsupported controls', async () => {
    jest.replaceProperty(Platform, 'OS', 'android');
    render(<PrivacySecurityScreen />);
    expect(hasText('Protected session')).toBe(true);
    expect(hasText('Private records')).toBe(true);
    expect(hasText('Current session')).toBe(true);
    expect(hasText('Android · 2026-001')).toBe(true);
    expect(hasText('Read full privacy notice')).toBe(false);
    expect(hasText('Your refresh token is kept in your device\'s Keychain or Keystore. Your access token is held in memory.')).toBe(true);
    await act(async () => renderer!.root.findByType(Action).props.onPress());
    expect(signOut).toHaveBeenCalledTimes(1);
  });
});

describe('AppearanceScreen', () => {
  it('previews the resolved system theme and exposes working accessible radio choices', () => {
    jest.mocked(useTheme).mockReturnValue({ theme: darkTheme, mode: 'dark', preference: 'system', setPreference });
    render(<AppearanceScreen />);
    expect(hasText('Saved on this device')).toBe(true);
    expect(hasText('Dark mode · device setting')).toBe(true);
    expect(button('Match system').props.accessibilityState).toEqual({ checked: true });
    expect(button('Dark').props.accessibilityState).toEqual({ checked: false });
    act(() => button('Light').props.onPress());
    act(() => button('Dark').props.onPress());
    act(() => button('Match system').props.onPress());
    expect(setPreference.mock.calls).toEqual([['light'], ['dark'], ['system']]);
    const darkLogo = image().props.source;
    jest.mocked(useTheme).mockReturnValue({ theme: lightTheme, mode: 'light', preference: 'light', setPreference });
    act(() => renderer!.update(<AppearanceScreen />));
    expect(hasText('Light mode')).toBe(true);
    expect(button('Light').props.accessibilityState).toEqual({ checked: true });
    expect(image().props.source).not.toEqual(darkLogo);
    const preview = image().parent!;
    expect(StyleSheet.flatten(preview.props.style).backgroundColor).toBe(lightTheme.surface);
  });
});
