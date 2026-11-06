export type BookingStep = 'unit' | 'details' | 'date-time' | 'review' | 'success';

export const BOOKING_STEPS = ['unit', 'details', 'date-time', 'review'] as const;

// Suggestions for a preferred time, not server availability or reserved slots.
// 15-minute steps match the web portal's finest configurable slot duration; the
// lunch break (11:45 → 13:00) and the 08:00–16:45 working window are preserved.
export const PREFERRED_TIMES = [
  '08:00', '08:15', '08:30', '08:45',
  '09:00', '09:15', '09:30', '09:45',
  '10:00', '10:15', '10:30', '10:45',
  '11:00', '11:15', '11:30', '11:45',
  '13:00', '13:15', '13:30', '13:45',
  '14:00', '14:15', '14:30', '14:45',
  '15:00', '15:15', '15:30', '15:45',
  '16:00', '16:15', '16:30', '16:45',
];

export const TYPE_LABELS: Record<string, string> = {
  routine: 'Routine consultation',
  initial: 'First session',
  follow_up: 'Follow-up',
  referral_based: 'Referral visit',
};

/** Format an HH:mm preference without interpreting it in the device timezone. */
export function formatPreferredTime(value: string): string {
  if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value)) {
    return '—';
  }
  const [hour, minute] = value.split(':');
  const hours = Number(hour);
  return `${hours % 12 || 12}:${minute} ${hours < 12 ? 'AM' : 'PM'}`;
}

export function previousBookingStep(step: BookingStep): BookingStep | null {
  const index = BOOKING_STEPS.findIndex(value => value === step);
  return index > 0 ? BOOKING_STEPS[index - 1] : null;
}

export interface DayOption {
  date: string;
  weekday: string;
  day: string;
}

/** Saturdays are half-day: only morning preferences are offered. */
export function isHalfDay(date: string): boolean {
  // Parse as a Manila calendar date; the weekday of a YYYY-MM-DD string is fixed
  // by the date itself, so a UTC anchor cannot shift it.
  const parsed = new Date(`${date}T00:00:00Z`);
  return !Number.isNaN(parsed.getTime()) && parsed.getUTCDay() === 6;
}

/**
 * Calendar suggestions start tomorrow in portal time.
 *
 * Sundays are skipped (no work or classes), so the caller still receives `count`
 * selectable days — the window extends further into the future instead of
 * shrinking. Saturdays remain available (half-day).
 */
export function nextDays(count: number, now = new Date()): DayOption[] {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(now);
  const part = (name: string) => Number(parts.find(value => value.type === name)?.value);
  const base = Date.UTC(part('year'), part('month') - 1, part('day'));
  const days: DayOption[] = [];
  // Walk forward until enough non-Sunday days are collected.
  for (let offset = 1; days.length < count && offset <= count + 14; offset += 1) {
    const day = new Date(base + offset * 86_400_000);
    if (day.getUTCDay() === 0) {
      continue;
    }
    days.push({
      date: day.toISOString().slice(0, 10),
      weekday: day.toLocaleDateString('en-PH', { timeZone: 'UTC', weekday: 'short' }),
      day: String(day.getUTCDate()),
    });
  }
  return days;
}
