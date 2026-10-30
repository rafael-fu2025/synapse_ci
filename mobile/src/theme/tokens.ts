/**
 * Design tokens translated 1:1 from mobile/mobileScreenReference (index.css).
 * Reading surfaces follow the reference, with white brand foregrounds in dark mode.
 */

export type ThemeMode = 'light' | 'dark';

export const palette = {
  brand900: '#520000',
  brand800: '#650000',
  brand700: '#800000',
  brand600: '#800000',
  brand100: '#f0d5d5',
  brand50: '#f8eeee',
  gold600: '#b47b17',
  gold500: '#d1972f',
  gold100: '#faedcf',
  violet600: '#7356a2',
  violet100: '#eee8f7',
  blue600: '#347293',
  blue100: '#e3f0f6',
  red700: '#a33b3b',
  red100: '#fae8e6',
} as const;

export interface Theme {
  mode: ThemeMode;
  /** Maroon fills stay fixed; foregrounds adapt for contrast on themed surfaces. */
  brand: string;
  brand50: string;
  brand100: string;
  brandText: string;
  brandIcon: string;

  surface: string;
  surfaceWarm: string;
  canvas: string;
  line: string;
  ink900: string;
  ink700: string;
  ink500: string;
  ink400: string;
  header: string;
  shadowColor: string;
}

export const lightTheme: Theme = {
  mode: 'light' as ThemeMode,
  brand: palette.brand700,
  brand50: palette.brand50,
  brand100: palette.brand100,
  brandText: palette.brand700,
  brandIcon: palette.brand700,

  surface: '#ffffff',
  surfaceWarm: '#fafbfc',
  canvas: '#f6f7fb',
  line: '#e5e7ef',
  ink900: '#111827',
  ink700: '#2d3a4e',
  ink500: '#667085',
  ink400: '#98a2b3',
  header: '#ffffff',
  shadowColor: '#1a3a36',
};

export const darkTheme: Theme = {
  mode: 'dark' as ThemeMode,
  brand: palette.brand700,
  brand50: '#2e1c1c',
  brand100: '#3f2424',
  brandText: '#ffffff',
  brandIcon: '#ffffff',

  surface: '#1c1c1c',
  surfaceWarm: '#222222',
  canvas: '#121212',
  line: '#484848',
  ink900: '#ffffff',
  ink700: '#f0f0f0',
  ink500: '#a3a3a3',
  ink400: '#888888',
  header: '#181818',
  shadowColor: '#000000',
};

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 15,
  xl: 18,
  xxl: 22,
} as const;

export const radius = {
  sm: 10,
  md: 15,
  lg: 22,
  pill: 999,
} as const;

export const font = {
  body: 'Nunito Sans',
  bodyBold: 'Nunito Sans',
  display: 'Poppins',
} as const;

export const type = {
  // Body sizes mirror the reference's 9-13px scale, nudged for device density.
  micro: { fontSize: 9, fontWeight: '700' as const, fontFamily: font.body },
  caption: { fontSize: 10.5, fontWeight: '600' as const, fontFamily: font.body },
  body: { fontSize: 11.5, fontWeight: '400' as const, fontFamily: font.body },
  bodyStrong: { fontSize: 11.5, fontWeight: '700' as const, fontFamily: font.body },
  action: { fontSize: 13, fontWeight: '700' as const, fontFamily: font.body },
  cardTitle: { fontSize: 14, fontWeight: '700' as const, fontFamily: font.display },
  pageTitle: { fontSize: 21, fontWeight: '800' as const, fontFamily: font.display },
  hero: { fontSize: 22, fontWeight: '800' as const, fontFamily: font.display },
} as const;

/** Small shadows from the reference: --shadow-sm and --shadow-md. */
export const elevation = {
  sm: { elevation: 2, shadowOpacity: 0.07, shadowRadius: 9, shadowOffset: { width: 0, height: 2 } },
  md: { elevation: 6, shadowOpacity: 0.11, shadowRadius: 30, shadowOffset: { width: 0, height: 12 } },
} as const;

export const statusTone = {
  neutral: { fg: '#2d3a4e', bg: '#eef2f1' },
  success: { fg: '#176259', bg: '#dcf0eb' },
  warning: { fg: '#95630f', bg: palette.gold100 },
  danger: { fg: palette.red700, bg: palette.red100 },
  info: { fg: '#2d6d8d', bg: palette.blue100 },
} as const;

export type StatusTone = keyof typeof statusTone;

/**
 * Screens build their StyleSheet from the active theme. Caching per theme object
 * keeps re-renders from allocating a fresh StyleSheet on every pass.
 */
export function memoizeStyles<S>(factory: (theme: Theme) => S): (theme: Theme) => S {
  const cache = new WeakMap<Theme, S>();
  return (theme: Theme): S => {
    let styles = cache.get(theme);
    if (styles === undefined) {
      styles = factory(theme);
      cache.set(theme, styles);
    }
    return styles;
  };
}
