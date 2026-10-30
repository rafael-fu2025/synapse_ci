import React from 'react';
import { Image, Text } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { ProfileHomeScreen } from '../src/features/profile/screens/ProfileHomeScreen';
import { useProfilePhoto } from '../src/features/profile/useProfilePhoto';

jest.mock('@tanstack/react-query', () => ({ useQuery: () => ({ data: { entitlements: { staff_queue_units: [] } } }) }));
jest.mock('@react-navigation/native', () => ({ useNavigation: () => ({ navigate: mockNavigate }) }));
jest.mock('../src/api/endpoints/me', () => ({ me: jest.fn() }));
jest.mock('../src/features/profile/hooks', () => ({ useHealthSummary: () => ({ isError: false }) }));
jest.mock('../src/features/profile/useProfilePhoto', () => ({ useProfilePhoto: jest.fn() }));
jest.mock('../src/store/auth', () => ({
  useAuth: () => ({ user: { id: 1, tenant_id: 1, name: 'Test Student', role: 'student', identifier: 'TEST-1', avatar_initials: 'TS' } }),
}));
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }) }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View }));
jest.mock('react-native-linear-gradient', () => jest.requireActual('react-native').View);
jest.mock('react-native-svg', () => ({ SvgXml: () => null }));
jest.mock('lucide-react-native', () => ({
  Building2: () => null, CalendarDays: () => null, Camera: () => null, ChevronRight: () => null,
  CircleCheck: () => null, FileHeart: () => null, HeartPulse: () => null, RefreshCw: () => null,
  Settings: () => null, IdCard: () => null, TriangleAlert: () => null, ArrowLeft: () => null,
}));

const mockNavigate = jest.fn();
let renderer: ReactTestRenderer | undefined;

beforeEach(() => {
  jest.clearAllMocks();
  jest.mocked(useProfilePhoto).mockReturnValue(null);
});
afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
});

it('shows an existing photo on Profile without opening the camera screen', () => {
  const uri = 'data:image/webp;base64,AQID';
  jest.mocked(useProfilePhoto).mockReturnValue(uri);
  act(() => { renderer = create(<ProfileHomeScreen />); });
  expect(renderer!.root.findByType(Image).props.source).toEqual({ uri });
  expect(mockNavigate).not.toHaveBeenCalled();
  const camera = renderer!.root.findAll(node => node.props.accessibilityLabel === 'Change profile photo' && typeof node.props.onPress === 'function')[0];
  act(() => camera.props.onPress());
  expect(mockNavigate).toHaveBeenCalledWith('ProfilePhoto');
});

it('switches from initials to an uploaded photo and back after removal', () => {
  act(() => { renderer = create(<ProfileHomeScreen />); });
  expect(renderer!.root.findAllByType(Image)).toHaveLength(0);
  expect(renderer!.root.findAllByType(Text).some(node => node.props.children === 'TS')).toBe(true);
  jest.mocked(useProfilePhoto).mockReturnValue('data:image/webp;base64,AQID');
  act(() => renderer!.update(<ProfileHomeScreen />));
  expect(renderer!.root.findAllByType(Image)).toHaveLength(1);
  jest.mocked(useProfilePhoto).mockReturnValue(null);
  act(() => renderer!.update(<ProfileHomeScreen />));
  expect(renderer!.root.findAllByType(Image)).toHaveLength(0);
});
