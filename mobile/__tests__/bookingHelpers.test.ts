import { BOOKING_STEPS, formatPreferredTime, nextDays, previousBookingStep } from '../src/features/appointments/booking';

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

it('includes weekends and full dates across a month boundary', () => {
  const days = nextDays(4, new Date('2026-10-30T01:00:00Z'));
  expect(days.map(day => day.date)).toEqual(['2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03']);
  expect(days.map(day => day.weekday)).toEqual(['Sat', 'Sun', 'Mon', 'Tue']);
});

it('starts tomorrow using Manila, not the UTC or device date', () => {
  expect(nextDays(1, new Date('2026-12-31T18:00:00Z'))[0].date).toBe('2027-01-02');
  expect(nextDays(0, new Date('2026-12-31T18:00:00Z'))).toEqual([]);
});
