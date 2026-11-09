import { useEffect } from 'react';
import { onlineManager, useQueryClient } from '@tanstack/react-query';
import { useApiClient } from '../../../api/runtime';
import { startCarouselRealtime } from './carouselRealtime';

/** Foreground comes from the carousel's existing AppState listener; active excludes the keyboard. */
export function useCarouselRealtime(active: boolean, foreground: boolean): void {
  const client = useQueryClient();
  const api = useApiClient();
  useEffect(() => {
    if (!active || !foreground) { return; }
    let stop: (() => void) | undefined;
    const update = () => {
      if (onlineManager.isOnline()) {
        stop ??= startCarouselRealtime(client, api);
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
  }, [active, api, foreground, client]);
}
