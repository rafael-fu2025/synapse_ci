import { useSyncExternalStore } from 'react';
import { AccessibilityInfo } from 'react-native';

const listeners = new Set<() => void>();
let reduced = true;
let revision = 0;
let subscription: ReturnType<typeof AccessibilityInfo.addEventListener> | null = null;

function subscribe(changed: () => void) {
  listeners.add(changed);
  if (listeners.size === 1) {
    const epoch = ++revision;
    let nativeChanged = false;
    const update = (enabled: boolean) => {
      if (epoch !== revision || listeners.size === 0 || reduced === enabled) { return; }
      reduced = enabled;
      for (const listener of [...listeners]) { listener(); }
    };
    subscription = AccessibilityInfo.addEventListener('reduceMotionChanged', enabled => {
      nativeChanged = true;
      update(enabled);
    });
    AccessibilityInfo.isReduceMotionEnabled().then(enabled => {
      if (!nativeChanged) { update(enabled); }
    }).catch(() => undefined);
  }
  return () => {
    listeners.delete(changed);
    if (listeners.size > 0) { return; }
    revision += 1;
    subscription?.remove();
    subscription = null;
    reduced = true;
  };
}

const snapshot = () => reduced;
const conservativeSnapshot = () => true;

/** Share observed native preferences with new presenters; unknown preferences stay conservative. */
export function useReducedMotion(): boolean {
  return useSyncExternalStore(subscribe, snapshot, conservativeSnapshot);
}
