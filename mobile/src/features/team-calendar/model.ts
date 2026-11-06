import type {
  TeamCalendarEventSummary,
  TeamCalendarItem,
  TeamCalendarTaskDeadline,
} from '../../api/endpoints/teamCalendar';
import {
  instantToManilaDate,
  manilaDayBounds,
  parseManilaDate,
} from './manila';

export function isCalendarEvent(item: TeamCalendarItem): item is TeamCalendarEventSummary {
  return item.type === 'event';
}

export function isTaskDeadline(item: TeamCalendarItem): item is TeamCalendarTaskDeadline {
  return item.type === 'task_deadline';
}

export function itemOccursOnDate(item: TeamCalendarItem, date: string): boolean {
  if (!parseManilaDate(date)) { return false; }
  if (item.type === 'task_deadline') { return item.due_date === date; }
  const start = Date.parse(item.starts_at);
  const end = Date.parse(item.ends_at);
  if (!Number.isFinite(start) || !Number.isFinite(end)) { return false; }
  const day = manilaDayBounds(date);
  return start < day.end && end > day.start;
}

export function itemsForDate(items: TeamCalendarItem[], date: string): TeamCalendarItem[] {
  return items.filter(item => itemOccursOnDate(item, date)).sort(compareCalendarItems);
}

export function compareCalendarItems(left: TeamCalendarItem, right: TeamCalendarItem): number {
  const leftSort = calendarItemTimestamp(left);
  const rightSort = calendarItemTimestamp(right);
  return leftSort - rightSort || left.id - right.id;
}

function calendarItemTimestamp(item: TeamCalendarItem): number {
  if (item.type === 'event') {
    const timestamp = Date.parse(item.starts_at);
    return Number.isFinite(timestamp) ? timestamp : Number.MAX_SAFE_INTEGER;
  }
  try {
    return manilaDayBounds(item.due_date).start;
  } catch {
    return Number.MAX_SAFE_INTEGER;
  }
}

export interface CalendarSection {
  date: string;
  data: TeamCalendarItem[];
}

/** Agenda assigns a multi-day event once, to its first visible Manila day. */
export function agendaSections(items: TeamCalendarItem[], rangeStart: string): CalendarSection[] {
  const groups = new Map<string, TeamCalendarItem[]>();
  [...items].sort(compareCalendarItems).forEach(item => {
    const actualDate = item.type === 'event' ? instantToManilaDate(item.starts_at) : item.due_date;
    const date = actualDate && actualDate >= rangeStart ? actualDate : rangeStart;
    const group = groups.get(date);
    if (group) { group.push(item); } else { groups.set(date, [item]); }
  });
  return [...groups.entries()]
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([date, data]) => ({ date, data }));
}

export function countItemsByDate(items: TeamCalendarItem[], dates: string[]): Map<string, number> {
  return new Map(dates.map(date => [date, itemsForDate(items, date).length]));
}

export function normalizeParticipantIds(ids: number[]): number[] {
  return [...new Set(ids.filter(id => Number.isSafeInteger(id) && id > 0))].sort((a, b) => a - b).slice(0, 100);
}
