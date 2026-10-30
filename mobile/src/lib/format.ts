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

const TILE_FORMAT = new Intl.DateTimeFormat('en-PH', { timeZone: MANILA, month: 'short', day: 'numeric' });

const PARTS_FORMAT = new Intl.DateTimeFormat('en-PH', {
  timeZone: MANILA,
  weekday: 'long',
  month: 'long',
  day: 'numeric',
  hour: 'numeric',
  hour12: false,
});

function manilaParts(date: Date): Record<string, string> {
  const parts: Record<string, string> = {};
  for (const part of PARTS_FORMAT.formatToParts(date)) {
    parts[part.type] = part.value;
  }
  return parts;
}

/** Dashboard eyebrow, e.g. "TUESDAY, 27 OCTOBER" in portal time. */
export function formatEyebrowDate(now: Date = new Date()): string {
  const parts = manilaParts(now);
  return `${parts.weekday}, ${parts.day} ${parts.month}`.toUpperCase();
}

/** Hour of day (0-23) in portal time, independent of the device timezone. */
export function manilaHour(now: Date = new Date()): number {
  return Number(manilaParts(now).hour) % 24;
}

/** Month abbreviation and day number for calendar tiles, e.g. { month: 'OCT', day: '29' }. */
export function formatDateTile(value: string | null | undefined): { month: string; day: string } | null {
  const date = parse(value);
  if (!date) {
    return null;
  }
  const parts = TILE_FORMAT.formatToParts(date);
  const month = parts.find((part) => part.type === 'month')?.value ?? '';
  const day = parts.find((part) => part.type === 'day')?.value ?? '';
  return { month: month.toUpperCase(), day };
}

export function formatDate(value: string | null | undefined): string {
  const date = parse(value);
  return date ? DATE_FORMAT.format(date) : '—';
}

export function formatTime(value: string | null | undefined): string {
  const date = parse(value);
  return date ? TIME_FORMAT.format(date) : '—';
}

/** "Just now", "12 min ago", "2 hours ago", "Yesterday", otherwise a short date. */
export function formatRelative(value: string | null | undefined, now: Date = new Date()): string {
  const date = parse(value);
  if (!date) {
    return '';
  }
  const minutes = Math.floor((now.getTime() - date.getTime()) / 60_000);
  if (minutes < 1) {
    return 'Just now';
  }
  if (minutes < 60) {
    return `${minutes} min ago`;
  }
  const hours = Math.floor(minutes / 60);
  if (hours < 24) {
    return `${hours} hour${hours === 1 ? '' : 's'} ago`;
  }
  const days = Math.floor(hours / 24);
  return days === 1 ? 'Yesterday' : formatDate(value);
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
    const parsed = new Date(trimmed.replace(' ', 'T') + 'Z');
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  }
  const parsed = new Date(trimmed);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}
