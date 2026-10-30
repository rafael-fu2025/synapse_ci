import Config from 'react-native-config';
import { resolveApiBaseUrl } from './apiConfig';

export const config = {
  apiBaseUrl: resolveApiBaseUrl(Config.API_BASE_URL, __DEV__),
};
