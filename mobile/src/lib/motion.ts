import { Easing } from 'react-native';

/** Product motion presets; platform stack transitions retain their native timing. */
export const motion = {
  context: { sceneMs: 240, barMs: 400 },
  tabs: { durationMs: 160 },
  sheet: { enterMs: 240, exitMs: 180 },
  dialog: { enterMs: 180, exitMs: 140, fromScale: 0.97 },
  press: { pressMs: 80, releaseMs: 120, scale: 0.98 },
  visibility: { durationMs: 120 },
} as const;

export type OverlayTiming = Readonly<{ enterMs: number; exitMs: number }>;
export const motionEasing = Easing.out(Easing.cubic);
