import React, { createContext, useCallback, useContext, useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { AccessibilityInfo, Animated, Easing, findNodeHandle, Platform, Text, TextInput } from 'react-native';
import { useReducedMotion } from '../lib/useReducedMotion';

type PresentationPhase = 'idle' | 'presented' | 'dismissing';
type PresentationState = { phase: PresentationPhase; token: number };
type OverlayPresentation = {
  mounted: boolean;
  nativeVisible: boolean;
  progress: Animated.Value;
  cycle: number;
  abandoned: boolean;
  onNativeDismiss: () => void;
};
type OverlayRequest = {
  id: string;
  visible: boolean;
  onDismiss?: () => void;
  render: (presentation: OverlayPresentation) => React.ReactNode;
};
type OverlayEntry = OverlayRequest & { abandoned: boolean; retained: boolean; attempt: number };
type OverlayQueue = {
  active: string | undefined;
  busy: boolean;
  upsert: (request: OverlayRequest) => void;
  abandon: (id: string) => void;
  complete: (id: string) => OverlayEntry | undefined;
  hasPending: () => boolean;
};
const OverlayContext = createContext<OverlayQueue | null>(null);
type OverlayFocusLifecycle = { capture: () => void; requestRestore: () => void; onDismiss: () => void };
const OverlayFocusContext = createContext<OverlayFocusLifecycle | null>(null);
const OverlayRequestsContext = createContext<Pick<OverlayQueue, 'upsert' | 'abandon'> | null>(null);

/**
 * Retain the active presenter here so a screen unmount cannot release a native
 * modal early. Requesters publish controlled intent; only the head gets a Modal.
 * Put this under contexts used by overlay children, and key it by auth identity.
 */
export function OverlayProvider({ children }: { children: React.ReactNode }) {
  const [entries, setEntries] = useState<OverlayEntry[]>([]);
  const current = useRef(entries);
  const attempts = useRef(0);
  const alive = useRef(true);
  useLayoutEffect(() => {
    alive.current = true;
    return () => { alive.current = false; };
  }, []);
  const update = useCallback((change: (value: OverlayEntry[]) => OverlayEntry[]) => {
    if (!alive.current) { return; }
    const next = change(current.current);
    if (next !== current.current) {
      current.current = next;
      setEntries(next);
    }
  }, []);
  const upsert = useCallback((request: OverlayRequest) => update(value => {
    const index = value.findIndex(entry => entry.id === request.id);
    if (index === -1) {
      if (!request.visible) { return value; }
      attempts.current += 1;
      return [...value, { ...request, abandoned: false, retained: value.length === 0, attempt: attempts.current }];
    }
    const entry = value[index];
    // A cancelled queued request never presented and needs no onDismiss.
    if (index > 0 && !request.visible && !entry.retained) {
      return value.filter(item => item.id !== request.id);
    }
    const next = [...value];
    next[index] = { ...request, abandoned: false, retained: entry.retained || index === 0, attempt: entry.attempt };
    return next;
  }), [update]);
  const abandon = useCallback((id: string) => update(value => {
    const index = value.findIndex(entry => entry.id === id);
    if (index === -1) { return value; }
    const entry = value[index];
    if (index > 0 && !entry.retained) { return value.filter(item => item.id !== id); }
    const next = [...value];
    next[index] = { ...entry, visible: false, abandoned: true, onDismiss: undefined, retained: true };
    return next;
  }), [update]);
  const complete = useCallback((id: string): OverlayEntry | undefined => {
    let completed: OverlayEntry | undefined;
    update(value => {
      const entry = value.find(item => item.id === id);
      if (!entry) { return value; }
      completed = entry;
      const next = value.filter(item => item.id !== id);
      // Once native hide starts it is irreversible. A renewed request goes to the
      // tail, behind dialogs already waiting for this dismissal to finish.
      if (entry.visible && !entry.abandoned) {
        attempts.current += 1;
        next.push({ ...entry, retained: false, attempt: attempts.current });
      }
      if (next.length) { next[0] = { ...next[0], retained: true }; }
      return next;
    });
    return completed;
  }, [update]);
  const hasPending = useCallback(() => current.current.some(entry => entry.visible && !entry.abandoned), []);
  const active = entries[0]?.id;
  const busy = entries.length > 0;
  const queue = useMemo(() => ({ active, busy, upsert, abandon, complete, hasPending }), [abandon, active, busy, complete, hasPending, upsert]);
  const requests = useMemo(() => ({ upsert, abandon }), [abandon, upsert]);
  return (
    <OverlayRequestsContext.Provider value={requests}>
      <OverlayContext.Provider value={queue}>
        {children}
        {entries[0] ? <OverlayHost key={`${entries[0].id}:${entries[0].attempt}`} entry={entries[0]} complete={complete} /> : null}
      </OverlayContext.Provider>
    </OverlayRequestsContext.Provider>
  );
}

function OverlayHost({ entry, complete }: { entry: OverlayEntry; complete: OverlayQueue['complete'] }) {
  const latest = useRef(entry);
  latest.current = entry;
  const { capture, requestRestore, onDismiss: dismissed } = useOverlayFocus(entry.visible, undefined, entry.abandoned);
  const finish = useCallback(() => {
    const request = complete(latest.current.id);
    // Use the queue's committed abandonment state: a callback may arrive before
    // this retained host has rerendered after the requester unmounted.
    if (request && !request.abandoned) { dismissed(); request.onDismiss?.(); }
  }, [complete, dismissed]);
  const presentation = useOverlayPresentation(entry.visible, finish);
  useLayoutEffect(() => { if (presentation.nativeVisible) { capture(); } }, [capture, presentation.nativeVisible]);
  const focusLifecycle = useMemo(() => ({ capture: capture, requestRestore: requestRestore, onDismiss: dismissed }), [capture, dismissed, requestRestore]);
  return presentation.mounted ? (
    <OverlayFocusContext.Provider value={focusLifecycle}>
      {entry.render({ ...presentation, abandoned: entry.abandoned })}
    </OverlayFocusContext.Provider>
  ) : null;
}

export function useOverlayActivity(): boolean {
  return useContext(OverlayContext)?.busy ?? false;
}

/** Internal host bridge; standalone components still mount without a provider. */
export function useOverlayHost(visible: boolean, onDismiss: (() => void) | undefined, render: OverlayRequest['render']): React.ReactNode {
  const queue = useContext(OverlayRequestsContext);
  const id = useId();
  const upsert = queue?.upsert;
  const abandon = queue?.abandon;
  const latest = useRef({ visible, onDismiss, render });
  latest.current = { visible, onDismiss, render };
  useLayoutEffect(() => {
    upsert?.({ id, ...latest.current });
  });
  useLayoutEffect(() => () => { abandon?.(id); }, [abandon, id]);
  return queue ? null : <StandaloneOverlay visible={visible} onDismiss={onDismiss} render={render} />;
}

function StandaloneOverlay({ visible, onDismiss, render }: Omit<OverlayRequest, 'id'>) {
  const { capture, requestRestore, onDismiss: dismissed } = useOverlayFocus(visible, onDismiss);
  const presentation = useOverlayPresentation(visible, dismissed);
  useLayoutEffect(() => { if (presentation.nativeVisible) { capture(); } }, [capture, presentation.nativeVisible]);
  const focusLifecycle = useMemo(() => ({ capture: capture, requestRestore: requestRestore, onDismiss: dismissed }), [capture, dismissed, requestRestore]);
  return presentation.mounted ? <OverlayFocusContext.Provider value={focusLifecycle}>{render(presentation)}</OverlayFocusContext.Provider> : null;
}

/**
 * Reversing an animated exit before native hide continues the same cycle. After
 * hide, wait for the native iOS callback (Android: a frame after hidden commit).
 * Cycle tokens reject early, duplicate and stale native/animation completions.
 */
export function useOverlayPresentation(visible: boolean, onDismiss?: () => void): OverlayPresentation {
  const [state, setState] = useState<PresentationState>({ phase: 'idle', token: 0 });
  const stateRef = useRef(state);
  const progress = useRef(new Animated.Value(0)).current;
  const reduced = useReducedMotion();
  const alive = useRef(true);
  const revision = useRef(0);
  const sequence = useRef(0);
  const latest = useRef({ visible, onDismiss });
  latest.current = { visible, onDismiss };
  const transition = useCallback((next: PresentationState) => {
    stateRef.current = next;
    setState(next);
  }, []);
  useLayoutEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
      revision.current += 1;
      progress.stopAnimation();
    };
  }, [progress]);
  useLayoutEffect(() => {
    if (visible && stateRef.current.phase === 'idle') {
      sequence.current += 1;
      progress.setValue(0);
      transition({ phase: 'presented', token: sequence.current });
    }
  }, [progress, state.phase, transition, visible]);
  const finish = useCallback((token: number) => {
    if (!alive.current || stateRef.current.phase !== 'dismissing' || stateRef.current.token !== token) { return; }
    transition({ phase: 'idle', token });
    latest.current.onDismiss?.();
  }, [transition]);
  const onNativeDismiss = useCallback(() => finish(state.token), [finish, state.token]);
  useEffect(() => {
    if (state.phase !== 'presented') { return undefined; }
    const animationRevision = ++revision.current;
    progress.stopAnimation();
    const done = ({ finished }: { finished: boolean }) => {
      if (!finished || !alive.current || revision.current !== animationRevision || stateRef.current.phase !== 'presented' || stateRef.current.token !== state.token || latest.current.visible) { return; }
      transition({ phase: 'dismissing', token: state.token });
    };
    if (reduced) {
      progress.setValue(visible ? 1 : 0);
      done({ finished: true });
      return () => { revision.current += 1; };
    }
    const animation = Animated.timing(progress, { toValue: visible ? 1 : 0, duration: visible ? 240 : 180, easing: Easing.out(Easing.cubic), useNativeDriver: true });
    animation.start(done);
    return () => { revision.current += 1; animation.stop(); };
  }, [progress, reduced, state.phase, state.token, transition, visible]);
  useEffect(() => {
    if (state.phase !== 'dismissing' || Platform.OS === 'ios') { return undefined; }
    const frame = requestAnimationFrame(() => finish(state.token));
    return () => cancelAnimationFrame(frame);
  }, [finish, state.phase, state.token]);
  return { mounted: state.phase !== 'idle', nativeVisible: state.phase === 'presented', progress, cycle: state.token, abandoned: false, onNativeDismiss };
}

