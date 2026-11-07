import React, { createContext, useCallback, useContext, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import type { AppContextMode } from '../features/workspace/WorkspaceContext';
import { SharedContextTabBar } from './SharedContextTabBar';

type Binding = { owner: object; props: BottomTabBarProps };
type Bindings = Partial<Record<AppContextMode, Binding>>;
type ContextBarRegistry = {
  bindings: Bindings;
  bind: (mode: AppContextMode, owner: object, props: BottomTabBarProps) => void;
  release: (mode: AppContextMode, owner: object) => void;
};

const ContextBar = createContext<ContextBarRegistry | null>(null);

function sameProps(left: BottomTabBarProps, right: BottomTabBarProps) {
  return left.state === right.state && left.descriptors === right.descriptors
    && left.navigation === right.navigation && left.insets.top === right.insets.top
    && left.insets.right === right.insets.right && left.insets.bottom === right.insets.bottom
    && left.insets.left === right.insets.left;
}

/** Inner navigators publish real tab bindings; the outer context owns their presentation. */
export function ContextTabBarProvider({ children }: { children: React.ReactNode }) {
  const [bindings, setBindings] = useState<Bindings>({});
  const bind = useCallback((mode: AppContextMode, owner: object, props: BottomTabBarProps) => {
    setBindings(current => {
      const previous = current[mode];
      if (previous?.owner === owner && sameProps(previous.props, props)) { return current; }
      return { ...current, [mode]: { owner, props } };
    });
  }, []);
  const release = useCallback((mode: AppContextMode, owner: object) => {
    setBindings(current => {
      if (current[mode]?.owner !== owner) { return current; }
      const next = { ...current };
      delete next[mode];
      return next;
    });
  }, []);
  const value = useMemo(() => ({ bindings, bind, release }), [bindings, bind, release]);
  return <ContextBar.Provider value={value}>{children}</ContextBar.Provider>;
}

export function ContextTabBarBinding({ mode, ...props }: BottomTabBarProps & { mode: AppContextMode }) {
  const registry = useContext(ContextBar);
  const owner = useRef({}).current;
  const bind = registry?.bind;
  const release = registry?.release;
  useLayoutEffect(() => { bind?.(mode, owner, props); }, [bind, mode, owner, props]);
  useLayoutEffect(() => () => release?.(mode, owner), [mode, owner, release]);
  // Standalone navigators retain the same bar without requiring a second navigation tree.
  return registry ? null : <SharedContextTabBar {...props} />;
}

export function ContextTabBarHost({ mode }: { mode: AppContextMode }) {
  const registry = useContext(ContextBar);
  const binding = registry?.bindings[mode]?.props;
  const previous = useRef<BottomTabBarProps | null>(null);
  if (binding) { previous.current = binding; }
  const props = binding ?? previous.current;
  if (!props) { return null; }
  return (
    <SharedContextTabBar
      {...props}
      available={binding !== undefined}
      contextBindings={{ personal: registry?.bindings.personal?.props, workspace: registry?.bindings.workspace?.props }}
    />
  );
}
