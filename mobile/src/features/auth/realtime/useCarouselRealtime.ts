import { useEffect } from 'react';
import { onlineManager, useQueryClient } from '@tanstack/react-query';
import { startCarouselRealtime } from './carouselRealtime';

/** Foreground comes from the carousel's existing AppState listener; active excludes the keyboard. */
export function useCarouselRealtime(active: boolean, foreground: boolean): void {
  const client = useQueryClient();
  useEffect(() => {
    if (!active || !foreground) { return; }
    let stop: (() => void) | undefined;
    const update = () => {
      if (onlineManager.isOnline()) {
        stop ??= startCarouselRealtime(client);
      } else {
        stop?.();
        stop = undefined;
      }
    };
    const unsubscribe = onlineManager.subscribe(update);
    update();
    return () => {
      unsubscribe();
      stop?.();
    };
  }, [active, foreground, client]);
}
