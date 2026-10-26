import React, { createContext, useContext, useEffect, useState } from 'react';
import { useColorScheme } from 'react-native';
import { darkTheme, lightTheme, Theme, ThemeMode } from './tokens';
import { getThemePreference, setThemePreference, ThemePreference } from '../lib/storage';

interface ThemeContextValue {
  theme: Theme;
  mode: ThemeMode;
  preference: ThemePreference;
  setPreference: (preference: ThemePreference) => void;
}

const ThemeContext = createContext<ThemeContextValue>({
  theme: lightTheme,
  mode: 'light',
  preference: 'system',
  setPreference: () => undefined,
});

/** System appearance by default; Settings offers an explicit light/dark override. */
export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const system = useColorScheme();
  const [preference, setPreferenceState] = useState<ThemePreference>('system');

  useEffect(() => {
    getThemePreference().then(setPreferenceState);
  }, []);

  const mode: ThemeMode = preference === 'system' ? (system === 'dark' ? 'dark' : 'light') : preference;
  const value: ThemeContextValue = {
    mode,
    theme: mode === 'dark' ? darkTheme : lightTheme,
    preference,
    setPreference: (next) => {
      setPreferenceState(next);
      setThemePreference(next).catch(() => undefined);
    },
  };

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeContextValue {
  return useContext(ThemeContext);
}
