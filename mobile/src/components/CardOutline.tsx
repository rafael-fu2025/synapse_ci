import React, { useId } from 'react';
import { StyleSheet } from 'react-native';
import Svg, { Defs, LinearGradient, Rect, Stop } from 'react-native-svg';

/**
 * A hairline border that dissolves toward the right edge.
 *
 * `borderColor` cannot carry a gradient, so the outline is drawn as an SVG
 * stroke. Its SVG is inset by one pixel so every side of the 2px stroke is
 * rendered inside the card instead of losing its bottom and right halves at the
 * SVG viewport boundary. The visible result is a crisp 1px line that follows
 * the card's corners. The stroke is a left-to-right gradient that ends fully
 * transparent, so the card has no hard edge on the side where its artwork or
 * trailing content sits.
 *
 * Decorative: `pointerEvents="none"` keeps every tap on the card itself.
 */
export function CardOutline({
  radius = 15,
  testID,
}: {
  /** Must match the card's borderRadius so the line hugs its corners. */
  radius?: number;
  testID?: string;
}) {
  // useId can contain characters that are not valid in an SVG url() reference.
  const id = `cardOutline${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
  return (
    <Svg testID={testID} pointerEvents="none" style={StyleSheet.absoluteFill} width="100%" height="100%">
      <Defs>
        <LinearGradient id={id} x1="0" y1="0" x2="1" y2="0">
          <Stop offset="0" stopColor="#ffffff" stopOpacity={0.55} />
          <Stop offset="0.5" stopColor="#ffffff" stopOpacity={0.3} />
          <Stop offset="1" stopColor="#ffffff" stopOpacity={0} />
        </LinearGradient>
      </Defs>
      <Rect x={1} y={1} width="99%" height="99%" rx={radius - 1} ry={radius - 1} fill="none" stroke={`url(#${id})`} strokeWidth={2} />
    </Svg>
  );
}
