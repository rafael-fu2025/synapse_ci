import { darkTheme, lightTheme, palette, statusTone } from '../src/theme/tokens';

describe('design tokens', () => {
  it('keeps the maroon brand scale from the reference', () => {
    expect(palette.brand900).toBe('#520000');
    expect(palette.brand700).toBe('#800000');
    expect(palette.brand50).toBe('#f8eeee');
  });

  it('defines every status tone used by screens', () => {
    for (const tone of ['neutral', 'success', 'warning', 'danger', 'info'] as const) {
      expect(statusTone[tone].fg).toMatch(/^#/);
      expect(statusTone[tone].bg).toMatch(/^#/);
    }
  });

  it('provides complete dark overrides for reading surfaces', () => {
    expect(darkTheme.surface).toBe('#1c1c1c');
    expect(darkTheme.canvas).toBe('#121212');
    expect(darkTheme.ink900).toBe('#ffffff');
    expect(darkTheme.mode).toBe('dark');
    expect(lightTheme.mode).toBe('light');
  });
});
