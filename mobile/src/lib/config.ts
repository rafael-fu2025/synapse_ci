import Config from 'react-native-config';

const devFallback = 'http://10.0.2.2:8080';

function normalize(url: string | undefined): string {
  if (!url) {
    return devFallback;
  }
  const trimmed = url.replace(/\/+$/, '');
  return trimmed.startsWith('http://') || trimmed.startsWith('https://') ? trimmed : devFallback;
}

export const config = {
  apiBaseUrl: normalize(Config.API_BASE_URL),
};
