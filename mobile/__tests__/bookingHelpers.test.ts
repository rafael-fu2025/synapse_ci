import {
  BOOKING_STEPS,
  PREFERRED_TIMES,
  formatPreferredTime,
  isHalfDay,
  nextDays,
  previousBookingStep,
} from '../src/features/appointments/booking';

it.each([
  ['00:00', '12:00 AM'],
  ['08:00', '8:00 AM'],
  ['11:30', '11:30 AM'],
  ['12:00', '12:00 PM'],
  ['12:30', '12:30 PM'],
  ['13:00', '1:00 PM'],
  ['16:30', '4:30 PM'],
  ['23:59', '11:59 PM'],
  ['24:00', '—'],
  ['09:60', '—'],
  ['garbage', '—'],
])('formats preferred time %s as %s without changing its payload', (value, expected) => {
  expect(formatPreferredTime(value)).toBe(expected);
});

it('keeps all four form steps and never sends success back to review', () => {
  expect(BOOKING_STEPS).toEqual(['unit', 'details', 'date-time', 'review']);
  expect(previousBookingStep('review')).toBe('date-time');
  expect(previousBookingStep('date-time')).toBe('details');
  expect(previousBookingStep('details')).toBe('unit');
  expect(previousBookingStep('unit')).toBeNull();
  expect(previousBookingStep('success')).toBeNull();
});

it('offers a 15-minute grid across the working window, without a lunch slot', () => {
  expect(PREFERRED_TIMES).toHaveLength(32);
  expect(PREFERRED_TIMES[0]).toBe('08:00');
  expect(PREFERRED_TIMES[PREFERRED_TIMES.length - 1]).toBe('16:45');
  // Every value sits on a quarter hour, and the 11:45 → 13:00 lunch gap holds.
  for (const value of PREFERRED_TIMES) {
    expect(value.endsWith(':00') || value.endsWith(':15') || value.endsWith(':30') || value.endsWith(':45')).toBe(true);
  }
  expect(PREFERRED_TIMES).not.toContain('12:00');
  expect(PREFERRED_TIMES).not.toContain('12:30');
  expect(PREFERRED_TIMES.filter(value => value < '12:00')).toHaveLength(16);
  expect(PREFERRED_TIMES.filter(value => value >= '12:00')).toHaveLength(16);
});

it('treats Saturday as a half day', () => {
  expect(isHalfDay('2026-10-31')).toBe(true); // Saturday
  expect(isHalfDay('2026-10-30')).toBe(false); // Friday
  expect(isHalfDay('2026-11-01')).toBe(false); // Sunday
  expect(isHalfDay('2026-11-02')).toBe(false); // Monday
  expect(isHalfDay('not-a-date')).toBe(false);
});

it('skips Sundays but keeps Saturdays, across a month boundary', () => {
  const days = nextDays(4, new Date('2026-10-30T01:00:00Z'));
  // 2026-10-31 is a Saturday (kept); 2026-11-01 is a Sunday (skipped).
  expect(days.map(day => day.date)).toEqual(['2026-10-31', '2026-11-02', '2026-11-03', '2026-11-04']);
  expect(days.map(day => day.weekday)).toEqual(['Sat', 'Mon', 'Tue', 'Wed']);
});

it('still returns the requested number of selectable days when Sundays are skipped', () => {
  const days = nextDays(14, new Date('2026-10-30T01:00:00Z'));
  expect(days).toHaveLength(14);
  expect(days.every(day => day.weekday !== 'Sun')).toBe(true);
  // The window reaches further out than 14 calendar days because of the skipped Sundays.
  expect(days[days.length - 1].date > '2026-11-13').toBe(true);
});

it('starts tomorrow using Manila, not the UTC or device date', () => {
  expect(nextDays(1, new Date('2026-12-31T18:00:00Z'))[0].date).toBe('2027-01-02');
  expect(nextDays(0, new Date('2026-12-31T18:00:00Z'))).toEqual([]);
});
