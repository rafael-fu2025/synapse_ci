import { useFocusPoll } from './useFocusPoll';
import { useRealtimeReady } from './useRealtimeReady';

/** For resources with matching socket hints: short polling is fallback, not a second live transport. */
export function useRealtimePoll(intervalMs: number): number | false {
  const interval = useFocusPoll(intervalMs);
  const realtime = useRealtimeReady();
  return realtime ? false : interval;
}
