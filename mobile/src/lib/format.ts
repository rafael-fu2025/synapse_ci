/**
 * The portal is a single-timezone deployment: every timestamp shown to users is
 * Asia/Manila wall-clock, matching the backend's ISO-8601 presentation.
 */

const MANILA = 'Asia/Manila';

const DATE_FORMAT = new Intl.DateTimeFormat('en-PH', {
  timeZone: MANILA,
  weekday: 'short',
  month: 'short',
  day: 'numeric',
});

const TIME_FORMAT = new Intl.DateTimeFormat('en-PH', {
  timeZone: MANILA,
  hour: 'numeric',
  minute: '2-digit',
  hour12: true,
});

const FULL_FORMAT = new Intl.DateTimeFormat('en-PH', {
  timeZone: MANILA,
  weekday: 'long',
  year: 'numeric',
  month: 'long',
  day: 'numeric',
});

export function formatDate(value: string | null | undefined): string {
  const date = parse(value);
  return date ? DATE_FORMAT.format(date) : '—';
}

export function formatTime(value: string | null | undefined): string {
  const date = parse(value);
  return date ? TIME_FORMAT.format(date) : '—';
}

export function formatDateTime(value: string | null | undefined): string {
  const date = parse(value);
  return date ? `${DATE_FORMAT.format(date)} · ${TIME_FORMAT.format(date)}` : '—';
}

export function formatFullDate(value: string | null | undefined): string {
  const date = parse(value);
  return date ? FULL_FORMAT.format(date) : '—';
}

/** Backend datetimes arrive as 'Y-m-d H:i:s' (UTC storage) or ISO-8601 with offset. */
function parse(value: string | null | undefined): Date | null {
  if (!value) {
    return null;
  }
  const trimmed = value.trim();
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(trimmed)) {
    // Stored UTC wall-clock; attach the offset explicitly.
    return new Date(trimmed.replace(' ', 'T') + 'Z');
  }
  const parsed = new Date(trimmed);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}
