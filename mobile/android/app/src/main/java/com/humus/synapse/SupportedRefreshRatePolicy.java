package com.humus.synapse;

/** Pure selection for the legacy Window hint; no mode ID or assumed panel rate. */
final class SupportedRefreshRatePolicy {
  private SupportedRefreshRatePolicy() {}

  static final class Mode {
    final int width;
    final int height;
    final float refreshRate;

    Mode(int width, int height, float refreshRate) {
      this.width = width;
      this.height = height;
      this.refreshRate = refreshRate;
    }
  }

  /** Zero releases the hint if no valid rate is advertised at the current resolution. */
  static float preferredRate(int width, int height, Iterable<Mode> modes,
      boolean refreshRateOnly) {
    float preferred = 0f;
    if (width <= 0 || height <= 0) return preferred;
    for (Mode mode : modes) {
      // Before API 34 a Window rate hint selects a default-resolution mode.
      // Without a public default-mode query, defer on multi-resolution displays.
      if (!refreshRateOnly && (mode.width != width || mode.height != height)) return 0f;
      if (mode.width == width && mode.height == height
          && Float.isFinite(mode.refreshRate) && mode.refreshRate > preferred) {
        preferred = mode.refreshRate;
      }
    }
    return preferred;
  }
}
