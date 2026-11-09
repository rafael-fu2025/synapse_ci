import { useCallback, useLayoutEffect, useMemo, useRef } from 'react';
import { Animated, PanResponder } from 'react-native';

type Options = {
  visible: boolean; settled: boolean; dismissible: boolean; abandoned: boolean;
  keyboard: boolean; reduced: boolean; travelHeight: number;
  onClose: () => void; requestRestore: () => void;
};

/** The native exit retains the finger offset; a rejected close returns in place. */
export function useSheetDrag(options: Options) {
  const drag = useRef(new Animated.Value(0)).current;
  const animation = useRef<Animated.CompositeAnimation | null>(null);
  const frame = useRef<ReturnType<typeof requestAnimationFrame> | null>(null);
  const latest = useRef(options);
  latest.current = options;
  const alive = useRef(false);
  const revision = useRef(0);
  const closeRequested = useRef(false);
  const gesture = useRef<{ base: number; origin: number; delta: number; ready: boolean } | null>(null);

  const stop = useCallback(() => {
    revision.current += 1;
    if (frame.current !== null) { cancelAnimationFrame(frame.current); frame.current = null; }
    animation.current?.stop();
    animation.current = null;
    drag.stopAnimation();
    return revision.current;
  }, [drag]);
  const settle = useCallback(() => {
    const epoch = stop();
    gesture.current = null;
    if (!alive.current || !latest.current.visible || latest.current.abandoned) { return; }
    if (latest.current.reduced) { drag.setValue(0); closeRequested.current = false; return; }
    const next = Animated.spring(drag, { toValue: 0, useNativeDriver: true, isInteraction: false, overshootClamping: true });
    animation.current = next;
    next.start(({ finished }) => {
      if (!finished || !alive.current || revision.current !== epoch) { return; }
      animation.current = null;
      if (latest.current.visible) { closeRequested.current = false; }
    });
  }, [drag, stop]);

  useLayoutEffect(() => {
    alive.current = true;
    return () => { alive.current = false; gesture.current = null; stop(); };
  }, [stop]);
  const wasVisible = useRef(options.visible);
  useLayoutEffect(() => {
    const reopening = options.visible && !wasVisible.current;
    wasVisible.current = options.visible;
    if (!options.visible || options.abandoned) {
      gesture.current = null;
      closeRequested.current = false;
      stop();
    } else if (reopening || options.keyboard || !options.dismissible) {
      settle();
    }
  }, [options.abandoned, options.dismissible, options.keyboard, options.visible, settle, stop]);
  useLayoutEffect(() => {
    if (options.reduced && options.visible && !options.abandoned) { settle(); }
  }, [options.abandoned, options.reduced, options.visible, settle]);

  const requestClose = useCallback(() => {
    const current = latest.current;
    if (!alive.current || !current.visible || !current.dismissible || current.abandoned || closeRequested.current) { return; }
    gesture.current = null;
    const epoch = stop();
    closeRequested.current = true;
    current.requestRestore();
    current.onClose();
    // Controlled visibility is authoritative, including delayed acceptance.
    frame.current = requestAnimationFrame(() => {
      frame.current = null;
      if (alive.current && revision.current === epoch && latest.current.visible && !latest.current.abandoned) { settle(); }
    });
  }, [settle, stop]);
  const responder = useMemo(() => PanResponder.create({
    onMoveShouldSetPanResponder: (_, event) => {
      const current = latest.current;
      return current.visible && current.settled && current.dismissible && !current.abandoned && !current.keyboard && !closeRequested.current &&
        event.dy > 8 && Math.abs(event.dy) > Math.abs(event.dx);
    },
    onPanResponderGrant: (_, event) => {
      const epoch = stop();
      const current = { base: 0, origin: event.dy, delta: event.dy, ready: false };
      gesture.current = current;
      drag.stopAnimation(value => {
        if (!alive.current || revision.current !== epoch || gesture.current !== current || !latest.current.visible || latest.current.keyboard) { return; }
        current.base = value;
        current.ready = true;
        drag.setValue(Math.min(latest.current.travelHeight, Math.max(0, value + current.delta - current.origin)));
      });
    },
    onPanResponderMove: (_, event) => {
      const current = gesture.current;
      if (!current || !latest.current.visible || latest.current.keyboard || latest.current.abandoned) { return; }
      current.delta = event.dy;
      if (current.ready) { drag.setValue(Math.min(latest.current.travelHeight, Math.max(0, current.base + event.dy - current.origin))); }
    },
    onPanResponderRelease: (_, event) => {
      const current = gesture.current;
      gesture.current = null;
      if (!current || !latest.current.visible || latest.current.abandoned) { return; }
      if (!latest.current.keyboard && (current.base + event.dy - current.origin > 80 || event.vy > 0.8)) { requestClose(); }
      else { settle(); }
    },
    onPanResponderTerminate: () => { gesture.current = null; settle(); },
  }), [drag, requestClose, settle, stop]);
  return { drag, responder, requestClose };
}
