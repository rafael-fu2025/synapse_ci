export type BookingStep = 'unit' | 'details' | 'date-time' | 'review' | 'success';

export const BOOKING_STEPS = ['unit', 'details', 'date-time', 'review'] as const;

// Suggestions for a preferred time, not server availability or reserved slots.
export const PREFERRED_TIMES = [
  '08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
  '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30',
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

/** Calendar suggestions start tomorrow in portal time and include weekends. */
export function nextDays(count: number, now = new Date()): DayOption[] {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(now);
  const part = (name: string) => Number(parts.find(value => value.type === name)?.value);
  const base = Date.UTC(part('year'), part('month') - 1, part('day'));
  return Array.from({ length: count }, (_, index) => {
    const day = new Date(base + (index + 1) * 86_400_000);
    return {
      date: day.toISOString().slice(0, 10),
      weekday: day.toLocaleDateString('en-PH', { timeZone: 'UTC', weekday: 'short' }),
      day: String(day.getUTCDate()),
    };
  });
}
