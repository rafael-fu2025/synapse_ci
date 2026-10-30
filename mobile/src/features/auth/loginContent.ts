import type { ImageSourcePropType } from 'react-native';
import { config } from '../../lib/config';

export interface LoginSlide {
  id: number;
  title: string;
  subtitle: string;
  lightImage: ImageSourcePropType;
  darkImage: ImageSourcePropType;
  fitMode: 'cover' | 'contain_blur';
}

/** Bundled copies of the web carousel's default artwork; usable before the API responds. */
export const FALLBACK_LOGIN_SLIDES: LoginSlide[] = [
  {
    id: 1,
    title: 'Foundation University',
    subtitle: 'Health & Guidance Services Portal',
    lightImage: require('../../assets/login/fuel-bg.jpg'),
    darkImage: require('../../assets/login/FoundationU-Dark.png'),
    fitMode: 'cover',
  },
  {
    id: 2,
    title: 'Guidance & Counseling Center',
    subtitle: 'Confidential Support & Mental Wellness Services',
    lightImage: require('../../assets/login/guidance-light.png'),
    darkImage: require('../../assets/login/guidance-dark.png'),
    fitMode: 'contain_blur',
  },
  {
    id: 3,
    title: 'University Health Services',
    subtitle: 'Accessible Campus Medical & Wellness Care',
    lightImage: require('../../assets/login/health-light.png'),
    darkImage: require('../../assets/login/health-dark.png'),
    fitMode: 'contain_blur',
  },
];

/** Public destinations copied from app/Views/auth/login.php, never composed from form data. */
export const LOGIN_SOCIAL_LINKS = [
  {
    label: 'Guidance',
    accessibilityLabel: 'Guidance Center on Facebook',
    image: require('../../assets/brand/guidance-center.png'),
    url: 'https://www.facebook.com/profile.php?id=100063778576372',
  },
  {
    label: 'FoundationU',
    accessibilityLabel: 'Foundation University on Facebook',
    image: require('../../assets/brand/FoundationU.png'),
    url: 'https://www.facebook.com/foundationu.edu',
  },
  {
    label: 'Health Care',
    accessibilityLabel: 'Health Services on Facebook',
    image: require('../../assets/brand/health-services.png'),
    url: 'https://www.facebook.com/profile.php?id=100057107094031',
  },
] as const;

function imageSource(path: unknown): ImageSourcePropType | null {
  if (typeof path !== 'string' || path.length > 255 ||
      !/^(?:assets\/img\/brand\/|uploads\/carousel\/)(?:[A-Za-z0-9_-][A-Za-z0-9._-]*\/)*[A-Za-z0-9_-][A-Za-z0-9._-]*\.(?:png|jpe?g|webp|gif)$/i.test(path)) {
    return null;
  }
  return { uri: `${config.apiBaseUrl}/${path}` };
}

/** Keep public artwork same-server and tolerate an older/unavailable backend. */
export function loginSlides(payload: unknown): LoginSlide[] {
  const rows = (payload as { data?: { slides?: unknown } } | null)?.data?.slides;
  if (!Array.isArray(rows)) {
    return FALLBACK_LOGIN_SLIDES;
  }
  const slides: LoginSlide[] = [];
  const ids = new Set<number>();
  for (const value of rows) {
    if (!value || typeof value !== 'object') {
      continue;
    }
    const slide = value as Record<string, unknown>;
    const id = Number(slide.id);
    const lightImage = imageSource(slide.light_image);
    if (!Number.isSafeInteger(id) || id <= 0 || ids.has(id) || !lightImage) {
      continue;
    }
    ids.add(id);
    slides.push({
      id,
      title: typeof slide.title === 'string' ? slide.title : '',
      subtitle: typeof slide.subtitle === 'string' ? slide.subtitle : '',
      lightImage,
      darkImage: imageSource(slide.dark_image) ?? lightImage,
      fitMode: slide.fit_mode === 'contain_blur' ? 'contain_blur' : 'cover',
    });
  }
  return slides.length > 0 ? slides : FALLBACK_LOGIN_SLIDES;
}
