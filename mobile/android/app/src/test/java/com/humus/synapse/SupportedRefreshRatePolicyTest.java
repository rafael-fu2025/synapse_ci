package com.humus.synapse;

import java.util.Arrays;
import java.util.Collections;

/** Runs on a plain JDK, without Android/JUnit dependencies or a connected device. */
public final class SupportedRefreshRatePolicyTest {
  private static int checks;

  public static void main(String[] args) {
    expect("no modes", 0f, Collections.emptyList());
    expect("60Hz only", 60f, mode(1080, 2400, 60f));
    expect("90Hz panel", 90f, mode(1080, 2400, 60f), mode(1080, 2400, 90f));
    expect("120Hz panel", 120f, mode(1080, 2400, 60f), mode(1080, 2400, 90f),
        mode(1080, 2400, 120f));
    expect("unsorted modes", 120f, mode(1080, 2400, 120f), mode(1080, 2400, 60f),
        mode(1080, 2400, 90f));
    expect("duplicate modes", 90f, mode(1080, 2400, 90f), mode(1080, 2400, 90f));
    expect("not capped at 120", 144f, mode(1080, 2400, 144f), mode(1080, 2400, 120f));
    expect("fractional rate is preserved", 119.88f, mode(1080, 2400, 59.94f),
        mode(1080, 2400, 119.88f));
    expect("no incompatible-resolution vote", 90f, mode(1080, 2400, 90f),
        mode(1440, 3200, 144f));
    expect("no lower-resolution switch", 60f, mode(1080, 2400, 60f),
        mode(720, 1600, 120f));
    expect("orientation is not interchangeable", 60f, mode(1080, 2400, 60f),
        mode(2400, 1080, 120f));
    expect("no matching resolution", 0f, mode(1440, 3200, 120f));
    expect("invalid rates fall back", 0f, mode(1080, 2400, Float.NaN),
        mode(1080, 2400, Float.POSITIVE_INFINITY), mode(1080, 2400, Float.NEGATIVE_INFINITY),
        mode(1080, 2400, 0f), mode(1080, 2400, -90f));
    expect("valid rate survives invalid advertisements", 90f, mode(1080, 2400, Float.NaN),
        mode(1080, 2400, Float.POSITIVE_INFINITY), mode(1080, 2400, 90f));
    expectRate("invalid current width", 0f,
        SupportedRefreshRatePolicy.preferredRate(0, 2400, Arrays.asList(mode(0, 2400, 120f)), true));
    expectRate("invalid current height", 0f,
        SupportedRefreshRatePolicy.preferredRate(1080, -1, Arrays.asList(mode(1080, -1, 120f)), true));
    expectRate("external display has its own rate", 75f,
        SupportedRefreshRatePolicy.preferredRate(1920, 1080,
            Arrays.asList(mode(1920, 1080, 75f), mode(1080, 2400, 120f)), true));
    expectRate("pre34 single-resolution display", 120f,
        SupportedRefreshRatePolicy.preferredRate(1080, 2400,
            Arrays.asList(mode(1080, 2400, 60f), mode(1080, 2400, 120f)), false));
    expectRate("pre34 multiresolution display defers to OS", 0f,
        SupportedRefreshRatePolicy.preferredRate(1080, 2400,
            Arrays.asList(mode(1080, 2400, 120f), mode(1440, 3200, 60f)), false));
    expectRate("pre34 lower resolution also defers to OS", 0f,
        SupportedRefreshRatePolicy.preferredRate(1080, 2400,
            Arrays.asList(mode(720, 1600, 120f), mode(1080, 2400, 90f)), false));
    expect("changed display can downshift without stale maximum", 60f, mode(1080, 2400, 60f));
    expect("missing modes after change releases preference", 0f, Collections.emptyList());
    System.out.println(checks + " refresh-rate selection checks passed");
  }

  private static SupportedRefreshRatePolicy.Mode mode(int width, int height, float refreshRate) {
    return new SupportedRefreshRatePolicy.Mode(width, height, refreshRate);
  }

  private static void expect(String name, float expected, SupportedRefreshRatePolicy.Mode... modes) {
    expect(name, expected, Arrays.asList(modes));
  }

  private static void expect(String name, float expected,
      Iterable<SupportedRefreshRatePolicy.Mode> modes) {
    expectRate(name, expected, SupportedRefreshRatePolicy.preferredRate(1080, 2400, modes, true));
  }

  private static void expectRate(String name, float expected, float actual) {
    checks++;
    if (Float.compare(expected, actual) != 0) {
      throw new AssertionError(name + ": expected " + expected + ", got " + actual);
    }
  }
}
