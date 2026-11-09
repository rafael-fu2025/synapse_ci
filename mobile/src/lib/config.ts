import Config from 'react-native-config';
import { Platform } from 'react-native';
import { resolveApiBaseUrl } from './apiConfig';
import { resolveLanApiBaseUrl } from '../features/connection/profiles';

const lanTest = Platform.OS === 'android' &&
  Config.SYNAPSE_BUILD_PROFILE === 'lan-test' && Config.SYNAPSE_ALLOW_LAN_HTTP === 'true';
let selected: { owner: number; apiBaseUrl: string } | null = null;

/** Only a committed testing connection can release the LAN startup gate. */
export function bindRuntimeApiBaseUrl(owner: number, apiBaseUrl: string): () => void {
  if (!lanTest) { return () => undefined; }
  selected = { owner, apiBaseUrl: resolveLanApiBaseUrl(apiBaseUrl) };
  return () => { if (selected?.owner === owner) { selected = null; } };
}

export const config = {
  lanTest,
  get apiBaseUrl(): string {
    if (!lanTest) { return resolveApiBaseUrl(Config.API_BASE_URL, __DEV__); }
    if (!selected) { throw new Error('Choose University or Home before connecting.'); }
    return selected.apiBaseUrl;
  },
};
