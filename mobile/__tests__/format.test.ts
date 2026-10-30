import {
  formatDate,
  formatDateTile,
  formatEyebrowDate,
  formatRelative,
  formatTime,
  manilaHour,
} from '../src/lib/format';
import { darkTheme, lightTheme, memoizeStyles } from '../src/theme/tokens';

describe('portal date presentation', () => {
  it('uses Manila time across the UTC date boundary', () => {
    const now = new Date('2026-10-26T23:30:00Z');
    expect(formatEyebrowDate(now)).toBe('TUESDAY, 27 OCTOBER');
    expect(manilaHour(now)).toBe(7);
    expect(formatDateTile(now.toISOString())).toEqual({ month: 'OCT', day: '27' });
  });

  it('formats a calendar tile by named date parts, not locale-dependent word positions', () => {
    expect(formatDateTile('2026-10-29T10:30:00+08:00')).toEqual({ month: 'OCT', day: '29' });
  });

  it('interprets database wall-clock datetimes as UTC', () => {
    expect(formatDate('2026-10-26 23:30:00')).toBe(formatDate('2026-10-27T07:30:00+08:00'));
    expect(formatTime('2026-10-26 23:30:00')).toBe(formatTime('2026-10-27T07:30:00+08:00'));
  });

  it.each([null, undefined, '', 'not a date', '2026-99-99 99:99:99'])('handles missing or invalid timestamps (%s)', value => {
    expect(formatDateTile(value)).toBeNull();
    expect(formatDate(value)).toBe('—');
    expect(formatTime(value)).toBe('—');
    expect(formatRelative(value)).toBe('');
  });

  it.each([
    ['2026-10-27T04:00:00Z', 'Just now'],
    ['2026-10-27T04:01:00Z', 'Just now'],
    ['2026-10-27T03:48:00Z', '12 min ago'],
    ['2026-10-27T03:00:00Z', '1 hour ago'],
    ['2026-10-27T02:00:00Z', '2 hours ago'],
    ['2026-10-26T03:00:00Z', 'Yesterday'],
  ])('presents a real update timestamp (%s)', (timestamp, label) => {
    expect(formatRelative(timestamp, new Date('2026-10-27T04:00:00Z'))).toBe(label);
  });
});

describe('themed style cache', () => {
  it('reuses each theme’s styles without mixing light and dark variants', () => {
    const factory = jest.fn(theme => ({ color: theme.ink900 }));
    const styles = memoizeStyles(factory);
    const light = styles(lightTheme);
    const dark = styles(darkTheme);
    expect(styles(lightTheme)).toBe(light);
    expect(styles(darkTheme)).toBe(dark);
    expect(light).not.toBe(dark);
    expect(light.color).toBe(lightTheme.ink900);
    expect(dark.color).toBe(darkTheme.ink900);
    expect(factory).toHaveBeenCalledTimes(2);
  });
});
