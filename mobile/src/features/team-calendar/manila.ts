const MANILA_OFFSET_MS = 8 * 60 * 60 * 1000;

export interface ManilaParts {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
}

function pad(value: number): string {
  return String(value).padStart(2, '0');
}

function utcDate(parts: ManilaParts): Date {
  // Date.UTC treats years 0..99 as 1900..1999; setUTCFullYear preserves the
  // backend's full supported 0001..9999 range.
  const date = new Date(0);
  date.setUTCFullYear(parts.year, parts.month - 1, parts.day);
  date.setUTCHours(parts.hour, parts.minute, 0, 0);
  return date;
}

function validParts(parts: ManilaParts): boolean {
  const date = utcDate(parts);
  return parts.year >= 1
    && parts.year <= 9999
    && parts.month >= 1
    && parts.month <= 12
    && parts.day >= 1
    && parts.hour >= 0
    && parts.hour <= 23
    && parts.minute >= 0
    && parts.minute <= 59
    && date.getUTCFullYear() === parts.year
    && date.getUTCMonth() === parts.month - 1
    && date.getUTCDate() === parts.day
    && date.getUTCHours() === parts.hour
    && date.getUTCMinutes() === parts.minute;
}

export function parseManilaDate(value: string): ManilaParts | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) { return null; }
  const parts: ManilaParts = {
    year: Number(match[1]),
    month: Number(match[2]),
    day: Number(match[3]),
    hour: 0,
    minute: 0,
  };
  return validParts(parts) ? parts : null;
}

export function parseManilaWall(value: string): ManilaParts | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value);
  if (!match) { return null; }
  const parts: ManilaParts = {
    year: Number(match[1]),
    month: Number(match[2]),
    day: Number(match[3]),
    hour: Number(match[4]),
    minute: Number(match[5]),
  };
  return validParts(parts) ? parts : null;
}

export function formatManilaDate(parts: Pick<ManilaParts, 'year' | 'month' | 'day'>): string {
  return `${String(parts.year).padStart(4, '0')}-${pad(parts.month)}-${pad(parts.day)}`;
}

export function formatManilaWall(parts: ManilaParts): string {
  return `${formatManilaDate(parts)}T${pad(parts.hour)}:${pad(parts.minute)}`;
}

/** Converts an explicit Manila wall value to its UTC instant for the native picker. */
export function manilaWallToPickerDate(value: string): Date {
  const parts = parseManilaWall(value);
  if (!parts) { throw new Error('Invalid Manila wall time.'); }
  return new Date(utcDate(parts).getTime() - MANILA_OFFSET_MS);
}

/** Reads an instant using Manila calendar components, independent of device timezone. */
export function pickerDateToManilaParts(value: Date): ManilaParts {
  const shifted = new Date(value.getTime() + MANILA_OFFSET_MS);
  return {
    year: shifted.getUTCFullYear(),
    month: shifted.getUTCMonth() + 1,
    day: shifted.getUTCDate(),
    hour: shifted.getUTCHours(),
    minute: shifted.getUTCMinutes(),
  };
}

export function mergePickerSelection(
  currentWall: string,
  selectedInstant: Date,
  mode: 'date' | 'time',
): string {
  const current = parseManilaWall(currentWall);
  if (!current) { throw new Error('Invalid Manila wall time.'); }
  const selected = pickerDateToManilaParts(selectedInstant);
  return formatManilaWall(mode === 'date'
    ? { ...current, year: selected.year, month: selected.month, day: selected.day }
    : { ...current, hour: selected.hour, minute: selected.minute });
}

export function instantToManilaWall(value: string): string | null {
  const timestamp = Date.parse(value);
  if (!Number.isFinite(timestamp)) { return null; }
  return formatManilaWall(pickerDateToManilaParts(new Date(timestamp)));
}

export function instantToManilaDate(value: string): string | null {
  const wall = instantToManilaWall(value);
  return wall?.slice(0, 10) ?? null;
}

export function todayInManila(now: Date = new Date()): string {
  return formatManilaDate(pickerDateToManilaParts(now));
}

