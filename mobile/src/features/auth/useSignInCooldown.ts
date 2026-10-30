import { useCallback, useEffect, useRef, useState } from 'react';

const FALLBACK_SECONDS = 300;

export function useSignInCooldown() {
  const deadlineRef = useRef(0);
  const [deadline, setDeadline] = useState(0);
  const [remainingSeconds, setRemainingSeconds] = useState(0);

  const startCooldown = useCallback((retryAfterSeconds: number | null) => {
    const seconds = retryAfterSeconds ?? FALLBACK_SECONDS;
    const wait = Number.isFinite(seconds) && seconds >= 0 ? seconds : FALLBACK_SECONDS;
    const nextDeadline = Date.now() + wait * 1000;
    deadlineRef.current = nextDeadline;
    setRemainingSeconds(Math.ceil(wait));
    setDeadline(nextDeadline);
  }, []);

  // Read the deadline synchronously too: a tap need not wait for the next render/tick.
  const isCoolingDown = useCallback(() => Date.now() < deadlineRef.current, []);

  useEffect(() => {
    if (deadline === 0) {
      return;
    }
    let timer: ReturnType<typeof setTimeout> | undefined;
    const update = () => {
      const remainingMs = Math.max(0, deadline - Date.now());
      setRemainingSeconds(Math.ceil(remainingMs / 1000));
      if (remainingMs > 0) {
        timer = setTimeout(update, Math.min(1000, remainingMs));
      }
    };
    update();
    return () => clearTimeout(timer);
  }, [deadline]);

  return { remainingSeconds, startCooldown, isCoolingDown };
}
