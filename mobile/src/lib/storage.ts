import AsyncStorage from '@react-native-async-storage/async-storage';
import { ThemeMode } from '../theme/tokens';

const THEME_KEY = 'synapse.theme';

export type ThemePreference = 'system' | ThemeMode;

export async function getThemePreference(): Promise<ThemePreference> {
  try {
    const value = await AsyncStorage.getItem(THEME_KEY);
    return value === 'light' || value === 'dark' ? value : 'system';
  } catch {
    return 'system';
  }
}

export async function setThemePreference(preference: ThemePreference): Promise<void> {
  try {
    if (preference === 'system') {
      await AsyncStorage.removeItem(THEME_KEY);
    } else {
      await AsyncStorage.setItem(THEME_KEY, preference);
    }
  } catch {
    // A failed preference write must not break the app; system theme remains.
  }
}
