import { useIsFocused } from '@react-navigation/native';

/**
 * Polling interval that only runs while the screen is visible. Tabs stay mounted
 * for instant switching, so unfocused screens must not keep hitting the API.
 */
export function useFocusPoll(intervalMs: number): number | false {
  return useIsFocused() ? intervalMs : false;
}