function dateToUtc(value: string): Date {
  const parts = parseManilaDate(value);
  if (!parts) { throw new Error('Invalid Manila date.'); }
  return utcDate(parts);
}

export function addManilaDays(value: string, days: number): string {
  const date = dateToUtc(value);
  date.setUTCDate(date.getUTCDate() + days);
  return formatManilaDate({
    year: date.getUTCFullYear(),
    month: date.getUTCMonth() + 1,
    day: date.getUTCDate(),
  });
}

export function addManilaMonths(value: string, months: number): string {
  const parts = parseManilaDate(value);
  if (!parts) { throw new Error('Invalid Manila date.'); }
  const target = dateToUtc(formatManilaDate({ year: parts.year, month: parts.month, day: 1 }));
  target.setUTCMonth(target.getUTCMonth() + months);
  const nextMonth = new Date(target.getTime());
  nextMonth.setUTCMonth(nextMonth.getUTCMonth() + 1);
  nextMonth.setUTCDate(0);
  const lastDay = nextMonth.getUTCDate();
  target.setUTCDate(Math.min(parts.day, lastDay));
  return formatManilaDate({
    year: target.getUTCFullYear(),
    month: target.getUTCMonth() + 1,
    day: target.getUTCDate(),
  });
}

export function startOfManilaWeek(value: string): string {
  const date = dateToUtc(value);
  const mondayOffset = (date.getUTCDay() + 6) % 7;
  return addManilaDays(value, -mondayOffset);
}

export function monthGridDates(value: string): string[] {
  const parts = parseManilaDate(value);
  if (!parts) { return []; }
  const first = formatManilaDate({ year: parts.year, month: parts.month, day: 1 });
  const start = startOfManilaWeek(first);
  return Array.from({ length: 42 }, (_, index) => addManilaDays(start, index));
}

export function manilaDayBounds(value: string): { start: number; end: number } {
  const parts = parseManilaDate(value);
  if (!parts) { throw new Error('Invalid Manila date.'); }
  const start = utcDate(parts).getTime() - MANILA_OFFSET_MS;
  return { start, end: start + 24 * 60 * 60 * 1000 };
}

export function compareManilaWalls(left: string, right: string): number {
  const a = parseManilaWall(left);
  const b = parseManilaWall(right);
  if (!a || !b) { return Number.NaN; }
  return left.localeCompare(right);
}

export function defaultEventWalls(now: Date = new Date()): { starts_at: string; ends_at: string } {
  const instant = new Date(now.getTime());
  instant.setUTCMinutes(0, 0, 0);
  instant.setUTCHours(instant.getUTCHours() + 1);
  const start = formatManilaWall(pickerDateToManilaParts(instant));
  const endDate = new Date(instant.getTime() + 60 * 60 * 1000);
  return { starts_at: start, ends_at: formatManilaWall(pickerDateToManilaParts(endDate)) };
}

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const SHORT_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

export function formatManilaDayLabel(value: string, includeYear = true): string {
  const date = dateToUtc(value);
  const label = `${WEEKDAYS[date.getUTCDay()]}, ${MONTHS[date.getUTCMonth()]} ${date.getUTCDate()}`;
  return includeYear ? `${label}, ${date.getUTCFullYear()}` : label;
}

export function formatManilaMonthLabel(value: string): string {
  const parts = parseManilaDate(value);
  return parts ? `${MONTHS[parts.month - 1]} ${parts.year}` : value;
}

export function formatManilaTimeFromInstant(value: string): string {
  const wall = instantToManilaWall(value);
  if (!wall) { return 'Invalid time'; }
  const hour = Number(wall.slice(11, 13));
  const minute = wall.slice(14, 16);
  return `${hour % 12 || 12}:${minute} ${hour < 12 ? 'AM' : 'PM'}`;
}

export function formatCompactDate(value: string): string {
  const parts = parseManilaDate(value);
  return parts ? `${SHORT_MONTHS[parts.month - 1]} ${parts.day}` : value;
}
