import React, { createContext, useCallback, useContext, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { NativeModules, Platform, StyleSheet, View } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { useTheme } from '../theme/ThemeProvider';
import { palette } from '../theme/tokens';

export type NavigationBarSurface = 'personal' | 'workspace' | 'splash' | 'sheet' | 'dialog';
type Surface = { kind: NavigationBarSurface; modal: boolean };
type NativeAppearance = { setAppearance: (background: string, darkIcons: boolean, modalBackground: string, modalDarkIcons: boolean) => void };
type Registry = { set: (owner: object, surface: Surface | null) => void };
const RegistryContext = createContext<Registry | null>(null);
type OptionalNavigation = NonNullable<React.ContextType<NonNullable<typeof NavigationContext>>> | undefined;
const OptionalNavigationContext = NavigationContext ?? createContext<OptionalNavigation>(undefined);

function rgb(color: string): number[] { return [1, 3, 5].map(offset => parseInt(color.slice(offset, offset + 2), 16)); }
export function dimNavigationBar(color: string): string {
  return `#${rgb(color).map(value => Math.round(value * 0.54).toString(16).padStart(2, '0')).join('')}`;
}
export function navigationBarDarkIcons(color: string): boolean {
  const [red, green, blue] = rgb(color).map(value => {
    const channel = value / 255;
    return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
  });
  return red * 0.2126 + green * 0.7152 + blue * 0.0722 > 0.179;
}

/** Insets protect controls; transparent native bars show the surface already behind them. */
export function SystemNavigationBarProvider({ children }: { children: React.ReactNode }) {
  const { theme } = useTheme();
  const [surfaces, setSurfaces] = useState(new Map<object, Surface>());
  const set = useCallback((owner: object, surface: Surface | null) => {
    setSurfaces(current => {
      const previous = current.get(owner);
      if (previous?.kind === surface?.kind && previous?.modal === surface?.modal) { return current; }
      const next = new Map(current);
      if (surface) { next.set(owner, surface); } else { next.delete(owner); }
      return next;
    });
  }, []);
  const registry = useMemo(() => ({ set }), [set]);
  let background = theme.canvas;
  let modal: NavigationBarSurface | undefined;
  surfaces.forEach(surface => {
    if (surface.modal) { modal = surface.kind; }
    else { background = surface.kind === 'workspace' ? theme.brand : surface.kind === 'splash' ? palette.brand600 : theme.surface; }
  });
  const modalBackground = modal === 'sheet' ? theme.surface : modal === 'dialog' ? dimNavigationBar(background) : background;
  useLayoutEffect(() => {
    if (Platform.OS !== 'android') { return; }
    const native = NativeModules.SystemNavigationBar as NativeAppearance | undefined;
    native?.setAppearance(background, navigationBarDarkIcons(background), modalBackground, navigationBarDarkIcons(modalBackground));
  }, [background, modalBackground]);
  return <RegistryContext.Provider value={registry}><View style={[styles.root, { backgroundColor: theme.canvas }]}>{children}</View></RegistryContext.Provider>;
}

/** Retained routes release their style on blur; modal styles last through native dismissal. */
export function useSystemNavigationBar(kind: NavigationBarSurface, enabled = true) {
  const registry = useContext(RegistryContext);
  const navigation = useContext(OptionalNavigationContext);
  const owner = useRef({}).current;
  const modal = kind === 'sheet' || kind === 'dialog';
  const focused = useRef(navigation?.isFocused() ?? true);
  const latest = useRef({ kind, enabled, modal });
  latest.current = { kind, enabled, modal };
  const apply = useCallback(() => {
    const current = latest.current;
    registry?.set(owner, current.enabled && (current.modal || focused.current) ? { kind: current.kind, modal: current.modal } : null);
  }, [owner, registry]);
  useLayoutEffect(() => {
    if (modal || !navigation) { return; }
    focused.current = navigation.isFocused();
    apply();
    // A frozen tab may not render on blur; release directly into the outer registry.
    const focus = navigation.addListener('focus', () => { focused.current = true; apply(); });
    const blur = navigation.addListener('blur', () => { focused.current = false; apply(); });
    return () => { focus(); blur(); };
  }, [apply, modal, navigation]);
  useLayoutEffect(() => { apply(); }, [apply, enabled, kind, modal]);
  useLayoutEffect(() => () => registry?.set(owner, null), [owner, registry]);
}

const styles = StyleSheet.create({ root: { flex: 1 } });
