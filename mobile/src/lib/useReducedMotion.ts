import { useEffect, useState } from 'react';
import { AccessibilityInfo } from 'react-native';

/** Be conservative until native preferences resolve; a live event always wins. */
export function useReducedMotion(): boolean {
  const [reduced, setReduced] = useState(true);

  useEffect(() => {
    let mounted = true;
    let changed = false;
    const subscription = AccessibilityInfo.addEventListener('reduceMotionChanged', enabled => {
      changed = true;
      if (mounted) { setReduced(enabled); }
    });
    AccessibilityInfo.isReduceMotionEnabled().then(enabled => {
      if (mounted && !changed) { setReduced(enabled); }
    }).catch(() => undefined);
    return () => {
      mounted = false;
      subscription.remove();
    };
  }, []);

  return reduced;
}