/**
 * Capture only native input focus, not unsupported screen-reader focus history.
 * Restore on cancellation after actual dismissal, but never behind a next overlay.
 */
export function useOverlayFocus(visible: boolean, onDismiss?: () => void, abandoned = false) {
  const parent = useContext(OverlayFocusContext);
  const queue = useContext(OverlayContext);
  const heading = useRef<React.ComponentRef<typeof Text>>(null);
  const previousInput = useRef<ReturnType<typeof TextInput.State.currentlyFocusedInput>>(null);
  const restore = useRef(false);
  const captured = useRef(false);
  const alive = useRef(true);
  const frame = useRef<ReturnType<typeof requestAnimationFrame> | null>(null);
  const latest = useRef({ visible, onDismiss, abandoned });
  latest.current = { visible, onDismiss, abandoned };
  const hasPending = queue?.hasPending;
  useLayoutEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
      previousInput.current = null;
      captured.current = false;
      restore.current = false;
      if (frame.current !== null) { cancelAnimationFrame(frame.current); }
    };
  }, []);
  const capture = useCallback(() => {
    if (!alive.current || latest.current.abandoned || captured.current) { return; }
    if (parent) { parent.capture(); return; }
    previousInput.current = TextInput.State.currentlyFocusedInput();
    captured.current = true;
    restore.current = false;
  }, [parent]);
  const onShow = useCallback(() => {
    if (!alive.current || latest.current.abandoned) { return; }
    capture();
    const handle = findNodeHandle(heading.current);
    if (typeof handle === 'number') { AccessibilityInfo.setAccessibilityFocus(handle); }
  }, [capture]);
  const requestRestore = useCallback(() => {
    if (parent) { parent.requestRestore(); }
    else { restore.current = true; }
  }, [parent]);
  const dismissed = useCallback(() => {
    const input = previousInput.current;
    const shouldRestore = restore.current && !latest.current.visible;
    previousInput.current = null;
    captured.current = false;
    restore.current = false;
    if (!alive.current || latest.current.abandoned) { return; }
    latest.current.onDismiss?.();
    if (!shouldRestore || !input) { return; }
    if (frame.current !== null) { cancelAnimationFrame(frame.current); }
    frame.current = requestAnimationFrame(() => {
      frame.current = null;
      if (!alive.current || latest.current.abandoned || latest.current.visible || hasPending?.()) { return; }
      try {
        const currentInput = TextInput.State.currentlyFocusedInput();
        // Do not steal focus from another field or re-focus a detached host node.
        if ((!currentInput || currentInput === input) && findNodeHandle(input) !== null) { input.focus(); }
      } catch {
        // The requesting field may have unmounted while the modal was closing.
      }
    });
  }, [hasPending]);
  return { heading, capture, onShow, requestRestore, onDismiss: dismissed };
}
